<?php

namespace App\Console\Commands;

use App\Services\AgentSessionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Throwable;

class MonitorAgents extends Command
{
    protected $signature = 'agents:monitor {--once : Run a single sync pass and exit}';

    protected $description = 'Discover tmux and CLI agent sessions, refresh their states and run cascading failover';

    public function handle(AgentSessionManager $manager): int
    {
        while (true) {
            try {
                $manager->syncDiscovered();
                $manager->updateStates();
            } catch (Throwable $e) {
                $this->error("[monitor] Error updating states: {$e->getMessage()}");
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            Sleep::for(3)->seconds();
        }
    }
}
