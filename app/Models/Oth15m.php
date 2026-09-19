<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Oth15m extends Model
{
    protected $table = 'oth_15m';
    public $timestamps = false;

    protected $fillable = ['Nazvanie', 'kf', 'data'];

    protected $casts = [
        'kf'   => 'float',
        'data' => 'datetime',
    ];
}