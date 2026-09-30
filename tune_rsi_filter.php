<?php
// ============================================
// ПОДБОР ПОРОГОВ RSI-ФИЛЬТРА
// Читает ml_signals_log.csv + считает rsi/cum5/bulls10
// Перебирает пороги, считает PnL
// ============================================

set_time_limit(0);
ini_set('memory_limit', '4G');

$history_file = __DIR__ . '/history_15m.json';
$csv = __DIR__ . '/ml_signals_log.csv';
if (!file_exists($history_file)) die("❌ Нет history_15m.json\n");
if (!file_exists($csv)) die("❌ Нет $csv\n");

$h15 = json_decode(file_get_contents($history_file), true);

$fp = fopen($csv, 'r');
$header = fgetcsv($fp);
$rows = [];
while ($r = fgetcsv($fp)) {
    if (count($r) !== count($header)) continue;
    $rows[] = array_combine($header, $r);
}
fclose($fp);

function findIdx($h15, $time_str) {
    $ts = strtotime($time_str . ' UTC') * 1000;
    $lo = 0; $hi = count($h15) - 1; $found = -1;
    while ($lo <= $hi) {
        $mid = (int)(($lo + $hi) / 2);
        if ($h15[$mid][0] <= $ts) { $found = $mid; $lo = $mid + 1; }
        else { $hi = $mid - 1; }
    }
    return $found;
}

function rsi($h15, $idx, $p = 14) {
    if ($idx < $p) return 0;
    $g = 0; $l = 0;
    for ($i = $idx - $p + 1; $i <= $idx; $i++) {
        $ch = (float)$h15[$i][4] - (float)$h15[$i-1][4];
        if ($ch > 0) $g += $ch; else $l += abs($ch);
    }
    if ($l == 0) return 100;
    return 100 - (100 / (1 + ($g/$p) / ($l/$p)));
}

function cumret($h15, $idx, $p) {
    if ($idx < $p) return 0;
    $s = (float)$h15[$idx - $p][4];
    $e = (float)$h15[$idx][4];
    return $s > 0 ? ($e - $s) / $s * 100 : 0;
}

function cntBull($h15, $idx, $p) {
    if ($idx < $p - 1) return 0;
    $b = 0;
    for ($i = $idx - $p + 1; $i <= $idx; $i++) {
        if ((float)$h15[$i][4] > (float)$h15[$i][1]) $b++;
    }
    return $b;
}

// Собираем данные только для SKIP (там, где ML сказала SKIP)
$data = [];
foreach ($rows as $r) {
    if ($r['action'] !== 'SKIP') continue;

    $idx = findIdx($h15, $r['signal_time']);
    if ($idx < 50) continue;

    $data[] = [
        'result'  => $r['result'],       // TP или SL
        'pnl'     => (float)$r['pnl_pct'], // уже с учётом знака (0.23 или -0.9)
        'rsi'     => rsi($h15, $idx, 14),
        'cum5'    => cumret($h15, $idx, 5),
        'bulls10' => cntBull($h15, $idx, 10),
    ];
}

echo "SKIP-сигналов: " . count($data) . "\n";
$tp_total = count(array_filter($data, fn($d) => $d['result'] === 'TP'));
$sl_total = count($data) - $tp_total;
echo "  TP: $tp_total, SL: $sl_total\n\n";

// ============================================
// ПЕРЕБОР ПОРОГОВ
// ============================================
$rsi_range     = range(20, 40, 2);
$cum5_range    = [-1.5, -1.2, -1.0, -0.9, -0.8, -0.75, -0.7, -0.6, -0.5, -0.4];
$bulls10_range = [2, 2.5, 3, 3.5, 4, 4.5, 5];

$results = [];

foreach ($rsi_range as $rsi_min) {
    foreach ($cum5_range as $cum5_min) {
        foreach ($bulls10_range as $b_min) {
            $taken_tp = 0;
            $taken_sl = 0;
            $pnl = 0.0;

            foreach ($data as $d) {
                $pass = ($d['rsi'] > $rsi_min)
                     && ($d['cum5'] > $cum5_min)
                     && ($d['bulls10'] > $b_min);

                if ($pass) {
                    $pnl += $d['pnl'];
                    if ($d['result'] === 'TP') $taken_tp++;
                    else $taken_sl++;
                }
            }

            $total = $taken_tp + $taken_sl;
            if ($total < 5) continue; // слишком мало

            $wr = $total > 0 ? ($taken_tp / $total) * 100 : 0;

            $results[] = [
                'rsi_min'     => $rsi_min,
                'cum5_min'    => $cum5_min,
                'bulls10_min' => $b_min,
                'taken'       => $total,
                'tp'          => $taken_tp,
                'sl'          => $taken_sl,
                'wr'          => $wr,
                'pnl'         => $pnl,
            ];
        }
    }
}

// Сортируем по PnL (по убыванию)
usort($results, fn($a, $b) => $b['pnl'] <=> $a['pnl']);

// ============================================
// ВЫВОД ТОП-20
// ============================================
echo str_repeat("=", 120) . "\n";
echo "  ТОП-20 ПРАВИЛ ПО PnL (на SKIP-сигналах)\n";
echo str_repeat("=", 120) . "\n\n";

printf("%-8s | %-10s | %-10s | %6s | %5s | %5s | %8s | %10s\n",
    "RSI>", "cum5>", "bulls10>", "взято", "TP", "SL", "winrate", "PnL, %");
echo str_repeat("-", 100) . "\n";

$shown = 0;
foreach ($results as $r) {
    if ($shown >= 20) break;
    printf("%-8.0f | %-10.2f | %-10.1f | %6d | %5d | %5d | %7.1f%% | %+10.2f\n",
        $r['rsi_min'], $r['cum5_min'], $r['bulls10_min'],
        $r['taken'], $r['tp'], $r['sl'], $r['wr'], $r['pnl']
    );
    $shown++;
}

// ============================================
// ЛУЧШЕЕ ПРАВИЛО (по PnL)
// ============================================
if (!empty($results)) {
    $best = $results[0];
    echo "\n" . str_repeat("=", 120) . "\n";
    echo "  ЛУЧШЕЕ ПРАВИЛО\n";
    echo str_repeat("=", 120) . "\n\n";
    echo "  RSI > {$best['rsi_min']}\n";
    echo "  cum5 > {$best['cum5_min']}\n";
    echo "  bulls10 > {$best['bulls10_min']}\n\n";
    echo "  Поймает: {$best['taken']} SKIP-сигналов\n";
    echo "  Из них TP: {$best['tp']}, SL: {$best['sl']}\n";
    echo "  Winrate: " . round($best['wr'], 1) . "%\n";
    echo "  PnL: " . round($best['pnl'], 2) . "%\n\n";
}

// ============================================
// ТОП-5 ПО КОЛИЧЕСТВУ TP (если хочешь "больше TP")
// ============================================
usort($results, fn($a, $b) => $b['tp'] <=> $a['tp']);

echo str_repeat("=", 120) . "\n";
echo "  ТОП-5 ПРАВИЛ ПО КОЛИЧЕСТВУ TP (если хочешь больше TP)\n";
echo str_repeat("=", 120) . "\n\n";

printf("%-8s | %-10s | %-10s | %6s | %5s | %5s | %8s | %10s\n",
    "RSI>", "cum5>", "bulls10>", "взято", "TP", "SL", "winrate", "PnL, %");
echo str_repeat("-", 100) . "\n";

$shown = 0;
foreach ($results as $r) {
    if ($shown >= 5) break;
    printf("%-8.0f | %-10.2f | %-10.1f | %6d | %5d | %5d | %7.1f%% | %+10.2f\n",
        $r['rsi_min'], $r['cum5_min'], $r['bulls10_min'],
        $r['taken'], $r['tp'], $r['sl'], $r['wr'], $r['pnl']
    );
    $shown++;
}