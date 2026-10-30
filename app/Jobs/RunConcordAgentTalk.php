<?php

namespace App\Jobs;

use App\Models\ConcordRoom;
use App\Services\ConcordHubService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunConcordAgentTalk implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(
        public ConcordRoom $room,
        public string $prompt,
        public string $senderName = 'Operator',
        public ?string $targetParticipantId = null,
        public bool $autoHandoff = true,
    ) {}

    public function handle(ConcordHubService $hub): void
    {
        $hub->executeMultiAgentTurn(
            $this->room,
            $this->prompt,
            $this->senderName,
            $this->targetParticipantId,
            $this->autoHandoff,
        );
    }
}
