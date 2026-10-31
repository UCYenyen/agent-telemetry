<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ConcordRoom extends Model
{
    use HasUuids;

    protected $fillable = [
        'title',
        'description',
        'owner_email',
        'access_mode',
        'share_token',
        'mcp_token',
        'status',
        'status_detail',
        'shared_memory',
        'active_claims',
        'agent_session_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $room) {
            $room->share_token ??= 'sh_'.Str::random(28);
            $room->mcp_token ??= 'mcp_'.Str::random(28);
            $room->shared_memory ??= [];
            $room->active_claims ??= [];
        });
    }

    protected function casts(): array
    {
        return [
            'shared_memory' => 'array',
            'active_claims' => 'array',
        ];
    }

    public function invites(): HasMany
    {
        return $this->hasMany(ConcordRoomInvite::class);
    }

    public function agentSession(): BelongsTo
    {
        return $this->belongsTo(AgentSession::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConcordParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConcordMessage::class);
    }

    public function localTasks(): HasMany
    {
        return $this->hasMany(ConcordLocalTask::class);
    }
}
