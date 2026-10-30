<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConcordParticipant extends Model
{
    use HasUuids;

    protected $fillable = [
        'concord_room_id',
        'kind',
        'name',
        'user_name',
        'account_email',
        'device_name',
        'execution_mode',
        'provider',
        'model',
        'avatar',
        'color',
        'role_prompt',
        'use_openrouter',
        'status',
        'current_thought',
        'current_action',
        'thinking_steps',
        'claimed_files',
        'local_modified_files',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'use_openrouter' => 'boolean',
            'thinking_steps' => 'array',
            'claimed_files' => 'array',
            'local_modified_files' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ConcordRoom::class, 'concord_room_id');
    }
}
