<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutineLog extends Model
{
    protected $fillable = [
        'routine_id',
        'chat_id',
        'platform',
        'entry_date',
        'done',
        'note',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'done' => 'boolean',
    ];

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }
}
