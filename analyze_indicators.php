<?php
// ============================================
// АНАЛИЗ ИНДИКАТОРОВ В ПРОПУЩЕННЫХ СИГНАЛАХ
// Считает индикаторы в момент каждого сигнала
// из ml_signals_log.csv (по 3 свечам) и истории 15m
// ============================================

set_time_limit(0);
ini_set('memory_limit', '4G');

// ============================================
// ЗАГРУЗКА ИСТОРИИ 15m
// ============================================
$history_file = __DIR__ . '/history_15m.json';
if (!file_exists($history_file)) die("❌ Нет $history_file\n");
$h15 = json_decode(file_get_contents($history_file), true);
echo "История: " . count($h15) . " свечей\n";

// ============================================
// ЗАГРУЗКА CSV СИГНАЛОВ
// ============================================
$csv = __DIR__ . '/ml_signals_log.csv';
if (!file_exists($csv)) die("❌ Нет $csv\n");
$fp = fopen($csv, 'r');
$header = fgetcsv($fp);
$rows = [];
while ($r = fgetcsv($fp)) {
    if (count($r) !== count($header)) continue;
    $rows[] = array_combine($header, $r);
}
fclose($fp);
echo "Сигналов: " . count($rows) . "\n\n";

// ============================================
// ПОИСК ИНДЕКСА СВЕЧИ ПО ВРЕМЕНИ
// ============================================
function findCandleIdx($h15, $time_str) {
    $ts = strtotime($time_str . ' UTC') * 1000;
    $lo = 0; $hi = count($h15) - 1; $found = -1;
    while ($lo <= $hi) {
        $mid = (int)(($lo + $hi) / 2);
        if ($h15[$mid][0] <= $ts) { $found = $mid; $lo = $mid + 1; }
        else { $hi = $mid - 1; }
    }
    return $found;
}

// ============================================
// ИНДИКАТОРЫ
// ============================================
function sma($h15, $idx, $period) {
    if ($idx < $period - 1) return null;
    $sum = 0;
    for ($i = $idx - $period + 1; $i <= $idx; $i++) $sum += (float)$h15[$i][4];
    return $sum / $period;
}

function rsi($h15, $idx, $period = 14) {
    if ($idx < $period) return null;
    $gains = 0; $losses = 0;
    for ($i = $idx - $period + 1; $i <= $idx; $i++) {
        $change = (float)$h15[$i][4] - (float)$h15[$i-1][4];
        if ($change > 0) $gains += $change;
        else $losses += abs($change);
    }
    $avgGain = $gains / $period;
    $avgLoss = $losses / $period;
    if ($avgLoss == 0) return 100;
    $rs = $avgGain / $avgLoss;
    return 100 - (100 / (1 + $rs));
}

function atr($h15, $idx, $period = 14) {
    if ($idx < $period) return null;
    $trs = [];
    for ($i = $idx - $period + 1; $i <= $idx; $i++) {
        $h = (float)$h15[$i][2];
        $l = (float)$h15[$i][3];
        $pc = (float)$h15[$i-1][4];
        $tr = max($h - $l, abs($h - $pc), abs($l - $pc));
        $trs[] = $tr;
    }
    return array_sum($trs) / $period;
}

function vol_sma($h15, $idx, $period = 20) {
    if ($idx < $period - 1) return null;
    $sum = 0;
    for ($i = $idx - $period + 1; $i <= $idx; $i++) $sum += (float)$h15[$i][5];
    return $sum / $period;
}

function max_high($h15, $idx, $period) {
    if ($idx < $period - 1) return null;
    $m = PHP_FLOAT_MIN;
    for ($i = $idx - $period + 1; $i <= $idx; $i++) $m = max($m, (float)$h15[$i][2]);
    return $m;
}

function min_low($h15, $idx, $period) {
    if ($idx < $period - 1) return null;
    $m = PHP_FLOAT_MAX;
    for ($i = $idx - $period + 1; $i <= $idx; $i++) $m = min($m, (float)$h15[$i][3]);
    return $m;
}

function stddev_close($h15, $idx, $period) {
    if ($idx < $period - 1) return null;
    $closes = [];
    for ($i = $idx - $period + 1; $i <= $idx; $i++) $closes[] = (float)$h15[$i][4];
    $mean = array_sum($closes) / count($closes);
    $var = 0;
    foreach ($closes as $c) $var += ($c - $mean) ** 2;
    return sqrt($var / count($closes));
}

function cumulative_ret($h15, $idx, $period) {
    if ($idx < $period) return null;
    $start = (float)$h15[$idx - $period][4];
    $end = (float)$h15[$idx][4];
    return ($end - $start) / $start * 100;
}

function count_bull($h15, $idx, $period) {
    if ($idx < $period - 1) return null;
    $bulls = 0;
    for ($i = $idx - $period + 1; $i <= $idx; $i++) {
        if ((float)$h15[$i][4] > (float)$h15[$i][1]) $bulls++;
    }
    return $bulls;
}

// ============================================
// ПРОХОД ПО СИГНАЛАМ
// ============================================
$skip_tp = $skip_sl = $take_tp = [];

foreach ($rows as $r) {
    if ($r['action'] === 'TAKE' && $r['result'] === 'TP') {
        $arr = &$take_tp;
    } elseif ($r['action'] === 'SKIP' && $r['result'] === 'TP') {
        $arr = &$skip_tp;
    } elseif ($r['action'] === 'SKIP' && $r['result'] === 'SL') {
        $arr = &$skip_sl;
    } else {
        continue;
    }

    $idx = findCandleIdx($h15, $r['signal_time']);
    if ($idx < 50) continue;

    $close = (float)$h15[$idx][4];

    $rsi_v = rsi($h15, $idx, 14);
    $atr_v = atr($h15, $idx, 14);
    $sma20 = sma($h15, $idx, 20);
    $sma50 = sma($h15, $idx, 50);
    $vol_sma20 = vol_sma($h15, $idx, 20);
    $cur_vol = (float)$h15[$idx][5];
    $std20 = stddev_close($h15, $idx, 20);
    $max20 = max_high($h15, $idx, 20);
    $min20 = min_low($h15, $idx, 20);
    $cum5  = cumulative_ret($h15, $idx, 5);
    $cum10 = cumulative_ret($h15, $idx, 10);
    $cum20 = cumulative_ret($h15, $idx, 20);
    $bulls10 = count_bull($h15, $idx, 10);

    $arr[] = [
        'time' => $r['signal_time'],
        'result' => $r['result'],
        'ml_prob' => (float)$r['ml_prob'],
        'candles' => (int)$r['candles'],

        'rsi'         => $rsi_v,
        'atr_rel'     => $atr_v / $close * 100,         // ATR в %
        'sma20_ratio' => ($close - $sma20) / $sma20 * 100,
        'sma50_ratio' => ($close - $sma50) / $sma50 * 100,
        'vol_ratio'   => $vol_sma20 > 0 ? $cur_vol / $vol_sma20 : null,
        'bb_pos'      => $std20 > 0 ? ($close - $sma20) / ($std20 * 2) : null, // позиция в Bollinger
        'pos_max20'   => ($close - $min20) / max(0.0001, $max20 - $min20),     // позиция в диапазоне 20
        'cum5'        => $cum5,
        'cum10'       => $cum10,
        'cum20'       => $cum20,
        'bulls10'     => $bulls10,
    ];
}

// ============================================
// СРАВНЕНИЕ
// ============================================
echo "SKIP_TP: " . count($skip_tp) . "\n";
echo "SKIP_SL: " . count($skip_sl) . "\n";
echo "TAKE_TP: " . count($take_tp) . "\n\n";

$features = [
    'rsi', 'atr_rel', 'sma20_ratio', 'sma50_ratio',
    'vol_ratio', 'bb_pos', 'pos_max20',
    'cum5', 'cum10', 'cum20', 'bulls10',
];

echo str_repeat("=", 120) . "\n";
echo "  СРАВНЕНИЕ ИНДИКАТОРОВ (SKIP_TP vs SKIP_SL)\n";
echo str_repeat("=", 120) . "\n\n";

printf("%-14s | %12s | %12s | %12s | %10s | %s\n",
    "Индикатор", "SKIP_TP", "SKIP_SL", "TAKE_TP", "Cohen's d", "Различие");
echo str_repeat("-", 100) . "\n";

$cohens = [];
foreach ($features as $f) {
    $means = [];
    $stds = [];
    foreach (['skip_tp', 'skip_sl', 'take_tp'] as $g) {
        $vals = array_filter(array_column($$g, $f), fn($v) => $v !== null);
        if (count($vals) === 0) { $means[$g] = 0; $stds[$g] = 0; continue; }
        $means[$g] = array_sum($vals) / count($vals);
        $m = $means[$g];
        $var = array_sum(array_map(fn($v) => ($v - $m) ** 2, $vals)) / count($vals);
        $stds[$g] = sqrt($var);
    }

    $pooled = sqrt(($stds['skip_tp'] ** 2 + $stds['skip_sl'] ** 2) / 2);
    $d = $pooled > 0 ? abs($means['skip_tp'] - $means['skip_sl']) / $pooled : 0;
    $cohens[$f] = $d;

    $level = $d > 0.8 ? '🔴 СИЛЬНО' : ($d > 0.5 ? '🟠 СРЕДНЕ' : ($d > 0.2 ? '🟡 слабо' : '⚪ нет'));

    printf("%-14s | %12.3f | %12.3f | %12.3f | %10.3f | %s\n",
        $f,
        $means['skip_tp'], $means['skip_sl'], $means['take_tp'],
        $d, $level
    );
}

// ============================================
// ТОП РАЗЛИЧИЙ
// ============================================
echo "\n" . str_repeat("=", 100) . "\n";
echo "  ТОП-5 РАЗЛИЧИЙ\n";
echo str_repeat("=", 100) . "\n\n";

arsort($cohens);
printf("%-14s | %10s | %s\n", "Индикатор", "Cohen's d", "Уровень");
echo str_repeat("-", 50) . "\n";
foreach (array_slice($cohens, 0, 5, true) as $f => $d) {
    $level = $d > 0.8 ? '🔴 СИЛЬНО' : ($d > 0.5 ? '🟠 СРЕДНЕ' : ($d > 0.2 ? '🟡 слабо' : '⚪ нет'));
    printf("%-14s | %10.3f | %s\n", $f, $d, $level);
}

// ============================================
// ДЕТАЛИ: SKIP_TP vs SKIP_SL
// ============================================
echo "\n" . str_repeat("=", 130) . "\n";
echo "  ДЕТАЛИ\n";
echo str_repeat("=", 130) . "\n\n";

echo "SKIP_TP (модель пропустила, но был TP):\n";
printf("%-20s | %6s | %7s | %7s | %7s | %7s | %7s | %7s | %7s\n",
    "Time", "ml", "RSI", "ATR%", "SMA20", "SMA50", "volR", "pos20", "cum10");
echo str_repeat("-", 120) . "\n";
foreach ($skip_tp as $r) {
    printf("%-20s | %6.3f | %7.1f | %7.3f | %+7.2f | %+7.2f | %7.2f | %7.2f | %+7.2f\n",
        $r['time'], $r['ml_prob'],
        $r['rsi'] ?? 0, $r['atr_rel'] ?? 0,
        $r['sma20_ratio'] ?? 0, $r['sma50_ratio'] ?? 0,
        $r['vol_ratio'] ?? 0, $r['pos_max20'] ?? 0,
        $r['cum10'] ?? 0
    );
}

echo "\nSKIP_SL (модель правильно отсеяла SL):\n";
printf("%-20s | %6s | %7s | %7s | %7s | %7s | %7s | %7s | %7s\n",
    "Time", "ml", "RSI", "ATR%", "SMA20", "SMA50", "volR", "pos20", "cum10");
echo str_repeat("-", 120) . "\n";
foreach ($skip_sl as $r) {
    printf("%-20s | %6.3f | %7.1f | %7.3f | %+7.2f | %+7.2f | %7.2f | %7.2f | %+7.2f\n",
        $r['time'], $r['ml_prob'],
        $r['rsi'] ?? 0, $r['atr_rel'] ?? 0,
        $r['sma20_ratio'] ?? 0, $r['sma50_ratio'] ?? 0,
        $r['vol_ratio'] ?? 0, $r['pos_max20'] ?? 0,
        $r['cum10'] ?? 0
    );
}

// ============================================
// РАСШИФРОВКА
// ============================================
echo "\n" . str_repeat("=", 100) . "\n";
echo "  РАСШИФРОВКА\n";
echo str_repeat("=", 100) . "\n\n";
echo "RSI        — индекс силы (0-100). >70 перекупленность, <30 перепроданность\n";
echo "ATR%       — волатильность в % от цены\n";
echo "SMA20      — отклонение close от SMA(20) в %\n";
echo "SMA50      — отклонение close от SMA(50) в %\n";
echo "volR       — объём / SMA(объём, 20)\n";
echo "pos20      — позиция close в диапазоне max/min за 20 свечей (0=низ, 1=верх)\n";
echo "cum10      — накопленный return за 10 свечей в %\n";
echo "Cohen's d  — >0.8 сильное, >0.5 среднее, >0.2 слабое, <0.2 нет\n";