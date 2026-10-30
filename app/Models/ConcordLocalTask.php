<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConcordLocalTask extends Model
{
    use HasUuids;

    protected $fillable = [
        'concord_room_id',
        'target_participant_id',
        'target_agent_name',
        'target_device',
        'from_sender',
        'kind',
        'instructions',
        'context_memory_snapshot',
        'file_patches',
        'status',
        'modified_files',
        'result_summary',
    ];

    protected function casts(): array
    {
        return [
            'context_memory_snapshot' => 'array',
            'file_patches' => 'array',
            'modified_files' => 'array',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ConcordRoom::class, 'concord_room_id');
    }
}
