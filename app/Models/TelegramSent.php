<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramSent extends Model
{
    protected $table = 'telegram_sent';
    public $timestamps = false;

    protected $fillable = ['hash', 'sent_at'];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public static function alreadySent(string $hash): bool
    {
        return static::where('hash', $hash)->exists();
    }

    public static function markSent(string $hash): void
    {
        static::create([
            'hash'    => $hash,
            'sent_at' => now('UTC'),
        ]);
    }
}