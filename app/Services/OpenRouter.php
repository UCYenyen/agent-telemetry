<?php

namespace App\Services;

use App\Models\Fallback;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OpenRouter
{
    private const CHAT_URL = 'https://openrouter.ai/api/v1/chat/completions';

    private const MODELS_URL = 'https://openrouter.ai/api/v1/models';

    public function chat(string $apiKey, string $model, array $messages, array $fallbackModels = [], int $timeout = 90): array
    {
        if (trim($apiKey) === '') {
            return [false, '[OpenRouter Error: No OpenRouter API Key configured. Paste your key in Settings.]', $model];
        }

        $candidates = array_values(array_unique(array_filter([$model, ...$fallbackModels]))) ?: ['openai/gpt-4o-mini'];
        $lastError = '';

        foreach ($candidates as $candidate) {
            try {
                $response = Http::withToken(trim($apiKey))
                    ->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => 'Agent Host Command & LLM Council'])
                    ->timeout($timeout)
                    ->post(self::CHAT_URL, ['model' => $candidate, 'messages' => $messages]);
            } catch (ConnectionException $e) {
                $lastError = "Error on {$candidate}: {$e->getMessage()}";

                continue;
            }

            if ($response->failed()) {
                $lastError = "HTTP {$response->status()} on {$candidate}: ".Str::limit($response->body(), 240, '');

                continue;
            }

            $content = trim((string) $response->json('choices.0.message.content'));

            if ($content !== '') {
                return [true, $content, $response->json('model') ?: $candidate];
            }

            $lastError = "Empty response from {$candidate}";
        }

        return [false, "[OpenRouter Failed: {$lastError}]", end($candidates)];
    }

    public function models(string $apiKey = ''): array
    {
        $defaults = config('agenthost.models.openrouter');
        $request = Http::timeout(15);

        if ($apiKey !== '') {
            $request->withToken($apiKey);
        }

        try {
            $ids = array_filter((array) $request->get(self::MODELS_URL)->json('data.*.id'));
        } catch (ConnectionException) {
            return $defaults;
        }

        if (! $ids) {
            return $defaults;
        }

        $prioritized = array_values(array_intersect($defaults, $ids));

        return [...$prioritized, ...array_slice(array_values(array_diff($ids, $prioritized)), 0, 150)];
    }

    public function keyFor(string $model = ''): string
    {
        $fallbacks = Fallback::chain();
        $own = $fallbacks->first(fn (Fallback $fallback) => $fallback->model === $model && filled($fallback->api_key));
        $any = $fallbacks->first(fn (Fallback $fallback) => filled($fallback->api_key));

        return trim($own?->api_key ?? '')
            ?: trim(Setting::read('openrouter')['api_key'] ?? '')
            ?: trim($any?->api_key ?? '');
    }

    public function chain(): array
    {
        $defaults = Setting::read('defaults');
        $settings = Setting::read('openrouter');

        return Fallback::chain()
            ->where('provider', 'openrouter')
            ->pluck('model')
            ->push($defaults['openrouter_model'] ?? null, $settings['default_model'] ?? null, ...$settings['fallback_models'] ?? [])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public static function mask(string $key, string $dots = '••••'): string
    {
        if (strlen($key) > 14) {
            return substr($key, 0, 9).$dots.substr($key, -4);
        }

        return $key === '' ? '' : '••••';
    }
}
