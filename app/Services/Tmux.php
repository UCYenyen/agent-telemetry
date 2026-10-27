<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class Tmux
{
    public function sessions(): array
    {
        $result = $this->run('list-sessions', '-F', '#{session_name}');

        return $result->successful() ? array_values(array_filter(array_map('trim', explode("\n", $result->output())))) : [];
    }

    public function has(string $name): bool
    {
        return $this->run('has-session', '-t', $name)->successful();
    }

    public function create(string $name, string $command, string $cwd): bool
    {
        if ($this->has($name) || $this->run('new-session', '-d', '-s', $name, '-n', $name, '-c', $cwd, 'bash')->failed()) {
            return false;
        }

        return $this->send($name, $command);
    }

    public function send(string $name, string $text, bool $enter = true): bool
    {
        if ($this->run('send-keys', '-t', $name, '-l', $text)->failed()) {
            return false;
        }

        return ! $enter || $this->run('send-keys', '-t', $name, 'Enter')->successful();
    }

    public function capture(string $name, int $lines = 150): string
    {
        $result = $this->run('capture-pane', '-p', '-t', $name, '-S', "-{$lines}");

        return $result->successful() ? $result->output() : '';
    }

    public function kill(string $name): bool
    {
        return ! $this->has($name) || $this->run('kill-session', '-t', $name)->successful();
    }

    private function run(string ...$args): ProcessResult
    {
        return Process::run(['tmux', ...$args]);
    }
}
