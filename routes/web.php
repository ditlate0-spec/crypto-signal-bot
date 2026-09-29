<?php

use App\Http\Controllers\DashboardController;
use App\Models\AiTradeJournal;
use App\Models\Trade;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/notify/position-closed', function (Request $request) {
    \Log::info('[notify] webhook received', ['body' => $request->json()->all()]);

    $TOKEN   = env('TELEGRAM_TOKEN');
    $CHAT_ID = env('TELEGRAM_CHAT_ID');

    $data = $request->json()->all();

    $symbol      = $data['symbol'] ?? 'BTCUSDT';
    $exitPrice   = (float)($data['exit_price'] ?? 0);
    $pnlFromRust = (float)($data['pnl'] ?? 0);
    $reason      = $data['reason'] ?? 'unknown';

    // 1. Ищем РЕАЛЬНУЮ открытую сделку (самую старую)
    $trade = Trade::where('symbol', $symbol)
        ->where('status', 'open')
        ->orderBy('opened_at', 'asc')
        ->first();

    $journal = null;
    $exitReason = 'UNKNOWN';
    $netPnl = null;

    if ($trade) {
        // 2. Ищем журнал по trade_id
        $journal = AiTradeJournal::where('trade_id', $trade->id)->first();

        // Fallback: если не нашли, ищем по времени
        if (!$journal) {
            $journal = AiTradeJournal::where('symbol', $symbol)
                ->whereNull('exit_time')
                ->where('entry_time', '>=', $trade->opened_at)
                ->orderBy('id', 'asc')
                ->first();
        }

        if ($journal) {
            $entryPrice = (float)$journal->entry_price;

            // 3. PnL берём ИЗ RUST (Binance уже посчитал)
            $netPnl = $pnlFromRust;

$exitReason = match($reason) {
    'STOP_MARKET'          => 'SL',
    'TRAILING_STOP_MARKET' => 'TRAILING_SL',
    'TAKE_PROFIT_MARKET'   => 'TP',
    'MARKET'               => 'TRAILING_SL',   // ← Binance часто шлёт MARKET для трейлинга
    default                => 'UNKNOWN',
};

// Уточнение по цене, если reason всё ещё UNKNOWN
if ($exitReason === 'UNKNOWN' && $entryPrice > 0 && $exitPrice > 0) {
    if ($exitPrice > $entryPrice) {
        $exitReason = 'SL';               // шорт закрылся выше входа = убыток
    } elseif ($exitPrice < $entryPrice) {
        $exitReason = 'TRAILING_SL';      // шорт закрылся ниже входа = прибыль
    }
}
            $journal->update([
                'exit_price'       => $exitPrice,
                'exit_time'        => now(),
                'exit_reason'      => $exitReason,
                'duration_seconds' => $journal->entry_time
                    ? now()->diffInSeconds($journal->entry_time)
                    : null,
                'pnl'              => $netPnl,
                'pnl_pct'          => $entryPrice > 0
                    ? round((($entryPrice - $exitPrice) / $entryPrice) * 100, 4)
                    : null,
                'is_win'           => $netPnl > 0,
            ]);

            $trade->update([
                'status'    => 'closed',
                'closed_at' => now(),
                'pnl'       => $netPnl,
            ]);

            Log::info('[notify] journal updated', [
                'journal_id'  => $journal->id,
                'trade_id'    => $trade->id,
                'exit_reason' => $exitReason,
                'net_pnl'     => $netPnl,
            ]);
        } else {
            Log::warning('[notify] journal not found for trade', [
                'trade_id' => $trade->id,
            ]);
        }
    } else {
        Log::warning('[notify] no open trade found for symbol', [
            'symbol' => $symbol,
        ]);
    }

    // Telegram
    $reasonText = match($reason) {
        'STOP_MARKET'          => 'Стоп-лосс',
        'TRAILING_STOP_MARKET' => 'Трейлинг-стоп',
        'TAKE_PROFIT_MARKET'   => 'Тейк-профит',
        default                => $reason,
    };

    $pnlFormatted = number_format($pnlFromRust, 2);
    $pnlSign = $pnlFromRust >= 0 ? '🟢' : '🔴';

    $msg  = "🔔 <b>ПОЗИЦИЯ ЗАКРЫТА</b>\n";
    $msg .= "Символ: {$symbol}\n";
    $msg .= "Причина: {$reasonText}\n";
    $msg .= "Цена выхода: \${$exitPrice}\n";
    $msg .= "{$pnlSign} PnL: \${$pnlFormatted}";

    $ch = curl_init("https://api.telegram.org/bot$TOKEN/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'chat_id'    => $CHAT_ID,
        'text'       => $msg,
        'parse_mode' => 'HTML',
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);

    return response()->json([
        'status'      => 'ok',
        'journal_id'  => $journal?->id,
        'trade_id'    => $trade?->id,
        'exit_reason' => $exitReason,
        'net_pnl'     => $netPnl,
    ]);
});