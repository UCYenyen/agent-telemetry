<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Advisor extends Model
{
    protected $fillable = ['name', 'icon', 'color', 'model', 'use_openrouter', 'enabled', 'prompt'];

    protected function casts(): array
    {
        return [
            'use_openrouter' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    public static function restoreDefaults(): void
    {
        static::query()->delete();

        foreach (config('agenthost.advisors') as $advisor) {
            static::create($advisor);
        }
    }
}
