<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Routine extends Model
{
    protected $fillable = [
        'chat_id',
        'platform',
        'title',
        'starts_on',
        'ends_on',
        'is_active',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(RoutineLog::class);
    }

    /** روتین برای یک تاریخ خاص فعال است؟ (فلگ + بازه‌ی شروع/پایان) */
    public function isActiveOn(string $dateYmd): bool
    {
        return $this->is_active
            && $this->starts_on->format('Y-m-d') <= $dateYmd
            && $dateYmd <= $this->ends_on->format('Y-m-d');
    }
}
