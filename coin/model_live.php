<?php
// ============================================
// coin/model_live.php
// Собирает свечи Binance, зовёт модель, возвращает результат
// ============================================
require_once __DIR__ . '/model_check.php';

function runModelCheck($symbol = 'BTCUSDT')
{
    // --- 1. Свечи с реального Binance (то же, что в old_bot_15m.php) ---
    $url = "https://api.binance.com/api/v3/klines?symbol={$symbol}&interval=15m&limit=4";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $raw = curl_exec($ch);
    curl_close($ch);

    $klines = json_decode($raw, true);
    if (!is_array($klines) || count($klines) < 4) {
        return ['error' => 'Не удалось получить свечи с Binance'];
    }

    // [0] = текущая, [1..3] = последние 3 закрытые
    $c1 = $klines[1];
    $c2 = $klines[2];
    $c3 = $klines[3];

    $entry_price = (float)$c3[4];  // close последней закрытой
    $signal_time = gmdate('Y-m-d H:i:s', (int)($c3[0] / 1000));

    // --- 2. kf-данные (передаём нули, если их нет под рукой) ---
    // Если хочешь точные kf — надо тянуть из БД или считать, как в old_bot_15m.php
    $kf_data = [
        'kf'             => 0,
        'kf_btc_15m_oth' => 0,
        'kf_eth_15m_oth' => 0,
        'kf_eth_15m_old' => 0,
        'kf_btc_1h_oth'  => 0,
        'kf_btc_1h_old'  => 0,
        'kf_btc_1d_oth'  => 0,
        'kf_btc_1d_old'  => 0,
    ];

    // --- 3. Спрашиваем модель ---
    $res = checkWithModel($c1, $c2, $c3, $entry_price, $signal_time, $kf_data);

    if (!$res || !isset($res['probability_tp'])) {
        return ['error' => 'Модель не ответила'];
    }

    return [
        'probability_tp' => (float)$res['probability_tp'],
        'threshold'      => (float)($res['threshold'] ?? 0.84),
        'decision'       => $res['decision'] ?? 'UNKNOWN',
        'entry_price'    => $entry_price,
        'signal_time'    => $signal_time,
    ];
}