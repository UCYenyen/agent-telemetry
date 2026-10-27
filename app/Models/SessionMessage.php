<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionMessage extends Model
{
    protected $fillable = ['role', 'content', 'model'];
}
