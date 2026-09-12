<?php
// ============================================
// coin/telegram_sender.php
// ============================================

$TOKEN   = getenv('TG_TOKEN')   ?: 'YOUR_BOT_TOKEN_HERE';
$CHAT_ID = getenv('TG_CHAT_ID') ?: 'YOUR_CHAT_ID_HERE';
$SYMBOL  = 'BTCUSDT';

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

// Fear & Greed
$fg_line = '';
$fg_hash_part = '';
if (isset($fear_greed) && $fear_greed && !isset($fear_greed['error'])) {
    $fgv = (int)$fear_greed['value'];
    if ($fgv <= 25)      { $fg_txt = 'ЦЕНА ПАДАЕТ';         $fg_hint = 'Экстремальный страх'; }
    elseif ($fgv <= 45)  { $fg_txt = 'ВОЗМОЖНО ПАДАЕТ';     $fg_hint = 'Страх на рынке'; }
    elseif ($fgv <= 55)  { $fg_txt = 'НЕОПРЕДЕЛЁННОСТЬ';    $fg_hint = 'Рынок в боковике'; }
    elseif ($fgv <= 75)  { $fg_txt = 'ВОЗМОЖНО РАСТЁТ';     $fg_hint = 'Жадность на рынке'; }
    else                 { $fg_txt = 'ЦЕНА РАСТЁТ';         $fg_hint = 'Экстремальная жадность'; }
    $fg_line = "$fg_txt — $fgv/100 ($fg_hint)";
    $fg_hash_part = 'fg_' . $fgv;
}

// Новости
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
// 1. KF-СИГНАЛЫ (BTC)
// ============================================
$kf_lines = [];
$kf_hash_parts = [];

foreach (['1d', '1h', '15m'] as $tf) {
    $q = mysqli_query($connection, "
        SELECT `id`, `kf`, `data` FROM (
            SELECT id, kf, data, '1d' AS tf FROM `oth_1d`  WHERE Nazvanie = '$SYMBOL'
            UNION ALL
            SELECT id, kf, data, '1h' AS tf FROM `oth_1h`  WHERE Nazvanie = '$SYMBOL'
            UNION ALL
            SELECT id, kf, data, '15m' AS tf FROM `oth_15m` WHERE Nazvanie = '$SYMBOL'
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
        $kf_hash_parts[] = "kf_" . $tf . "_" . $row['id'];
    } else {
        $kf_lines[] = "$label: 🟢 шанс маленький";
    }
}

// ============================================
// 2. НЕЙРОСЕТЬ (BTC)
// ============================================
$neural_lines = [];
$neural_hash_parts = [];

foreach (['1d', '1h', '15m'] as $tf) {
    $q = mysqli_query($connection, "SELECT * FROM `neural_predictions` 
        WHERE `symbol` = '$SYMBOL' AND `timeframe` = '$tf' 
        AND (
            (timeframe = '1d'  AND DATE(`created_at`) = DATE(UTC_TIMESTAMP()))
         OR (timeframe = '1h'  AND DATE_FORMAT(`created_at`, '%Y-%m-%d %H') = DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H'))
         OR (timeframe = '15m' AND `created_at` >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE)
        )
        ORDER BY `created_at` DESC LIMIT 1");
    $label = $tf == '1d' ? '1D' : ($tf == '1h' ? '1H' : '15M');

    if ($row = mysqli_fetch_assoc($q)) {
        $data = json_decode($row['json_data'], true);
        if ($data) {
            $change = $data['change_percent'];
            $sign   = $change > 0 ? '+' : '';
            $neural_lines[] = "$label: $sign$change% (\$" . $data['future_price'] . ", уверенность " . round($data['confidence'] * 100) . "%)";
            $neural_hash_parts[] = "neural_" . $tf . "_" . $row['id'];
            continue;
        }
    }
    $neural_lines[] = "$label: 🟢 шанс маленький";
}

// ============================================
// 3. СЛИВ ПО ТРЁМ (BTC + ETH)
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
        } else {
            $old_block_lines[] = "$label: 🟢 шанс маленький";
        }
    }
    $old_block_lines[] = "";
}

// ============================================
// ОБЩИЙ HASH
// ============================================
$all_hashes = array_merge($kf_hash_parts, $old_hash_parts, $neural_hash_parts);
if ($fg_hash_part)   $all_hashes[] = $fg_hash_part;
if ($news_hash_part) $all_hashes[] = $news_hash_part;
sort($all_hashes);
$common_hash = md5(implode('|', $all_hashes));

// ============================================
// ОТПРАВКА
// ============================================
if (!tgAlreadySent($connection, $common_hash)) {

    $now_msk = (new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('d.m.Y H:i');

    $msg  = "🤖 <b>СИГНАЛЫ</b> — $now_msk МСК\n\n";

    if ($fg_line) {
        $msg .= "📌 <b>ИНДЕКС СТРАХА И ЖАДНОСТИ</b>\n";
        $msg .= $fg_line . "\n\n";
    }

    if (!empty($news_lines)) {
        $msg .= "📰 <b>НОВОСТИ</b>\n";
        $msg .= implode("\n", $news_lines) . "\n\n";
    }

    $msg .= "📊 <b>KF-СИГНАЛЫ (BTCUSDT)</b>\n";
    $msg .= implode("\n", $kf_lines) . "\n\n";

    $msg .= "🧠 <b>НЕЙРОСЕТЬ (BTCUSDT)</b>\n";
    $msg .= implode("\n", $neural_lines) . "\n\n";

    $msg .= "🐢 <b>СЛИВ ПО ТРЁМ</b>\n";
    $msg .= implode("\n", $old_block_lines);

    tgSend($TOKEN, $CHAT_ID, $msg);
    tgMarkSent($connection, $common_hash);
}
?>