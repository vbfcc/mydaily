<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAccount extends Model
{
    protected $fillable = [
        'chat_id',
        'platform',
        'title',
        'ordered_on',
        'duration_days',
        'ends_on',
        'is_active',
    ];

    protected $casts = [
        'ordered_on' => 'date',
        'ends_on' => 'date',
        'duration_days' => 'integer',
        'is_active' => 'boolean',
    ];
}
