<?php

namespace App\Services;

use App\Models\Fallback;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\RuntimeException;

class LlmCascade
{
    public function __construct(private OpenRouter $openRouter) {}

    public function ask(string $prompt, string $model, bool $useOpenRouter, int $timeout = 90): array
    {
        $messages = [['role' => 'user', 'content' => $prompt]];

        if ($useOpenRouter || str_contains($model, '/')) {
            $chain = $this->openRouter->chain();
            $target = str_contains($model, '/') ? $model : ($chain[0] ?? 'anthropic/claude-3.7-sonnet');
            [, $text, $used] = $this->openRouter->chat($this->openRouter->keyFor($target), $target, $messages, $chain, $timeout);

            return [$text, "openrouter // {$used}"];
        }

        $resolved = $this->resolve($model);

        if (($output = $this->runCli($resolved, $prompt, $timeout)) !== null) {
            return [$output, $resolved];
        }

        $fallbacks = Fallback::chain()->values();

        foreach ($fallbacks as $index => $fallback) {
            $rank = $index + 1;

            if ($fallback->provider === 'openrouter' || str_contains($fallback->model, '/')) {
                $key = $this->openRouter->keyFor($fallback->model);

                if ($key === '') {
                    continue;
                }

                [$ok, $text, $used] = $this->openRouter->chat($key, $fallback->model, $messages, [], $timeout);

                if ($ok) {
                    return [$text, "fallback #{$rank} (openrouter) // {$used}"];
                }

                continue;
            }

            $fallbackModel = $this->resolve($fallback->model);

            if ($fallbackModel !== $resolved && ($output = $this->runCli($fallbackModel, $prompt, $timeout)) !== null) {
                return [$output, "fallback #{$rank} ({$fallback->provider}) // {$fallbackModel}"];
            }
        }

        return ["[Error: Model {$resolved} and all {$fallbacks->count()} fallbacks unavailable]", $resolved];
    }

    private function resolve(string $model): string
    {
        return $model === '' ? 'gemini-3.8-flash-medium' : (config('agenthost.model_aliases')[$model] ?? $model);
    }

    private function runCli(string $model, string $prompt, int $timeout): ?string
    {
        try {
            $result = Process::timeout($timeout)->run([config('agenthost.agy_bin'), '--disable-slash-commands', '--model', $model, '-p', $prompt]);
        } catch (RuntimeException $e) {
            Log::warning("[cascade] {$model}: {$e->getMessage()}");

            return null;
        }

        $output = trim($result->output());

        return $result->successful() && $output !== '' ? $output : null;
    }
}
