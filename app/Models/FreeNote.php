<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreeNote extends Model
{
    protected $fillable = [
        'chat_id',
        'platform',
        'body',
    ];
}
