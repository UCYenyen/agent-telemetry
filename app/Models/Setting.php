<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted:array'];
    }

    public static function read(string $key): array
    {
        return array_replace(config("agenthost.{$key}", []), static::find($key)?->value ?? []);
    }

    public static function merge(string $key, array $values): array
    {
        $merged = array_replace(static::read($key), $values);

        static::updateOrCreate(['key' => $key], ['value' => $merged]);

        return $merged;
    }
}
