<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Oth1h extends Model
{
    protected $table = 'oth_1h';
    public $timestamps = false;

    protected $fillable = ['Nazvanie', 'kf', 'data'];

    protected $casts = [
        'kf'   => 'float',
        'data' => 'datetime',
    ];
}