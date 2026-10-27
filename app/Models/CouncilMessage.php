<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouncilMessage extends Model
{
    protected $fillable = ['type', 'sender', 'icon', 'color', 'model', 'anon_legend', 'content', 'elapsed_sec'];

    protected function casts(): array
    {
        return ['elapsed_sec' => 'float'];
    }
}
