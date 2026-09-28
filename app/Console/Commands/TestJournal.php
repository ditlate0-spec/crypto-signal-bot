<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AiTradeJournal;
use Illuminate\Support\Facades\Log;

class TestJournal extends Command
{
    protected $signature   = 'test:journal';
    protected $description = 'Test ai_trade_journal insert without opening trade';

    public function handle()
    {
        try {
            $journal = AiTradeJournal::create([
                'trade_id'    => null,
                'symbol'      => 'BTCUSDT',
                'side'        => 'SHORT',
                'entry_price' => 99999,
                'entry_time'  => now('UTC'),
                'quantity'    => 0.001,
                'leverage'    => 50,
                'sl_price'    => 100500,
                'tp_price'    => 99800,

                'kf_btc_1d'   => 25,
                'kf_btc_1h'   => 15,
                'kf_btc_15m'  => 35,
                'kf_eth_1d'   => 22,
                'kf_eth_1h'   => 12,
                'kf_eth_15m'  => 33,
                'old_btc_1d'  => 55,
                'old_btc_1h'  => 42,
                'old_btc_15m' => 58,
                'old_eth_1d'  => 52,
                'old_eth_1h'  => 41,
                'old_eth_15m' => 72,

                'ml_probability'   => 0.87,
                'ml_threshold'     => 0.85,
                'ml_features'      => ['test' => 1],

                'fear_greed_index' => 62,
                'fear_greed_label' => 'Greed',

                'news_positive'  => 3,
                'news_negative'  => 1,
                'news_neutral'   => 1,
                'news_headlines' => ['test headline'],
                'news_sentiment' => 'positive',

                'verdict_text' => 'ТЕСТ: проверка записи',
            ]);

            $this->info('✅ Запись создана. ID = ' . $journal->id);
        } catch (\Throwable $e) {
            $this->error('❌ Ошибка: ' . $e->getMessage());
            Log::error('[test:journal] ' . $e->getMessage());
        }

        return self::SUCCESS;
    }
}