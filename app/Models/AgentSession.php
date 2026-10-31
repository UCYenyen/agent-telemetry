<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentSession extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'title',
        'agent',
        'model',
        'use_openrouter',
        'remote_url',
        'state',
        'fallback_enabled',
        'fallback_triggered',
        'fallback_stage',
        'last_prompt',
    ];

    protected $attributes = [
        'state' => 'idle',
        'fallback_enabled' => true,
        'fallback_triggered' => false,
        'fallback_stage' => 0,
        'use_openrouter' => false,
    ];

    protected function casts(): array
    {
        return [
            'use_openrouter' => 'boolean',
            'fallback_enabled' => 'boolean',
            'fallback_triggered' => 'boolean',
            'fallback_stage' => 'integer',
        ];
    }

    public function room(): HasOne
    {
        return $this->hasOne(ConcordRoom::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SessionMessage::class);
    }
}
