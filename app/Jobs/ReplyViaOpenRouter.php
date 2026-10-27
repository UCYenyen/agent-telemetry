<?php

namespace App\Jobs;

use App\Models\AgentSession;
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
            ->map(fn ($message) => ['role' => $message->role, 'content' => $message->content])
            ->values()
            ->all();

        if (! $history || end($history)['content'] !== $this->prompt) {
            $history[] = ['role' => 'user', 'content' => $this->prompt];
        }

        $model = $this->session->model;
        [, $reply, $usedModel] = $openRouter->chat($openRouter->keyFor($model), $model, $history, $openRouter->chain());

        $this->session->update(['model' => $usedModel, 'state' => 'idle']);
        $this->session->messages()->create(['role' => 'assistant', 'content' => $reply, 'model' => "openrouter // {$usedModel}"]);
    }

    public function failed(): void
    {
        $this->session->update(['state' => 'idle']);
    }
}
