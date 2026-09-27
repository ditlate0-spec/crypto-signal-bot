<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_trade_journal', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('trade_id')->nullable();
            $table->index('trade_id');

            // Сделка
            $table->string('symbol', 20);
            $table->enum('side', ['LONG', 'SHORT']);
            $table->decimal('entry_price', 20, 8);
            $table->dateTime('entry_time');
            $table->decimal('quantity', 20, 8);
            $table->integer('leverage');
            $table->decimal('sl_price', 20, 8)->nullable();
            $table->decimal('tp_price', 20, 8)->nullable();

            // KF-бот №1 (Oth)
            $table->decimal('kf_btc_1d', 10, 4)->nullable();
            $table->decimal('kf_btc_1h', 10, 4)->nullable();
            $table->decimal('kf_btc_15m', 10, 4)->nullable();
            $table->decimal('kf_eth_1d', 10, 4)->nullable();
            $table->decimal('kf_eth_1h', 10, 4)->nullable();
            $table->decimal('kf_eth_15m', 10, 4)->nullable();

            // KF-бот №2 (Old)
            $table->decimal('old_btc_1d', 10, 4)->nullable();
            $table->decimal('old_btc_1h', 10, 4)->nullable();
            $table->decimal('old_btc_15m', 10, 4)->nullable();
            $table->decimal('old_eth_1d', 10, 4)->nullable();
            $table->decimal('old_eth_1h', 10, 4)->nullable();
            $table->decimal('old_eth_15m', 10, 4)->nullable();

            // ML
            $table->decimal('ml_probability', 5, 4)->nullable();
            $table->decimal('ml_threshold', 5, 4)->nullable();
            $table->json('ml_features')->nullable();

            // Fear & Greed
            $table->integer('fear_greed_index')->nullable();
            $table->string('fear_greed_label', 50)->nullable();

            // Новости
            $table->integer('news_positive')->default(0);
            $table->integer('news_negative')->default(0);
            $table->integer('news_neutral')->default(0);
            $table->json('news_headlines')->nullable();
            $table->enum('news_sentiment', ['positive', 'negative', 'neutral'])->nullable();

            // Вердикт
            $table->text('verdict_text')->nullable();

            // Данные выхода
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->dateTime('exit_time')->nullable();
            $table->enum('exit_reason', [
                'TP', 'SL', 'TRAILING_SL', 'MANUAL', 'LIQUIDATION', 'UNKNOWN',
            ])->nullable();
            $table->integer('duration_seconds')->nullable();

            // Результат
            $table->decimal('pnl', 20, 8)->nullable();
            $table->decimal('pnl_pct', 8, 4)->nullable();
            $table->decimal('fees', 20, 8)->nullable();
            $table->boolean('is_win')->nullable();

            $table->timestamps();

            // Индексы
            $table->index(['symbol', 'entry_time']);
            $table->index('entry_time');
            $table->index('exit_time');
            $table->index('exit_reason');
            $table->index('is_win');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_trade_journal');
    }
};