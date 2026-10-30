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
        'current_prompt',
        'current_prompt_at',
        'activity',
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
            'current_prompt_at' => 'datetime',
            'activity' => 'array',
        ];
    }

    private const ACTIVITY_LIMIT = 30;

    /**
     * Record what this agent is doing: a new prompt, a tool call, or finishing a turn.
     * Keeps a short rolling log for the room's agent board.
     */
    public function recordActivity(string $type, string $text, ?string $prompt = null): void
    {
        $now = now();
        $text = mb_substr(trim($text), 0, 300);
        $updates = ['last_seen_at' => $now, 'execution_mode' => 'mcp_connected'];

        if ($prompt !== null && trim($prompt) !== '') {
            $updates['current_prompt'] = mb_substr(trim($prompt), 0, 4000);
            $updates['current_prompt_at'] = $now;
        }

        $updates['status'] = $type === 'done' ? 'idle' : 'working';
        if ($text !== '') {
            $updates['current_action'] = $text;
        }

        $log = $this->activity ?? [];
        if ($text !== '') {
            $log[] = ['at' => $now->toIso8601String(), 'type' => $type, 'text' => $text];
        }
        $updates['activity'] = array_slice($log, -self::ACTIVITY_LIMIT);

        $this->update($updates);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ConcordRoom::class, 'concord_room_id');
    }
}
