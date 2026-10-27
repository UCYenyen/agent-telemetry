<?php

namespace App\Jobs;

use App\Models\CouncilRoom;
use App\Services\CouncilService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunCouncilDeliberation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public CouncilRoom $room, public string $question, public array $advisors, public bool $peerReview) {}

    public function handle(CouncilService $council): void
    {
        $council->deliberate($this->room, $this->question, $this->advisors, $this->peerReview);
    }

    public function failed(?Throwable $exception): void
    {
        $this->room->patch([
            'status' => 'interrupted',
            'status_detail' => 'Deliberation was interrupted before the verdict. Ask again to reconvene.',
        ]);
    }
}
