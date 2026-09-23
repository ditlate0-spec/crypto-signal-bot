<?php
// ============================================

// Подключение к БД (добавлено для standalone-запуска)
$connection = mysqli_connect(
    getenv('DB_HOST') ?: 'db',
    getenv('DB_USERNAME') ?: 'root',
    getenv('DB_PASSWORD') ?: 'root',
    getenv('DB_DATABASE') ?: 'volta'
);

if (!$connection) {
    die('MySQL connection failed: ' . mysqli_connect_error());
}

mysqli_set_charset($connection, 'utf8mb4');
$TOKEN   = "ваш токен";
$CHAT_ID = ваш;
$SYMBOL  = 'BTCUSDT';

$data_kf     = ['BTCUSDT' => ['1d' => 0, '1h' => 0, '15m' => 0], 'ETHUSDT' => ['1d' => 0, '1h' => 0, '15m' => 0]];
$data_old    = ['BTCUSDT' => ['1d' => 0, '1h' => 0, '15m' => 0], 'ETHUSDT' => ['1d' => 0, '1h' => 0, '15m' => 0]];

function tgSend($token, $chat_id, $text) {
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => 'HTML'
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function tgAlreadySent($connection, $hash) {
    $h = mysqli_real_escape_string($connection, $hash);
    $q = mysqli_query($connection, "SELECT `id` FROM `telegram_sent` 
        WHERE `hash` = '$h' LIMIT 1");
    return $q && mysqli_num_rows($q) > 0;
}

function tgMarkSent($connection, $hash) {
    $h   = mysqli_real_escape_string($connection, $hash);
    $now = gmdate('Y-m-d H:i:s');
    mysqli_query($connection, "INSERT INTO `telegram_sent` 
        (`hash`, `sent_at`) VALUES ('$h', '$now')");
}

// ============================================
// 0. FEAR & GREED
// ============================================
$fg_line = '';
$fg_hash_part = '';
$fg_block_trade = false;

if (isset($fear_greed) && $fear_greed && !isset($fear_greed['error'])) {
    $fgv = (int)$fear_greed['value'];
    if ($fgv <= 25)      { $fg_txt = 'ЦЕНА ПАДАЕТ';         $fg_hint = 'Экстремальный страх'; }
    elseif ($fgv <= 45)  { $fg_txt = 'ВОЗМОЖНО ПАДАЕТ';     $fg_hint = 'Страх на рынке'; }
    elseif ($fgv <= 55)  { $fg_txt = 'НЕОПРЕДЕЛЁННОСТЬ';    $fg_hint = 'Рынок в боковике'; }
    elseif ($fgv <= 75)  { $fg_txt = 'ВОЗМОЖНО РАСТЁТ';     $fg_hint = 'Жадность на рынке'; }
    else                 { $fg_txt = 'ЦЕНА РАСТЁТ';         $fg_hint = 'Экстремальная жадность'; }

    $fg_line = "$fg_txt — $fgv/100 ($fg_hint)";
    $fg_hash_part = 'fg_' . $fgv;

    if ($fgv > 75) {
        $fg_block_trade = true;
    }
}

// ============================================
// 0. НОВОСТИ
// ============================================
$news_lines = [];
$news_hash_part = '';

if (isset($crypto_news) && $crypto_news && !isset($crypto_news['error'])) {
    $pos = (int)$crypto_news['positive_count'];
    $neg = (int)$crypto_news['negative_count'];
    $neu = (int)$crypto_news['neutral_count'];

    $news_lines[] = "🟢 Позитивных: $pos";
    $news_lines[] = "🔴 Негативных: $neg";
    $news_lines[] = "⚪ Нейтральных: $neu";

    $news_hash_part = "news_{$pos}_{$neg}_{$neu}";
}

// ============================================
// 1. KF-СИГНАЛЫ
// ============================================
$kf_lines = [];
$kf_hash_parts = [];

foreach (['BTCUSDT', 'ETHUSDT'] as $sym) {
    $kf_lines[] = "━━━ <b>$sym</b> ━━━";

    foreach (['1d', '1h', '15m'] as $tf) {
        $q = mysqli_query($connection, "
            SELECT `id`, `kf`, `data` FROM (
                SELECT id, kf, data, '1d' AS tf FROM `oth_1d`  WHERE Nazvanie = '$sym'
                UNION ALL
                SELECT id, kf, data, '1h' AS tf FROM `oth_1h`  WHERE Nazvanie = '$sym'
                UNION ALL
                SELECT id, kf, data, '15m' AS tf FROM `oth_15m` WHERE Nazvanie = '$sym'
            ) AS all_signals
            WHERE tf = '$tf'
            AND (
                (tf = '1d'  AND DATE(`data`) = DATE(UTC_TIMESTAMP()))
             OR (tf = '1h'  AND DATE_FORMAT(`data`, '%Y-%m-%d %H') = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H'))
             OR (tf = '15m' AND `data` >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE)
            )
            ORDER BY data DESC LIMIT 1
        ");
        $label = $tf == '1d' ? '1D' : ($tf == '1h' ? '1H' : '15M');

        if ($row = mysqli_fetch_assoc($q)) {
            $kf_lines[] = "$label: " . round($row['kf'], 1) . "%";
            $kf_hash_parts[] = "kf_{$sym}_{$tf}_" . $row['id'];
            $data_kf[$sym][$tf] = (float)$row['kf'];
        } else {
            $kf_lines[] = "$label: 🟢 шанс маленький";
        }
    }
    $kf_lines[] = "";
}

// ============================================
// 2. СЛИВ ПО ТРЁМ
// ============================================
$old_hash_parts = [];
$old_block_lines = [];

foreach (['BTCUSDT', 'ETHUSDT'] as $sym) {
    $old_block_lines[] = "━━━ <b>$sym</b> ━━━";

    foreach (['1d', '1h', '15m'] as $tf) {
        $q = mysqli_query($connection, "
            SELECT `id`, `kf`, `text`, `created_at` FROM (
                SELECT id, kf, text, created_at, '1d' AS tf FROM `old_bot_signals_1d`  WHERE symbol = '$sym'
                UNION ALL
                SELECT id, kf, text, created_at, '1h' AS tf FROM `old_bot_signals_1h`  WHERE symbol = '$sym'
                UNION ALL
                SELECT id, kf, text, created_at, '15m' AS tf FROM `old_bot_signals_15m` WHERE symbol = '$sym'
            ) AS all_signals
            WHERE tf = '$tf'
            AND (
                (tf = '1d'  AND DATE(`created_at`) = DATE(UTC_TIMESTAMP()))
             OR (tf = '1h'  AND DATE_FORMAT(`created_at`, '%Y-%m-%d %H') = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H'))
             OR (tf = '15m' AND `created_at` >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE)
            )
            ORDER BY created_at DESC LIMIT 1
        ");
        $label = $tf == '1d' ? '1D' : ($tf == '1h' ? '1H' : '15M');

        if ($row = mysqli_fetch_assoc($q)) {
            $old_block_lines[] = "$label: " . round($row['kf'], 1) . "% — " . $row['text'];
            $old_hash_parts[] = "old_{$sym}_{$tf}_" . $row['id'];
            $data_old[$sym][$tf] = (float)$row['kf'];
        } else {
            $old_block_lines[] = "$label: 🟢 шанс маленький";
        }
    }
    $old_block_lines[] = "";
}

// ============================================
// 3. КАСКАДНЫЙ АЛГОРИТМ
// ============================================
$verdict = "❌ НЕ ТОРГУЕМ (Тренды слишком слабы)";

$THRESH_DAY_OTH     = 20;
$THRESH_DAY_OLD     = 50;
$THRESH_HOUR_OTH    = 10;
$THRESH_HOUR_OLD    = 40;
$THRESH_BTC_15M_OTH = 30;
$THRESH_BTC_15M_OLD = 55;
$THRESH_ETH_15M_OLD = 70;

$btc_day_strong  = ($data_kf['BTCUSDT']['1d'] > $THRESH_DAY_OTH && $data_old['BTCUSDT']['1d'] > $THRESH_DAY_OLD);
$btc_hour_strong = ($data_kf['BTCUSDT']['1h'] > $THRESH_HOUR_OTH && $data_old['BTCUSDT']['1h'] > $THRESH_HOUR_OLD);

$bots_btc_15m_ready = ($data_kf['BTCUSDT']['15m'] > $THRESH_BTC_15M_OTH && $data_old['BTCUSDT']['15m'] > $THRESH_BTC_15M_OLD);
$bots_eth_15m_ready = ($data_kf['ETHUSDT']['15m'] > $THRESH_BTC_15M_OTH && $data_old['ETHUSDT']['15m'] > $THRESH_ETH_15M_OLD);
$trigger_15m_ready  = ($bots_btc_15m_ready && $bots_eth_15m_ready);

if ($btc_day_strong) {
    if ($btc_hour_strong) {
        $verdict = "🚀 <b>ВХОД РАЗРЕШЕН: ТОРГУЕМ НА 1H!</b>\n(1D одобрен [>" . $THRESH_DAY_OTH . "%] + 1H одобрен [>" . $THRESH_HOUR_OTH . "%])";
    } elseif ($trigger_15m_ready) {
        $verdict = "⚡️ <b>ВХОД РАЗРЕШЕН: ТОРГУЕМ НА 15M!</b>\n(1D одобрен + Боты BTC/ETH дали синхронный импульс)";
    } else {
        $verdict = "⏸ <b>ЗАБОР (ЖДЕМ СИНХРОНИЗАЦИИ)</b>\n(День > " . $THRESH_DAY_OTH . "%, но локальный Час слаб, а на 15М нет парного сигнала BTC+ETH)";
    }
} 
elseif ($btc_hour_strong) {
    if ($trigger_15m_ready) {
        $verdict = "⚡️ <b>ВХОД РАЗРЕШЕН: СКАЛЬПИНГ НА 15M!</b>\n(1H одобрен [>" . $THRESH_HOUR_OTH . "%] + Боты BTC/ETH одновременно)";
    } else {
        $verdict = "⏸ ЗАБОР (ЖДЕМ СИНХРОНИЗАЦИИ)\n(Час > " . $THRESH_HOUR_OTH . "%, но 15-минутный импульс по двум монетам отсутствует)";
    }
} else {
    $verdict = "❌ НЕ ТОРГУЕМ\n(Старшие фильтры BTC 1D и 1H не имеют достаточной уверенности)";
}

// ============================================
// 3.1. БЛОКИРОВКА ПО ЖАДНОСТИ
// ============================================
$verdict_raw = $verdict;

if ($fg_block_trade && strpos($verdict, 'ВХОД РАЗРЕШЕН') !== false) {
    $verdict = "🚫 <b>НЕ ТОРГУЕМ (ЭКСТРЕМАЛЬНАЯ ЖАДНОСТЬ)</b>\n" .
               "(Индекс страха и жадности > 75 — риск разворота вверх)";
}

// ============================================
// 4. ПОДКЛЮЧЕНИЕ RUST EXECUTOR
// ============================================
require_once __DIR__ . '/RustExecutorClient.php';
$executor = new RustExecutorClient();

$tradeSymbol = 'BTCUSDT';
$margin      = 100;
$leverage    = 50;
$sl_percent  = 0.5;
$trail_activate_percent = 0.2;
$trail_callback_rate    = 0.1;

// Считаем, что позиция открыта, если в БД есть запись со status='open'.
// Rust сам проверит наличие позиции на бирже при /execute.
$hasPosition = false;
$q_pos = mysqli_query($connection, "
    SELECT `id` FROM `trades` WHERE `status` = 'open' LIMIT 1
");
if ($q_pos && mysqli_num_rows($q_pos) > 0) {
    $hasPosition = true;
}

// ============================================
// 4.1.5. СИНХРОНИЗАЦИЯ: если в БД open, но позиции нет — закрываем
// ============================================
$q_open = mysqli_query($connection, "
    SELECT `id` FROM `trades`
    WHERE `status` = 'open'
    ORDER BY `opened_at` DESC
    LIMIT 1
");

if ($q_open && $row_open = mysqli_fetch_assoc($q_open)) {
    if (!$hasPosition) {
        $now_utc = gmdate('Y-m-d H:i:s');
        mysqli_query($connection, "
            UPDATE `trades`
            SET `status` = 'closed', `closed_at` = '$now_utc'
            WHERE `id` = {$row_open['id']}
        ");
    }
}

// ============================================
// 4.1. ЕСЛИ ПОЗИЦИЯ ОТКРЫТА — ОТПРАВЛЯЕМ СИГНАЛЫ В ТГ
// ============================================
if ($hasPosition) {

    $all_hashes = array_merge($kf_hash_parts, $old_hash_parts);
    if ($fg_hash_part)   $all_hashes[] = $fg_hash_part;
    if ($news_hash_part) $all_hashes[] = $news_hash_part;
    sort($all_hashes);
    $common_hash = md5(implode('|', $all_hashes));

    if (!tgAlreadySent($connection, $common_hash)) {

        $now_msk = (new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('d.m.Y H:i');

        $msg  = "🤖 <b>СИГНАЛЫ (СДЕЛКА ОТКРЫТА)</b> — $now_msk МСК\n\n";
        $msg .= "🤖 <b>ВЕРДИКТ СИСТЕМЫ</b>\n";
        $msg .= $verdict . "\n\n";
        $msg .= "───────────────────\n\n";

        if ($fg_line) {
            $msg .= "📌 <b>ИНДЕКС СТРАХА И ЖАДНОСТИ</b>\n";
            $msg .= $fg_line . "\n\n";
        }

        if (!empty($news_lines)) {
            $msg .= "📰 <b>НОВОСТИ</b>\n";
            $msg .= implode("\n", $news_lines) . "\n\n";
        }

        $msg .= "📊 <b>KF-СИГНАЛЫ</b>\n";
        $msg .= implode("\n", $kf_lines) . "\n\n";

        $msg .= "🐢 <b>СЛИВ ПО ТРЁМ</b>\n";
        $msg .= implode("\n", $old_block_lines);

        tgSend($TOKEN, $CHAT_ID, $msg);
        tgMarkSent($connection, $common_hash);
    }
}

// ============================================
// 4.2. ОТКРЫТИЕ СДЕЛКИ — ПОСЛЕ ПРОВЕРКИ МОДЕЛЬЮ
// ============================================
$candle_ts   = floor(time() / 900) * 900;
$candle_time = gmdate('Y-m-d H:i', $candle_ts);
$signal_hash = md5('trade_' . $candle_time);
$already_traded = tgAlreadySent($connection, $signal_hash);

// ============================================
// 4.2.0. ЗАЩИТА ОТ ПОВТОРНЫХ СДЕЛОК (1 ЧАС)
// ============================================
$cooldown_ok = true;
$cooldown_left_min = 0;

$q_last = mysqli_query($connection, "
    SELECT `opened_at` FROM `trades`
    WHERE `status` IN ('open', 'closed')
    ORDER BY `opened_at` DESC
    LIMIT 1
");

if ($q_last && $row_last = mysqli_fetch_assoc($q_last)) {
    $last_ts = strtotime($row_last['opened_at'] . ' UTC');
    $now_ts  = time();
    $diff    = $now_ts - $last_ts;

    if ($diff < 3600) {
        $cooldown_ok = false;
        $cooldown_left_min = ceil((3600 - $diff) / 60);
    }
}

// ============================================
// 4.2.1. ОТКРЫТИЕ (с блокировкой по жадности)
// ============================================
if (!$already_traded && $cooldown_ok && !$fg_block_trade && strpos($verdict, 'ВХОД РАЗРЕШЕН') !== false) {
    // ============================================
    // ПОЛУЧАЕМ 3 СВЕЧИ С BINANCE (для ML-модели)
    // ============================================
    $candles_ok = false;
    $c1 = $c2 = $c3 = null;

    $url_k = "https://api.binance.com/api/v3/klines?symbol=BTCUSDT&interval=15m&limit=4";
    $ch_k = curl_init($url_k);
    curl_setopt($ch_k, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_k, CURLOPT_TIMEOUT, 10);
    $raw_k = curl_exec($ch_k);
    curl_close($ch_k);

    $klines = json_decode($raw_k, true);
    if (is_array($klines) && count($klines) >= 4) {
        $c1 = $klines[1];
        $c2 = $klines[2];
        $c3 = $klines[3];
        $candles_ok = true;
    }

    // ============================================
    // СПРАШИВАЕМ МОДЕЛЬ
    // ============================================
    $model_ok = false;
    $model_prob = null;
    $model_dec  = null;

    if ($candles_ok) {
        require_once __DIR__ . '/model_check.php';

        $entry_price_for_model = (float)$c3[4];
        $signal_time_for_model = gmdate('Y-m-d H:i:s', (int)($c3[0] / 1000));

        $kf_data_for_model = [
            'kf'             => $data_old['BTCUSDT']['15m'],
            'kf_btc_15m_oth' => $data_kf['BTCUSDT']['15m'],
            'kf_eth_15m_oth' => $data_kf['ETHUSDT']['15m'],
            'kf_eth_15m_old' => $data_old['ETHUSDT']['15m'],
            'kf_btc_1h_oth'  => $data_kf['BTCUSDT']['1h'],
            'kf_btc_1h_old'  => $data_old['BTCUSDT']['1h'],
            'kf_btc_1d_oth'  => $data_kf['BTCUSDT']['1d'],
            'kf_btc_1d_old'  => $data_old['BTCUSDT']['1d'],
        ];

        $model_res = checkWithModel($c1, $c2, $c3,
                                    $entry_price_for_model,
                                    $signal_time_for_model,
                                    $kf_data_for_model);

        if ($model_res && isset($model_res['probability_tp'])) {
            $model_prob = (float)$model_res['probability_tp'];
            $model_dec  = $model_res['decision'];

            $MODEL_THRESHOLD = 0.85;

            if ($model_prob >= $MODEL_THRESHOLD) {
                $model_ok = true;
            }

            $prob_pct  = round($model_prob * 100, 1);
            $emoji     = $model_ok ? '✅' : '❌';
            $status    = $model_ok ? 'ВХОД РАЗРЕШЁН' : 'ПРОПУСК (низкая вероятность)';

            tgSend($TOKEN, $CHAT_ID,
                "$emoji <b>ПРОВЕРКА МОДЕЛИ</b>\n" .
                "Вероятность TP: <b>{$prob_pct}%</b>\n" .
                "Порог: {$MODEL_THRESHOLD}\n" .
                "Решение: <b>{$status}</b>\n" .
                "Цена: $" . number_format($entry_price_for_model, 2) . "\n" .
                "Свеча: {$signal_time_for_model}"
            );
        } else {
            tgSend($TOKEN, $CHAT_ID, "⚠️ Модель недоступна. Пропускаю сигнал для безопасности.");
        }
    } else {
        tgSend($TOKEN, $CHAT_ID, "⚠️ Не удалось получить свечи Binance. Пропускаю сигнал.");
    }

    // ============================================
    // ОТКРЫТИЕ СДЕЛКИ — ЗАПРОС В RUST
    // ============================================
    if ($model_ok) {

        $response = $executor->executeTrade([
            'symbol'                 => $tradeSymbol,
            'margin'                 => $margin,
            'leverage'               => $leverage,
            'sl_percent'             => $sl_percent,
            'trail_activate_percent' => $trail_activate_percent,
            'trail_callback_rate'    => $trail_callback_rate,
            'idempotency_key'        => $signal_hash,
        ]);

        if ($response['status'] === 'ok' && isset($response['result'])) {
            $r = $response['result'];

            $entryPrice = (float)$r['entry_price'];
            $quantity   = (float)$r['quantity'];
            $slPrice    = (float)$r['sl_price'];
            $tpPrice    = (float)$r['trail_activation_price'];

            $entryEsc = mysqli_real_escape_string($connection, $entryPrice);
            $slEsc    = mysqli_real_escape_string($connection, $slPrice);
            $tpEsc    = mysqli_real_escape_string($connection, $tpPrice);
            $qtyEsc   = mysqli_real_escape_string($connection, $quantity);
            $now      = gmdate('Y-m-d H:i:s');

            mysqli_query($connection, "INSERT INTO trades 
                (symbol, side, entry_price, quantity, leverage, stop_loss, take_profit, status, opened_at)
                VALUES ('$tradeSymbol', 'SHORT', '$entryEsc', '$qtyEsc', $leverage, '$slEsc', '$tpEsc', 'open', '$now')");

            tgMarkSent($connection, $signal_hash);

            $prob_pct = round($model_prob * 100, 1);

            tgSend($TOKEN, $CHAT_ID, "✅ <b>ШОРТ ОТКРЫТ (Rust)</b>\n" .
                "Символ: $tradeSymbol\n" .
                "Статус: {$r['status']}\n" .
                "Модель: <b>{$prob_pct}%</b> вероятность TP\n" .
                "Цена входа: $" . number_format($entryPrice, 2) . "\n" .
                "Количество: $quantity BTC\n" .
                "Плечо: {$leverage}x\n" .
                "SL: $" . number_format($slPrice, 2) . " (+{$sl_percent}%)\n" .
                "Order ID: {$r['order_id']}\n" .
                "SL Order ID: {$r['sl_order_id']}");

        } else {
            $err = $response['error'] ?? 'unknown error';
            tgSend($TOKEN, $CHAT_ID, "❌ <b>ОШИБКА ОТКРЫТИЯ СДЕЛКИ (Rust)</b>\n" . $err);
            tgMarkSent($connection, $signal_hash);
        }

    } else {
        // Модель сказала "нет" — помечаем свечу как обработанную
        tgMarkSent($connection, $signal_hash);
    }
}
// ============================================
// 4.2.2. БЛОКИРОВКА ПО ЖАДНОСТИ — УВЕДОМЛЕНИЕ
// ============================================
elseif (!$already_traded && $cooldown_ok && $fg_block_trade && strpos($verdict_raw, 'ВХОД РАЗРЕШЕН') !== false) {
    tgSend($TOKEN, $CHAT_ID,
        "🚫 <b>СИГНАЛ ЗАБЛОКИРОВАН (ЖАДНОСТЬ > 75)</b>\n" .
        "KF-вердикт: ВХОД РАЗРЕШЕН\n" .
        "Индекс: $fg_line\n" .
        "Сделка не открыта."
    );
    tgMarkSent($connection, $signal_hash);
}
// ============================================
// 4.2.3. КУЛДАУН — УВЕДОМЛЕНИЕ
// ============================================
elseif (!$already_traded && !$cooldown_ok && !$fg_block_trade && strpos($verdict, 'ВХОД РАЗРЕШЕН') !== false) {
    tgSend($TOKEN, $CHAT_ID,
        "⏳ <b>СИГНАЛ ПРОПУЩЕН (КУЛДАУН)</b>\n" .
        "Вердикт: ВХОД РАЗРЕШЕН\n" .
        "Но прошло менее 1 часа с последней сделки.\n" .
        "Осталось ждать: <b>{$cooldown_left_min} мин</b>"
    );
    tgMarkSent($connection, $signal_hash);
}
 
?>
