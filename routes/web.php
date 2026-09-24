<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::post('/notify/position-closed', function (Request $request) {
    $TOKEN   = "5608379544:AAHU2hFHcCVbQKD8RJS6HWunN_IeGCDcUmc";
    $CHAT_ID = 1745395495;

    $data = $request->json()->all();

    $symbol    = $data['symbol'] ?? 'BTCUSDT';
    $exitPrice = $data['exit_price'] ?? '0';
    $pnl       = $data['pnl'] ?? '0';
    $reason    = $data['reason'] ?? 'unknown';

    $reasonText = match($reason) {
        'STOP_MARKET'           => 'Стоп-лосс',
        'TRAILING_STOP_MARKET'  => 'Трейлинг-стоп',
        'TAKE_PROFIT_MARKET'    => 'Тейк-профит',
        default                 => $reason,
    };

    $pnlFormatted = number_format((float)$pnl, 2);
    $pnlSign = (float)$pnl >= 0 ? '🟢' : '🔴';

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

    return response()->json(['status' => 'ok']);
});