<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    protected $fillable = [
        'chat_id',
        'platform',
        'title',
        'body',
    ];
}
