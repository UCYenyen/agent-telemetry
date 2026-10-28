<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class Tmux
{
    /**
     * Session name => pid of the process in its first pane.
     */
    public function panePids(): array
    {
        // tmux prints control characters such as tabs as "_", and never allows ":" in a session name
        $result = $this->run('list-panes', '-a', '-F', '#{session_name}:#{pane_pid}');

        if ($result->failed()) {
            return [];
        }

        $pids = [];

        foreach (array_filter(explode("\n", trim($result->output()))) as $line) {
            $at = strrpos($line, ':');
            $pid = $at === false ? 0 : (int) substr($line, $at + 1);

            if ($pid > 1) {
                $pids[substr($line, 0, $at)] ??= $pid;
            }
        }

        return $pids;
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
