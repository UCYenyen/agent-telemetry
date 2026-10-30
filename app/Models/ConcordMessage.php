<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConcordMessage extends Model
{
    protected $fillable = [
        'concord_room_id',
        'participant_id',
        'sender_name',
        'sender_user',
        'sender_device',
        'sender_kind',
        'sender_icon',
        'sender_color',
        'model',
        'kind',
        'target_agent',
        'reply_to_id',
        'thinking_text',
        'tool_calls',
        'local_changes',
        'content',
        'elapsed_sec',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'local_changes' => 'array',
            'elapsed_sec' => 'float',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ConcordRoom::class, 'concord_room_id');
    }
}
