<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class Fallback extends Model
{
    protected $fillable = ['provider', 'model', 'api_key', 'position'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }

    public static function chain(): Collection
    {
        return static::orderBy('position')->orderBy('id')->get();
    }

    public static function restoreDefaults(): void
    {
        foreach (config('agenthost.fallbacks') as $position => $fallback) {
            static::create([...$fallback, 'position' => $position]);
        }
    }
}
