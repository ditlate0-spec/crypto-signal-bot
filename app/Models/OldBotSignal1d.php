<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OldBotSignal1d extends Model
{
    protected $table = 'old_bot_signals_1d';
    public $timestamps = false;

    protected $fillable = ['symbol', 'kf', 'text', 'created_at'];

    protected $casts = [
        'kf'         => 'float',
        'created_at' => 'datetime',
    ];
}