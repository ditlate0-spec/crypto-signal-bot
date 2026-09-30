<?php
// ============================================
// АНАЛИЗ ПРОПУЩЕННЫХ СИГНАЛОВ
// ============================================

$csv = __DIR__ . '/ml_signals_log.csv';
if (!file_exists($csv)) die("❌ Нет $csv\n");

$fp = fopen($csv, 'r');
$header = fgetcsv($fp);

$rows = [];
while ($r = fgetcsv($fp)) {
    $rows[] = array_combine($header, $r);
}
fclose($fp);

echo "Всего сигналов: " . count($rows) . "\n\n";

// Группы
$groups = [
    'TAKE_TP' => [],
    'TAKE_SL' => [],
    'SKIP_TP' => [],
    'SKIP_SL' => [],
];

foreach ($rows as $r) {
    if ($r['action'] === 'TAKE') {
        $g = $r['result'] === 'TP' ? 'TAKE_TP' : 'TAKE_SL';
    } else {
        $g = $r['result'] === 'TP' ? 'SKIP_TP' : 'SKIP_SL';
    }
    $groups[$g][] = $r;
}

echo "СВОДКА:\n";
foreach ($groups as $name => $arr) {
    echo "  $name: " . count($arr) . "\n";
}
echo "\n";

// ============================================
// ВЫЧИСЛЯЕМ ПРИЗНАКИ
// ============================================
function extractFeatures($r) {
    $c1_o = (float)$r['c1_open']; $c1_h = (float)$r['c1_high']; $c1_l = (float)$r['c1_low']; $c1_c = (float)$r['c1_close']; $c1_v = (float)$r['c1_vol'];
    $c2_o = (float)$r['c2_open']; $c2_h = (float)$r['c2_high']; $c2_l = (float)$r['c2_low']; $c2_c = (float)$r['c2_close']; $c2_v = (float)$r['c2_vol'];
    $c3_o = (float)$r['c3_open']; $c3_h = (float)$r['c3_high']; $c3_l = (float)$r['c3_low']; $c3_c = (float)$r['c3_close']; $c3_v = (float)$r['c3_vol'];
    $entry = (float)$r['entry_price'];

    $ret1 = ($c1_c - $c1_o) / $c1_o * 100;
    $ret2 = ($c2_c - $c2_o) / $c2_o * 100;
    $ret3 = ($c3_c - $c3_o) / $c3_o * 100;

    $rng1 = $c1_o > 0 ? ($c1_h - $c1_l) / $c1_o * 100 : 0;
    $rng2 = $c2_o > 0 ? ($c2_h - $c2_l) / $c2_o * 100 : 0;
    $rng3 = $c3_o > 0 ? ($c3_h - $c3_l) / $c3_o * 100 : 0;

    $vol_rel = $c1_v > 0 ? $c3_v / $c1_v : 0;
    $entry_vs_c3 = $c3_c > 0 ? ($entry - $c3_c) / $c3_c * 100 : 0;

    // Направление свечей
    $dir1 = $c1_c > $c1_o ? 1 : -1;
    $dir2 = $c2_c > $c2_o ? 1 : -1;
    $dir3 = $c3_c > $c3_o ? 1 : -1;
    $dir_sum = $dir1 + $dir2 + $dir3;

    // Позиция close в диапазоне 3-й свечи
    $rng3_abs = $c3_h - $c3_l;
    $c3_pos = $rng3_abs > 0 ? ($c3_c - $c3_l) / $rng3_abs : 0.5;

    // Верхние / нижние тени
    $upper_wick3 = $rng3_abs > 0 ? ($c3_h - max($c3_o, $c3_c)) / $rng3_abs : 0;
    $lower_wick3 = $rng3_abs > 0 ? (min($c3_o, $c3_c) - $c3_l) / $rng3_abs : 0;

    return [
        'ret1' => $ret1,
        'ret2' => $ret2,
        'ret3' => $ret3,
        'rng1' => $rng1,
        'rng2' => $rng2,
        'rng3' => $rng3,
        'vol_rel' => $vol_rel,
        'entry_vs_c3' => $entry_vs_c3,
        'dir_sum' => $dir_sum,
        'c3_pos' => $c3_pos,
        'upper_wick3' => $upper_wick3,
        'lower_wick3' => $lower_wick3,
        'hour' => (int)date('G', strtotime($r['signal_time'])),
        'dow' => (int)date('N', strtotime($r['signal_time'])),
        'candles' => (int)$r['candles'],
    ];
}

// ============================================
// СРАВНЕНИЕ SKIP_TP vs SKIP_SL
// ============================================
$features = ['ret1','ret2','ret3','rng1','rng2','rng3','vol_rel','entry_vs_c3','dir_sum','c3_pos','upper_wick3','lower_wick3','hour','dow','candles'];

// Собираем значения
$data = [];
foreach ($groups as $name => $arr) {
    $data[$name] = [];
    foreach ($features as $f) $data[$name][$f] = [];
    foreach ($arr as $r) {
        $feats = extractFeatures($r);
        foreach ($features as $f) $data[$name][$f][] = $feats[$f];
    }
}

// Считаем статистику
echo str_repeat("=", 110) . "\n";
echo "  СРАВНЕНИЕ ПРИЗНАКОВ\n";
echo str_repeat("=", 110) . "\n\n";

printf("%-15s | %12s | %12s | %12s | %10s\n",
    "Признак", "SKIP_TP", "SKIP_SL", "TAKE_TP", "Cohen's d");
echo str_repeat("-", 85) . "\n";

$cohens = [];
foreach ($features as $f) {
    $means = [];
    $stds = [];
    foreach (['SKIP_TP', 'SKIP_SL', 'TAKE_TP'] as $g) {
        $vals = $data[$g][$f];
        if (count($vals) === 0) { $means[$g] = 0; $stds[$g] = 0; continue; }
        $means[$g] = array_sum($vals) / count($vals);
        $variance = array_sum(array_map(fn($v) => ($v - $means[$g]) ** 2, $vals)) / count($vals);
        $stds[$g] = sqrt($variance);
    }

    // Cohen's d между SKIP_TP и SKIP_SL
    $pooled = sqrt(($stds['SKIP_TP'] ** 2 + $stds['SKIP_SL'] ** 2) / 2);
    $d = $pooled > 0 ? abs($means['SKIP_TP'] - $means['SKIP_SL']) / $pooled : 0;

    $cohens[$f] = $d;

    printf("%-15s | %12.4f | %12.4f | %12.4f | %10.3f\n",
        $f, $means['SKIP_TP'], $means['SKIP_SL'], $means['TAKE_TP'], $d);
}

echo "\n";
echo str_repeat("=", 110) . "\n";
echo "  ТОП ПРИЗНАКОВ ПО РАЗЛИЧИЮ (SKIP_TP vs SKIP_SL)\n";
echo str_repeat("=", 110) . "\n\n";

arsort($cohens);
printf("%-15s | %10s | %s\n", "Признак", "Cohen's d", "Значение");
echo str_repeat("-", 60) . "\n";
foreach (array_slice($cohens, 0, 10, true) as $f => $d) {
    $level = $d > 0.8 ? 'СИЛЬНОЕ' : ($d > 0.5 ? 'СРЕДНЕЕ' : ($d > 0.2 ? 'слабое' : 'нет'));
    printf("%-15s | %10.3f | %s\n", $f, $d, $level);
}

// ============================================
// РАСПРЕДЕЛЕНИЕ ПО СТРАТЕГИЯМ ВХОДА
// ============================================
echo "\n" . str_repeat("=", 110) . "\n";
echo "  РАСПРЕДЕЛЕНИЕ ПО ЧАСАМ (UTC)\n";
echo str_repeat("=", 110) . "\n\n";

$hours_skip_tp = array_fill(0, 24, 0);
$hours_skip_sl = array_fill(0, 24, 0);
$hours_take_tp = array_fill(0, 24, 0);

foreach ($data['SKIP_TP']['hour'] as $h) $hours_skip_tp[$h]++;
foreach ($data['SKIP_SL']['hour'] as $h) $hours_skip_sl[$h]++;
foreach ($data['TAKE_TP']['hour'] as $h) $hours_take_tp[$h]++;

printf("%-6s | %10s | %10s | %10s\n", "Час", "SKIP_TP", "SKIP_SL", "TAKE_TP");
echo str_repeat("-", 45) . "\n";
for ($h = 0; $h < 24; $h++) {
    if ($hours_skip_tp[$h] + $hours_skip_sl[$h] + $hours_take_tp[$h] == 0) continue;
    printf("%02d:00 | %10d | %10d | %10d\n", $h, $hours_skip_tp[$h], $hours_skip_sl[$h], $hours_take_tp[$h]);
}