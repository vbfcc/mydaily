<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotSubscriber extends Model
{
    protected $fillable = [
        'chat_id',
        'platform',
        'username',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];
}
