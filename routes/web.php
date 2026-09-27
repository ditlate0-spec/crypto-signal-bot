<?php

use App\Http\Controllers\DashboardController;
use App\Models\AiTradeJournal;
use App\Models\Trade;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::post('/notify/position-closed', function (Request $request) {
    $TOKEN   = "5608379544:AAHU2hFHcCVbQKD8RJS6HWunN_IeGCDcUmc";
    $CHAT_ID = 1745395495;

    $data = $request->json()->all();

    $symbol    = $data['symbol'] ?? 'BTCUSDT';
    $exitPrice = (float)($data['exit_price'] ?? 0);
    $pnlFromRust = (float)($data['pnl'] ?? 0);
    $reason    = $data['reason'] ?? 'unknown';

    // ============================================
    // 1. ОБНОВЛЕНИЕ AI-ЖУРНАЛА (НОВОЕ)
    // ============================================
    $journal = AiTradeJournal::where('symbol', $symbol)
        ->whereNull('exit_time')
        ->orderByDesc('id')
        ->first();

    $netPnl = null;
    $pnlPct = null;
    $fees   = null;
    $isWin  = null;
    $duration = null;
    $exitReason = 'UNKNOWN';

    if ($journal) {
        $entryPrice = (float)$journal->entry_price;
        $qty        = (float)$journal->quantity;
        $side       = $journal->side;

        // PnL считаем сами
        if ($side === 'SHORT') {
            $pnl = ($entryPrice - $exitPrice) * $qty;
        } else {
            $pnl = ($exitPrice - $entryPrice) * $qty;
        }

        // Комиссии Binance Futures: 0.04% taker × 2
        $fees = ($entryPrice + $exitPrice) * $qty * 0.0004;
        $netPnl = $pnl - $fees;

        // PnL в % от маржи
        $margin = $qty > 0 ? ($entryPrice * $qty) / max(1, $journal->leverage) : 0;
        $pnlPct = $margin > 0 ? ($netPnl / $margin) * 100 : 0;

        // Причина выхода
        $exitReason = match($reason) {
            'STOP_MARKET'          => 'SL',
            'TRAILING_STOP_MARKET' => 'TRAILING_SL',
            'TAKE_PROFIT_MARKET'   => 'TP',
            default                => 'UNKNOWN',
        };

        // Уточнение по цене
        if ($journal->sl_price > 0
            && abs($exitPrice - $journal->sl_price) / $journal->sl_price < 0.001) {
            $exitReason = 'SL';
        }
        if ($netPnl > 0 && $exitReason === 'SL') {
            $exitReason = 'TRAILING_SL';
        }

        // Длительность
        $duration = $journal->entry_time
            ? now()->diffInSeconds($journal->entry_time)
            : null;

        // Обновляем ai_trade_journal
        $journal->update([
            'exit_price'       => $exitPrice,
            'exit_time'        => now(),
            'exit_reason'      => $exitReason,
            'duration_seconds' => $duration,
            'pnl'              => $pnl,
            'pnl_pct'          => round($pnlPct, 4),
            'fees'             => $fees,
            'is_win'           => $netPnl > 0,
        ]);

        // Обновляем trades
        if ($journal->trade_id) {
            Trade::where('id', $journal->trade_id)->update([
                'status'    => 'closed',
                'closed_at' => now(),
                'pnl'       => $netPnl,
            ]);
        }

        Log::info('[notify] journal updated', [
            'journal_id'  => $journal->id,
            'exit_reason' => $exitReason,
            'net_pnl'     => $netPnl,
        ]);
    }

    // ============================================
    // 2. TELEGRAM (КАК БЫЛО — НЕ ТРОГАЕМ)
    // ============================================
    $reasonText = match($reason) {
        'STOP_MARKET'           => 'Стоп-лосс',
        'TRAILING_STOP_MARKET'  => 'Трейлинг-стоп',
        'TAKE_PROFIT_MARKET'    => 'Тейк-профит',
        default                 => $reason,
    };

    $pnlFormatted = number_format((float)$pnlFromRust, 2);
    $pnlSign = (float)$pnlFromRust >= 0 ? '🟢' : '🔴';

    $msg  = "🔔 <b>ПОЗИЦИЯ ЗАКРЫТА</b>\n";
    $msg .= "Символ: {$symbol}\n";
    $msg .= "Причина: {$reasonText}\n";
    $msg .= "Цена выхода: \${$exitPrice}\n";
    $msg .= "{$pnlSign} PnL: \${$pnlFormatted}";

    $url = "https://api.telegram.org/bot$TOKEN/sendMessage";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'chat_id'    => $CHAT_ID,
        'text'       => $msg,
        'parse_mode' => 'HTML'
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);

    return response()->json([
        'status'      => 'ok',
        'journal_id'  => $journal->id ?? null,
        'exit_reason' => $exitReason,
        'net_pnl'     => $netPnl,
    ]);
});