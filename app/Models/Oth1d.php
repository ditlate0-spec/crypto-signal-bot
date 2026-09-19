<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Oth1d extends Model
{
    protected $table = 'oth_1d';
    public $timestamps = false;

    protected $fillable = ['Nazvanie', 'kf', 'data'];

    protected $casts = [
        'kf'   => 'float',
        'data' => 'datetime',
    ];
}