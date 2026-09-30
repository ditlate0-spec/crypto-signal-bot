<?php
// ============================================
// CHECK_INDICATORS.PHP
// Независимая проверка индикаторов по истории 15m.
// Вход: JSON с {"signal_time": "YYYY-MM-DD HH:MM:SS"}
// Выход: JSON с rsi, cum5, bulls10, bb_pct_b, rsi_decision
// ============================================

// ============================================
// ПОРОГИ
// ============================================
$RSI_MIN      = 15;      // RSI(7) должен быть больше
$CUM5_MIN     = -1.1;   // cum5 > (падение не больше 1.6%)
$CUM5_MAX     = -0.30;   // cum5 < (падение должно быть)
$BULLS10_MIN  = 1.5;     // минимум 3 бычьи свечи из 10

$BB_PCT_B_MIN = -0.15;    // bb_pct_b > (не на нижней полосе)
$BB_PCT_B_MAX = 0.80;    // bb_pct_b < (не на верхней полосе)

// ============================================
// ЗАГРУЗКА ИСТОРИИ (кэш)
// ============================================
static $h15 = null;
if ($h15 === null) {
    $history_file = __DIR__ . '/history_15m.json';
    if (!file_exists($history_file)) {
        echo json_encode(['error' => 'history_15m.json not found']);
        exit(1);
    }
    $h15 = json_decode(file_get_contents($history_file), true);
    if (empty($h15)) {
        echo json_encode(['error' => 'history_15m.json empty']);
        exit(1);
    }
}

// ============================================
// ФУНКЦИИ
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

// RSI с настраиваемым периодом
function calcRsi($h15, $idx, $period = 7) {
    if ($idx < $period) return null;
    $gains = 0; $losses = 0;
    for ($i = $idx - $period + 1; $i <= $idx; $i++) {
        $change = (float)$h15[$i][4] - (float)$h15[$i-1][4];
        if ($change > 0) $gains += $change;
        else $losses += abs($change);
    }
    $avgGain = $gains / $period;
    $avgLoss = $losses / $period;
    if ($avgLoss == 0) return 100.0;
    return 100.0 - (100.0 / (1.0 + $avgGain / $avgLoss));
}

// cum5 — накопленный return за 5 свечей
function calcCumRet($h15, $idx, $period) {
    if ($idx < $period) return null;
    $start = (float)$h15[$idx - $period][4];
    $end   = (float)$h15[$idx][4];
    return $start > 0 ? ($end - $start) / $start * 100 : 0;
}

// bulls10 — сколько бычьих свечей из 10
function calcBulls($h15, $idx, $period) {
    if ($idx < $period - 1) return null;
    $bulls = 0;
    for ($i = $idx - $period + 1; $i <= $idx; $i++) {
        if ((float)$h15[$i][4] > (float)$h15[$i][1]) $bulls++;
    }
    return $bulls;
}

// bb_pct_b — позиция close в полосах Боллинджера
function calcBBPctB($h15, $idx, $period = 20, $mult = 2.0) {
    if ($idx < $period - 1) return null;

    // SMA
    $sum = 0;
    for ($i = $idx - $period + 1; $i <= $idx; $i++) {
        $sum += (float)$h15[$i][4];
    }
    $sma = $sum / $period;

    // Std dev
    $var = 0;
    for ($i = $idx - $period + 1; $i <= $idx; $i++) {
        $var += ((float)$h15[$i][4] - $sma) ** 2;
    }
    $std = sqrt($var / $period);

    $upper = $sma + $mult * $std;
    $lower = $sma - $mult * $std;

    $range = $upper - $lower;
    if ($range <= 0) return 0.5;

    $close = (float)$h15[$idx][4];
    return ($close - $lower) / $range;
}

// ============================================
// MAIN
// ============================================
$input_path  = $argv[1] ?? null;
$output_path = $argv[2] ?? null;

if (!$input_path || !$output_path) {
    fwrite(STDERR, "Usage: php check_indicators.php input.json output.json\n");
    exit(1);
}

if (!file_exists($input_path)) {
    file_put_contents($output_path, json_encode(['error' => 'input not found']));
    exit(1);
}

$data = json_decode(file_get_contents($input_path), true);
if (!$data || !isset($data['signal_time'])) {
    file_put_contents($output_path, json_encode(['error' => 'no signal_time']));
    exit(1);
}

$signal_time = $data['signal_time'];
$idx = findCandleIdx($h15, $signal_time);

if ($idx < 50) {
    file_put_contents($output_path, json_encode([
        'error' => 'candle not found or too early',
        'idx' => $idx,
    ]));
    exit(0);
}

// ============================================
// СЧИТАЕМ ИНДИКАТОРЫ
// ============================================
$rsi      = calcRsi($h15, $idx, 7);       // RSI(7) — быстрый
$cum5     = calcCumRet($h15, $idx, 5);    // накопленный return за 5 свечей
$bulls10  = calcBulls($h15, $idx, 10);    // бычьих свечей за 10
$bb_pct_b = calcBBPctB($h15, $idx, 20, 2.0);  // позиция в Bollinger Bands

// ============================================
// РЕШЕНИЕ
// ============================================
$rsi_decision = 'SKIP';
$conditions = [];

if ($rsi !== null && $cum5 !== null && $bulls10 !== null && $bb_pct_b !== null) {
    $cond_rsi    = ($rsi > $RSI_MIN);
    $cond_cum5_min = ($cum5 > $CUM5_MIN);
    $cond_cum5_max = ($cum5 < $CUM5_MAX);
    $cond_bulls  = ($bulls10 > $BULLS10_MIN);
    $cond_bb_min = ($bb_pct_b > $BB_PCT_B_MIN);
    $cond_bb_max = ($bb_pct_b < $BB_PCT_B_MAX);

    $conditions = [
        'rsi_ok'       => $cond_rsi,
        'cum5_min_ok'  => $cond_cum5_min,
        'cum5_max_ok'  => $cond_cum5_max,
        'bulls_ok'     => $cond_bulls,
        'bb_min_ok'    => $cond_bb_min,
        'bb_max_ok'    => $cond_bb_max,
    ];

    if ($cond_rsi && $cond_cum5_min && $cond_cum5_max 
        && $cond_bulls && $cond_bb_min && $cond_bb_max) {
        $rsi_decision = 'TAKE';
    }
}

$result = [
    'signal_time'  => $signal_time,
    'rsi'          => $rsi !== null ? round($rsi, 2) : null,
    'cum5'         => $cum5 !== null ? round($cum5, 4) : null,
    'bulls10'      => $bulls10,
    'bb_pct_b'     => $bb_pct_b !== null ? round($bb_pct_b, 4) : null,
    'rsi_decision' => $rsi_decision,
    'conditions'   => $conditions,
    'thresholds'   => [
        'rsi_min'      => $RSI_MIN,
        'cum5_min'     => $CUM5_MIN,
        'cum5_max'     => $CUM5_MAX,
        'bulls10_min'  => $BULLS10_MIN,
        'bb_pct_b_min' => $BB_PCT_B_MIN,
        'bb_pct_b_max' => $BB_PCT_B_MAX,
        'rsi_period'   => 7,
        'bb_period'    => 20,
        'bb_mult'      => 2.0,
    ],
];

file_put_contents($output_path, json_encode($result, JSON_UNESCAPED_UNICODE));
echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";