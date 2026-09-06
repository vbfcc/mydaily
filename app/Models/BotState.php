<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotState extends Model
{
    protected $fillable = [
        'chat_id',
        'platform',
        'state',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];
}
