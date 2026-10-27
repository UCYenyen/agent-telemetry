<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class CouncilRoom extends Model
{
    use HasUuids;

    protected $fillable = ['title', 'status', 'status_detail', 'progress'];

    protected function casts(): array
    {
        return ['progress' => 'array'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CouncilMessage::class);
    }

    public function patch(array $attributes, array $progress = []): void
    {
        DB::transaction(function () use ($attributes, $progress) {
            $room = static::lockForUpdate()->findOrFail($this->getKey());
            $room->fill($attributes);
            $room->progress = array_replace_recursive($room->progress ?? [], $progress);
            $room->save();
        });
    }
}
