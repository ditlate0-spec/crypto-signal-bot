<?php
// ============================================
// CHECK_INDICATORS.PHP
// Проверка ТОЛЬКО RSI по истории 15m.
// Вход:  JSON {"signal_time": "YYYY-MM-DD HH:MM:SS"}
// Выход: JSON {"rsi": ..., "rsi_decision": "TAKE|SKIP"}
// ============================================

// ============================================
// ПОРОГ RSI
// ============================================
$RSI_MIN = 15;   // RSI(7) должен быть больше

// ============================================
// ЗАГРУЗКА ИСТОРИИ
// ============================================
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
// RSI
// ============================================
$rsi = calcRsi($h15, $idx, 7);

$rsi_decision = 'SKIP';
if ($rsi !== null && $rsi > $RSI_MIN) {
    $rsi_decision = 'TAKE';
}

$result = [
    'signal_time'  => $signal_time,
    'rsi'          => $rsi !== null ? round($rsi, 2) : null,
    'rsi_decision' => $rsi_decision,
    'thresholds'   => [
        'rsi_min'    => $RSI_MIN,
        'rsi_period' => 7,
    ],
];

file_put_contents($output_path, json_encode($result, JSON_UNESCAPED_UNICODE));
echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";