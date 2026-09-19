<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trade extends Model
{
    protected $table = 'trades';
    public $timestamps = false;

    protected $fillable = [
        'symbol', 'side', 'entry_price', 'quantity', 'leverage',
        'stop_loss', 'take_profit', 'status', 'opened_at', 'closed_at', 'pnl',
    ];

    protected $casts = [
        'entry_price'  => 'float',
        'quantity'     => 'float',
        'leverage'     => 'int',
        'stop_loss'    => 'float',
        'take_profit'  => 'float',
        'pnl'          => 'float',
        'opened_at'    => 'datetime',
        'closed_at'    => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}