<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class HashPassword extends Command
{
    protected $signature = 'agenthost:hash-password';

    protected $description = 'Hash a login password for AGENT_HOST_PASSWORD_HASH';

    public function handle(): int
    {
        $password = (string) $this->secret('Password');

        if (strlen($password) < 12) {
            $this->error('Use at least 12 characters.');

            return self::FAILURE;
        }

        $this->line("AGENT_HOST_PASSWORD_HASH='".Hash::make($password)."'");

        return self::SUCCESS;
    }
}
