<?php
// ============================================
// ПОИСК ПАТТЕРНОВ В 3 СВЕЧАХ
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

$skip_tp = $skip_sl = $take_tp = [];
foreach ($rows as $r) {
    if ($r['action'] === 'TAKE' && $r['result'] === 'TP') $take_tp[] = $r;
    elseif ($r['action'] === 'SKIP' && $r['result'] === 'TP') $skip_tp[] = $r;
    elseif ($r['action'] === 'SKIP' && $r['result'] === 'SL') $skip_sl[] = $r;
}

echo "SKIP_TP: " . count($skip_tp) . "\n";
echo "SKIP_SL: " . count($skip_sl) . "\n";
echo "TAKE_TP: " . count($take_tp) . "\n\n";

// ============================================
// КЛАССИФИКАТОР
// ============================================
function classify($r) {
    $c1_o = (float)$r['c1_open']; $c1_h = (float)$r['c1_high']; $c1_l = (float)$r['c1_low']; $c1_c = (float)$r['c1_close'];
    $c2_o = (float)$r['c2_open']; $c2_h = (float)$r['c2_high']; $c2_l = (float)$r['c2_low']; $c2_c = (float)$r['c2_close'];
    $c3_o = (float)$r['c3_open']; $c3_h = (float)$r['c3_high']; $c3_l = (float)$r['c3_low']; $c3_c = (float)$r['c3_close'];

    // Направления (B = bull, S = bear)
    $d1 = $c1_c > $c1_o ? 'B' : 'S';
    $d2 = $c2_c > $c2_o ? 'B' : 'S';
    $d3 = $c3_c > $c3_o ? 'B' : 'S';

    // Тело к range
    $rng1 = max(0.0001, $c1_h - $c1_l);
    $rng2 = max(0.0001, $c2_h - $c2_l);
    $rng3 = max(0.0001, $c3_h - $c3_l);
    $b1 = abs($c1_c - $c1_o) / $rng1;
    $b2 = abs($c2_c - $c2_o) / $rng2;
    $b3 = abs($c3_c - $c3_o) / $rng3;

    $t1 = $b1 > 0.6 ? 'L' : ($b1 > 0.3 ? 'M' : 'S');
    $t2 = $b2 > 0.6 ? 'L' : ($b2 > 0.3 ? 'M' : 'S');
    $t3 = $b3 > 0.6 ? 'L' : ($b3 > 0.3 ? 'M' : 'S');

    // Тени 3-й свечи
    $up3 = ($c3_h - max($c3_o, $c3_c)) / $rng3;
    $lo3 = (min($c3_o, $c3_c) - $c3_l) / $rng3;
    $w3 = 'N';
    if ($up3 > 0.4 && $lo3 > 0.4) $w3 = 'D';
    elseif ($up3 > 0.4) $w3 = 'U';
    elseif ($lo3 > 0.4) $w3 = 'L';

    // Позиция close 3-й свечи
    $pos3_val = ($c3_c - $c3_l) / $rng3;
    $pos3 = $pos3_val > 0.7 ? 'H' : ($pos3_val < 0.3 ? 'L' : 'M');

    // Расширение диапазона
    $exp_val = $rng3 / $rng1;
    $exp = $exp_val > 1.5 ? 'X' : ($exp_val > 0.8 ? 'E' : 'C');

    return "{$d1}{$d2}{$d3}|{$t1}{$t2}{$t3}|{$w3}{$pos3}{$exp}";
}

// ============================================
// СЧИТАЕМ ПАТТЕРНЫ
// ============================================
$p_tp = [];
$p_sl = [];
$p_take = [];

foreach ($skip_tp as $r) { $k = classify($r); $p_tp[$k] = ($p_tp[$k] ?? 0) + 1; }
foreach ($skip_sl as $r) { $k = classify($r); $p_sl[$k] = ($p_sl[$k] ?? 0) + 1; }
foreach ($take_tp as $r) { $k = classify($r); $p_take[$k] = ($p_take[$k] ?? 0) + 1; }

// ============================================
// ВСЕ ПАТТЕРНЫ
// ============================================
$all = array_unique(array_merge(array_keys($p_tp), array_keys($p_sl), array_keys($p_take)));
sort($all);

echo str_repeat("=", 110) . "\n";
echo "  ВСЕ ПАТТЕРНЫ (формат: DIR|BODY|WICK+POS+EXP)\n";
echo str_repeat("=", 110) . "\n\n";
printf("%-30s | %10s | %10s | %10s\n", "Паттерн", "SKIP_TP", "SKIP_SL", "TAKE_TP");
echo str_repeat("-", 70) . "\n";
foreach ($all as $k) {
    printf("%-30s | %10d | %10d | %10d\n",
        $k,
        $p_tp[$k] ?? 0,
        $p_sl[$k] ?? 0,
        $p_take[$k] ?? 0
    );
}

// ============================================
// ТОЛЬКО У SKIP_TP (нет в SKIP_SL)
// ============================================
echo "\n" . str_repeat("=", 110) . "\n";
echo "  ПАТТЕРНЫ ЕСТЬ У SKIP_TP, НЕТ У SKIP_SL — КАНДИДАТЫ\n";
echo str_repeat("=", 110) . "\n\n";
printf("%-30s | %10s | %10s\n", "Паттерн", "SKIP_TP", "TAKE_TP");
echo str_repeat("-", 60) . "\n";

$pure = [];
foreach ($p_tp as $k => $cnt) {
    if (!isset($p_sl[$k])) {
        $pure[$k] = ['tp' => $cnt, 'take' => $p_take[$k] ?? 0];
    }
}
arsort($pure);
foreach ($pure as $k => $info) {
    printf("%-30s | %10d | %10d\n", $k, $info['tp'], $info['take']);
}

// ============================================
// ТОЛЬКО У SKIP_SL
// ============================================
echo "\n" . str_repeat("=", 110) . "\n";
echo "  ПАТТЕРНЫ ЕСТЬ У SKIP_SL, НЕТ У SKIP_TP\n";
echo str_repeat("=", 110) . "\n\n";
printf("%-30s | %10s\n", "Паттерн", "SKIP_SL");
echo str_repeat("-", 50) . "\n";
foreach ($p_sl as $k => $cnt) {
    if (!isset($p_tp[$k])) {
        printf("%-30s | %10d\n", $k, $cnt);
    }
}

// ============================================
// ДЕТАЛИ SKIP_TP
// ============================================
echo "\n" . str_repeat("=", 110) . "\n";
echo "  ВСЕ 44 SKIP_TP — с паттернами\n";
echo str_repeat("=", 110) . "\n\n";
printf("%-20s | %-28s | %8s | %8s\n", "Time", "Pattern", "ml_prob", "candles");
echo str_repeat("-", 80) . "\n";
foreach ($skip_tp as $r) {
    printf("%-20s | %-28s | %8.4f | %8d\n",
        $r['signal_time'],
        classify($r),
        (float)$r['ml_prob'],
        (int)$r['candles']
    );
}

// ============================================
// ДЕТАЛИ SKIP_SL
// ============================================
echo "\n" . str_repeat("=", 110) . "\n";
echo "  ВСЕ " . count($skip_sl) . " SKIP_SL — с паттернами\n";
echo str_repeat("=", 110) . "\n\n";
printf("%-20s | %-28s | %8s | %8s\n", "Time", "Pattern", "ml_prob", "candles");
echo str_repeat("-", 80) . "\n";
foreach ($skip_sl as $r) {
    printf("%-20s | %-28s | %8.4f | %8d\n",
        $r['signal_time'],
        classify($r),
        (float)$r['ml_prob'],
        (int)$r['candles']
    );
}

// ============================================
// РАСШИФРОВКА
// ============================================
echo "\n" . str_repeat("=", 110) . "\n";
echo "  КАК ЧИТАТЬ ПАТТЕРН\n";
echo str_repeat("=", 110) . "\n\n";
echo "Пример: SSB|MLS|UHX\n";
echo "  SSB  — направления 3 свечей: 1-я=S(медвежья), 2-я=S, 3-я=B(бычья)\n";
echo "  MLS  — тела свечей: 1-я=M(среднее), 2-я=L(длинное), 3-я=S(короткое)\n";
echo "  U    — верхняя тень у 3-й свечи\n";
echo "  H    — close 3-й свечи вверху диапазона\n";
echo "  X    — расширение диапазона (3-я свеча > 1.5× от 1-й)\n\n";
echo "Тела: L=большое, M=среднее, S=маленькое\n";
echo "Тени: N=нет, U=верхняя, L=нижняя, D=двойная\n";
echo "Позиция close: H=верх, M=середина, L=низ\n";
echo "Расширение: X=расширение, E=равно, C=сжатие\n";