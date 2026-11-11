<?php

namespace App\Jobs;

use App\Models\AgentSession;
use App\Models\SessionMessage;
use App\Services\OpenRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReplyViaOpenRouter implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public AgentSession $session, public string $prompt) {}

    public function handle(OpenRouter $openRouter): void
    {
        $history = $this->session->messages()
            ->latest('id')
            ->limit(16)
            ->get()
            ->reverse()
            ->map(fn ($message) => ['role' => $message->role, 'content' => $this->content($message)])
            ->values()
            ->all();

        // A message with images is already the prompt, as content parts
        if (! $history || (is_string(end($history)['content']) && end($history)['content'] !== $this->prompt)) {
            $history[] = ['role' => 'user', 'content' => $this->prompt];
        }

        $model = $this->session->model;
        [, $reply, $usedModel] = $openRouter->chat($openRouter->keyFor($model), $model, $history, $openRouter->chain());

        $this->session->update(['model' => $usedModel, 'state' => 'idle']);
        $this->session->messages()->create(['role' => 'assistant', 'content' => $reply, 'model' => "openrouter // {$usedModel}"]);
    }

    /**
     * Plain text, or OpenAI-style content parts when the message carries images.
     */
    private function content(SessionMessage $message): string|array
    {
        $images = array_filter($message->attachments ?? [], 'is_file');

        if (! $images) {
            return $message->content;
        }

        return [
            ['type' => 'text', 'text' => $message->content],
            ...array_map(fn ($path) => [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:'.mime_content_type($path).';base64,'.base64_encode(file_get_contents($path))],
            ], array_values($images)),
        ];
    }

    public function failed(): void
    {
        $this->session->update(['state' => 'idle']);
    }
}
