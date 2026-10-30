<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ConcordRoomInvite extends Model
{
    protected $fillable = [
        'concord_room_id',
        'email',
        'name',
        'role',
        'invite_token',
        'accepted_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $invite) {
            $invite->invite_token ??= 'inv_'.Str::random(28);
        });
    }

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ConcordRoom::class, 'concord_room_id');
    }
}
