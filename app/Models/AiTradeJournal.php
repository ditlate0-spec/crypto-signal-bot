<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiTradeJournal extends Model
{
    protected $table = 'ai_trade_journal';

    protected $fillable = [
        'trade_id', 'symbol', 'side', 'entry_price', 'entry_time',
        'quantity', 'leverage', 'sl_price', 'tp_price',

        'kf_btc_1d', 'kf_btc_1h', 'kf_btc_15m',
        'kf_eth_1d', 'kf_eth_1h', 'kf_eth_15m',

        'old_btc_1d', 'old_btc_1h', 'old_btc_15m',
        'old_eth_1d', 'old_eth_1h', 'old_eth_15m',

        'ml_probability', 'ml_threshold', 'ml_features',

        'fear_greed_index', 'fear_greed_label',

        'news_positive', 'news_negative', 'news_neutral',
        'news_headlines', 'news_sentiment',

        'verdict_text',

        'exit_price', 'exit_time', 'exit_reason', 'duration_seconds',
        'pnl', 'pnl_pct', 'fees', 'is_win',
    ];

    protected $casts = [
        'entry_price'      => 'float',
        'quantity'         => 'float',
        'leverage'         => 'int',
        'sl_price'         => 'float',
        'tp_price'         => 'float',
        'ml_probability'   => 'float',
        'ml_threshold'     => 'float',
        'ml_features'      => 'array',
        'news_headlines'   => 'array',
        'fear_greed_index' => 'int',
        'exit_price'       => 'float',
        'pnl'              => 'float',
        'pnl_pct'          => 'float',
        'fees'             => 'float',
        'is_win'           => 'bool',
        'entry_time'       => 'datetime',
        'exit_time'        => 'datetime',
    ];
}