<?php
// ============================================
// ПРОВЕРКА СОГЛАСОВАННОСТИ ИСТОРИИ
// 15m ↔ 1h ↔ 1d
// ============================================

$h15 = json_decode(file_get_contents(__DIR__ . '/history_15m.json'), true);
$h1h = json_decode(file_get_contents(__DIR__ . '/history_1h.json'), true);
$h1d = json_decode(file_get_contents(__DIR__ . '/history_1d.json'), true);

echo "Загружено:\n";
echo "  15m: " . count($h15) . " свечей\n";
echo "  1h:  " . count($h1h) . " свечей\n";
echo "  1d:  " . count($h1d) . " свечей\n\n";

// ============================================
// БИНАРНЫЙ ПОИСК
// ============================================
function findIdx($arr, $ts_ms) {
    $lo = 0; $hi = count($arr) - 1; $found = -1;
    while ($lo <= $hi) {
        $mid = (int)(($lo + $hi) / 2);
        if ($arr[$mid][0] <= $ts_ms) { $found = $mid; $lo = $mid + 1; }
        else { $hi = $mid - 1; }
    }
    return $found;
}

// ============================================
// ПРОВЕРКА 1: 1d ↔ 1h
// ============================================
echo str_repeat("=", 100) . "\n";
echo "  ПРОВЕРКА 1: 1D ↔ 1H\n";
echo str_repeat("=", 100) . "\n\n";

$test_day_ts = strtotime('2025-06-15 00:00:00 UTC') * 1000;

$idx_1d = findIdx($h1d, $test_day_ts);
if ($idx_1d < 0) {
    echo "❌ Не найдена 1d свеча для 2025-06-15\n";
} else {
    $day = $h1d[$idx_1d];
    $day_open  = (float)$day[1];
    $day_high  = (float)$day[2];
    $day_low   = (float)$day[3];
    $day_close = (float)$day[4];
    $day_time  = gmdate('Y-m-d H:i', $day[0] / 1000);

    echo "1d свеча: {$day_time}\n";
    echo "  O: {$day_open}, H: {$day_high}, L: {$day_low}, C: {$day_close}\n\n";

    // Ищем все 1h свечи за этот день
    $day_start_ms = $day[0];
    $day_end_ms = $day_start_ms + 24 * 3600 * 1000;

    $hours_in_day = [];
    for ($i = 0; $i < count($h1h); $i++) {
        if ($h1h[$i][0] >= $day_start_ms && $h1h[$i][0] < $day_end_ms) {
            $hours_in_day[] = $h1h[$i];
        }
    }

    echo "1h свечей в этом дне: " . count($hours_in_day) . "\n\n";

    if (count($hours_in_day) > 0) {
        $first_h = $hours_in_day[0];
        $last_h  = $hours_in_day[count($hours_in_day) - 1];

        echo "Первая 1h свеча: " . gmdate('Y-m-d H:i', $first_h[0] / 1000) . "\n";
        echo "  O: {$first_h[1]}, C: {$first_h[4]}\n";
        echo "Последняя 1h свеча: " . gmdate('Y-m-d H:i', $last_h[0] / 1000) . "\n";
        echo "  O: {$last_h[1]}, C: {$last_h[4]}\n\n";

        // Проверка
        echo "Проверка:\n";
        echo "  1d.open  = {$day_open}\n";
        echo "  1h[0].open = {$first_h[1]}\n";
        echo "  → " . (abs($day_open - (float)$first_h[1]) < 0.01 ? "✅ Совпадает" : "❌ НЕ совпадает") . "\n\n";

        echo "  1d.close  = {$day_close}\n";
        echo "  1h[last].close = {$last_h[4]}\n";
        echo "  → " . (abs($day_close - (float)$last_h[4]) < 0.01 ? "✅ Совпадает" : "❌ НЕ совпадает") . "\n\n";

        $max_h = 0; $min_l = PHP_FLOAT_MAX;
        foreach ($hours_in_day as $h) {
            $max_h = max($max_h, (float)$h[2]);
            $min_l = min($min_l, (float)$h[3]);
        }
        echo "  1d.high  = {$day_high}\n";
        echo "  max(1h.high) = {$max_h}\n";
        echo "  → " . (abs($day_high - $max_h) < 0.01 ? "✅ Совпадает" : "❌ НЕ совпадает") . "\n\n";

        echo "  1d.low  = {$day_low}\n";
        echo "  min(1h.low) = {$min_l}\n";
        echo "  → " . (abs($day_low - $min_l) < 0.01 ? "✅ Совпадает" : "❌ НЕ совпадает") . "\n";
    }
}

// ============================================
// ПРОВЕРКА 2: 1h ↔ 15m
// ============================================
echo "\n" . str_repeat("=", 100) . "\n";
echo "  ПРОВЕРКА 2: 1H ↔ 15M\n";
echo str_repeat("=", 100) . "\n\n";

$test_hour_ts = strtotime('2025-06-15 12:00:00 UTC') * 1000;

$idx_1h = findIdx($h1h, $test_hour_ts);
if ($idx_1h < 0) {
    echo "❌ Не найдена 1h свеча для 2025-06-15 12:00\n";
} else {
    $hour = $h1h[$idx_1h];
    $hour_open  = (float)$hour[1];
    $hour_high  = (float)$hour[2];
    $hour_low   = (float)$hour[3];
    $hour_close = (float)$hour[4];
    $hour_time  = gmdate('Y-m-d H:i', $hour[0] / 1000);

    echo "1h свеча: {$hour_time}\n";
    echo "  O: {$hour_open}, H: {$hour_high}, L: {$hour_low}, C: {$hour_close}\n\n";

    // Ищем 4 15m свечи в этом часе
    $hour_start_ms = $hour[0];
    $hour_end_ms = $hour_start_ms + 3600 * 1000;

    $candles_15m_in_hour = [];
    for ($i = 0; $i < count($h15); $i++) {
        if ($h15[$i][0] >= $hour_start_ms && $h15[$i][0] < $hour_end_ms) {
            $candles_15m_in_hour[] = $h15[$i];
        }
    }

    echo "15m свечей в этом часе: " . count($candles_15m_in_hour) . "\n\n";

    if (count($candles_15m_in_hour) >= 4) {
        foreach ($candles_15m_in_hour as $idx => $c) {
            $t = gmdate('H:i', $c[0] / 1000);
            echo "  [{$idx}] {$t}  O={$c[1]} H={$c[2]} L={$c[3]} C={$c[4]}\n";
        }
        echo "\n";

        $first = $candles_15m_in_hour[0];
        $last  = $candles_15m_in_hour[count($candles_15m_in_hour) - 1];

        echo "Проверка:\n";
        echo "  1h.open = {$hour_open}\n";
        echo "  15m[0].open = {$first[1]}\n";
        echo "  → " . (abs($hour_open - (float)$first[1]) < 0.01 ? "✅ Совпадает" : "❌ НЕ совпадает") . "\n\n";

        echo "  1h.close = {$hour_close}\n";
        echo "  15m[last].close = {$last[4]}\n";
        echo "  → " . (abs($hour_close - (float)$last[4]) < 0.01 ? "✅ Совпадает" : "❌ НЕ совпадает") . "\n\n";

        $max_h = 0; $min_l = PHP_FLOAT_MAX;
        foreach ($candles_15m_in_hour as $c) {
            $max_h = max($max_h, (float)$c[2]);
            $min_l = min($min_l, (float)$c[3]);
        }
        echo "  1h.high = {$hour_high}\n";
        echo "  max(15m.high) = {$max_h}\n";
        echo "  → " . (abs($hour_high - $max_h) < 0.01 ? "✅ Совпадает" : "❌ НЕ совпадает") . "\n\n";

        echo "  1h.low = {$hour_low}\n";
        echo "  min(15m.low) = {$min_l}\n";
        echo "  → " . (abs($hour_low - $min_l) < 0.01 ? "✅ Совпадает" : "❌ НЕ совпадает") . "\n";
    }
}

// ============================================
// ПРОВЕРКА 3: СИГНАЛ → СВЕЧИ
// ============================================
echo "\n" . str_repeat("=", 100) . "\n";
echo "  ПРОВЕРКА 3: СИГНАЛ ИЗ CSV → СВЕЧИ В ИСТОРИИ\n";
echo str_repeat("=", 100) . "\n\n";

if (file_exists(__DIR__ . '/ml_signals_log.csv')) {
    $fp = fopen(__DIR__ . '/ml_signals_log.csv', 'r');
    $header = fgetcsv($fp);
    $first_row = null;
    while ($r = fgetcsv($fp)) {
        if (count($r) === count($header)) { $first_row = array_combine($header, $r); break; }
    }
    fclose($fp);

    if ($first_row) {
        $signal_time = $first_row['signal_time'];
        echo "Сигнал из CSV: {$signal_time}\n\n";

        $sig_ts = strtotime($signal_time . ' UTC') * 1000;

        $i15 = findIdx($h15, $sig_ts);
        $i1h = findIdx($h1h, $sig_ts);
        $i1d = findIdx($h1d, $sig_ts);

        echo "Найдено в истории:\n";
        echo "  15m[" . $i15 . "]: " . gmdate('Y-m-d H:i', $h15[$i15][0] / 1000) . "\n";
        echo "  1h[" . $i1h . "]:  " . gmdate('Y-m-d H:i', $h1h[$i1h][0] / 1000) . "\n";
        echo "  1d[" . $i1d . "]:  " . gmdate('Y-m-d H:i', $h1d[$i1d][0] / 1000) . "\n\n";

        echo "Свечи (c1, c2, c3) из CSV:\n";
        echo "  c1: O={$first_row['c1_open']} C={$first_row['c1_close']}\n";
        echo "  c2: O={$first_row['c2_open']} C={$first_row['c2_close']}\n";
        echo "  c3: O={$first_row['c3_open']} C={$first_row['c3_close']}\n\n";

        echo "Свечи из истории (i-2, i-1, i):\n";
        for ($k = $i15 - 2; $k <= $i15; $k++) {
            $label = ['c1','c2','c3'][$k - ($i15 - 2)];
            echo "  {$label} [" . gmdate('H:i', $h15[$k][0] / 1000) . "]: O={$h15[$k][1]} C={$h15[$k][4]}\n";
        }
    }
}