<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyEntry extends Model
{
    protected $fillable = [
        'chat_id',
        'platform',
        'entry_date',
        'sleep_time',
        'wake_time',
        'work_hours',
        'gym',
        'gaming_minutes',
        'social',
        'mood',
        'emotional_trigger',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'gym' => 'boolean',
        'social' => 'boolean',
        'work_hours' => 'decimal:1',
        'mood' => 'integer',
        'gaming_minutes' => 'integer',
    ];
}
