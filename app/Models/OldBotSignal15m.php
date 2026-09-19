<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OldBotSignal15m extends Model
{
    protected $table = 'old_bot_signals_15m';
    public $timestamps = false;

    protected $fillable = ['symbol', 'kf', 'text', 'created_at'];

    protected $casts = [
        'kf'         => 'float',
        'created_at' => 'datetime',
    ];
}