<?php
// ============================================
// ТОЛЬКО ПРОВЕРКА SL-МОДЕЛИ.
// Прогон всех сигналов бота через модель.
// Вывод: сколько взято, winrate, expectancy.
// ============================================

ob_implicit_flush(true);
if (ob_get_level()) ob_end_flush();

set_time_limit(0);
ini_set('memory_limit', '4G');

// ============================================
// ПУТИ
// ============================================
$history_15m_btc_file = __DIR__ . '/history_15m.json';
$history_15m_eth_file = __DIR__ . '/history_15m_eth.json';
$history_1h_file      = __DIR__ . '/history_1h.json';
$history_1d_file      = __DIR__ . '/history_1d.json';

$python_script_sl     = __DIR__ . '/coin/predict_one_sl.py';
$python_exe           = 'python3';

// ============================================
// ПОРОГИ СТРАТЕГИИ
// ============================================
$BASE = [
    'THRESH_DAY_OTH'     => 20,
    'THRESH_DAY_OLD'     => 50,
    'THRESH_HOUR_OTH'    => 10,
    'THRESH_HOUR_OLD'    => 40,
    'THRESH_BTC_15M_OTH' => 30,
    'THRESH_BTC_15M_OLD' => 55,
    'THRESH_ETH_15M_OTH' => 0,
    'THRESH_ETH_15M_OLD' => 70,
];

$SL_PCT      = 0.5;
$TP_PCT      = -0.22;
$MAX_CANDLES = 70;

$DEFAULT_SL_THRESHOLD = 0.50;

// Метаданные символа — категориальные фичи для CatBoost
$SYMBOL_META = [
    'symbol'    => 'BTCUSDT',
    'bot_type'  => 'mixed',
    'timeframe' => '15m',
];

// ============================================
// ЗАГРУЗКА
// ============================================
foreach ([$history_15m_btc_file, $history_15m_eth_file, $history_1h_file, $history_1d_file] as $f) {
    if (!file_exists($f)) die("❌ Не найден: $f\n");
}

$h15_btc = json_decode(file_get_contents($history_15m_btc_file), true);
$h15_eth = json_decode(file_get_contents($history_15m_eth_file), true);
$h1h     = json_decode(file_get_contents($history_1h_file), true);
$h1d     = json_decode(file_get_contents($history_1d_file), true);

if (empty($h15_btc) || empty($h15_eth) || empty($h1h) || empty($h1d)) die("❌ Пустые данные\n");

echo "✅ 15m BTC: " . count($h15_btc) . " свечей\n";
echo "✅ 15m ETH: " . count($h15_eth) . " свечей\n";
echo "✅ 1h BTC:  " . count($h1h) . " свечей\n";
echo "✅ 1d BTC:  " . count($h1d) . " свечей\n\n";

// ============================================
// ФУНКЦИИ KF
// ============================================
// ... СКОПИРУЙ ИЗ backtest_ML.php:
//     calcKF_oth_1d, calcKF_old_1d, calcKF_oth_1h, calcKF_old_1h,
//     calcKF_oth_15m, calcKF_old_15m, findCandleAt, simulateShort
// ... (не меняются)

// ============================================
// ВЫЗОВ SL-МОДЕЛИ
// ============================================
function callSLModel($pythonExe, $scriptPath, $c1, $c2, $c3, $entryPrice, $signalTime, $kfData, $meta = []) {
    if (!file_exists($scriptPath)) return null;

    $kfWithMeta = array_merge($kfData, $meta);

    $input = [
        'candles' => [
            ['open'=>(float)$c1[1], 'high'=>(float)$c1[2], 'low'=>(float)$c1[3], 'close'=>(float)$c1[4], 'vol'=>(float)$c1[5]],
            ['open'=>(float)$c2[1], 'high'=>(float)$c2[2], 'low'=>(float)$c2[3], 'close'=>(float)$c2[4], 'vol'=>(float)$c2[5]],
            ['open'=>(float)$c3[1], 'high'=>(float)$c3[2], 'low'=>(float)$c3[3], 'close'=>(float)$c3[4], 'vol'=>(float)$c3[5]],
        ],
        'entry_price' => $entryPrice,
        'signal_time' => $signalTime,
        'kf_data'     => $kfWithMeta,
    ];

    $tmpIn  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bt_in_'  . uniqid() . '.json';
    $tmpOut = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bt_out_' . uniqid() . '.json';

    file_put_contents($tmpIn, json_encode($input));

    $cmd = escapeshellarg($pythonExe)
         . ' ' . escapeshellarg($scriptPath)
         . ' ' . escapeshellarg($tmpIn)
         . ' ' . escapeshellarg($tmpOut)
         . ' 2>&1';

    exec($cmd, $output, $ret);

    $result = null;
    if ($ret === 0 && file_exists($tmpOut)) {
        $raw = file_get_contents($tmpOut);
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['probability_sl'])) {
            $result = [
                'probability' => (float)$decoded['probability_sl'],
                'threshold'   => (float)($decoded['threshold_p_sl'] ?? $GLOBALS['DEFAULT_SL_THRESHOLD']),
                'decision'    => $decoded['decision'] ?? 'SKIP',
            ];
        }
    }

    @unlink($tmpIn);
    @unlink($tmpOut);
    return $result;
}

// ============================================
// ПРЕДРАСЧЁТ KF ДЛЯ ВСЕХ СВЕЧЕЙ
// ============================================
// ... СКОПИРУЙ ИЗ backtest_ML.php:
//     echo "🔧 Предрасчёт KF...";
//     $N = count($h15_btc);
//     $btc_15m_oth_arr = array_fill(...);
//     ... весь цикл for ($i = 2; $i < $N; $i++) { ... }
//     echo "✅ KF предрасчитан\n\n";

// ============================================
// ОСНОВНОЙ ПРОГОН — ТОЛЬКО МОДЕЛЬ
// ============================================
$THRESH_DAY_OTH     = $BASE['THRESH_DAY_OTH'];
$THRESH_DAY_OLD     = $BASE['THRESH_DAY_OLD'];
$THRESH_HOUR_OTH    = $BASE['THRESH_HOUR_OTH'];
$THRESH_HOUR_OLD    = $BASE['THRESH_HOUR_OLD'];
$THRESH_BTC_15M_OTH = $BASE['THRESH_BTC_15M_OTH'];
$THRESH_BTC_15M_OLD = $BASE['THRESH_BTC_15M_OLD'];
$THRESH_ETH_15M_OTH = $BASE['THRESH_ETH_15M_OTH'];
$THRESH_ETH_15M_OLD = $BASE['THRESH_ETH_15M_OLD'];

$taken = 0; $filtered = 0;
$m_tp = 0; $m_sl = 0; $m_to = 0;
$m_pnl = 0.0;

$next_available_idx = 2;
$last_sl_thr = $DEFAULT_SL_THRESHOLD;

echo "🔍 Прогон сигналов через SL-модель...\n";
echo str_repeat("=", 80) . "\n";
echo " Дата                | Result  | PnL      | SL-prob  decision\n";
echo str_repeat("=", 80) . "\n";

$t_start = microtime(true);

for ($i = 2; $i < $N - 2; $i++) {
    if ($i < $next_available_idx) continue;

    $btc_day_strong  = ($btc_1d_oth_arr[$i] > $THRESH_DAY_OTH && $btc_1d_old_arr[$i] > $THRESH_DAY_OLD);
    $btc_hour_strong = ($btc_1h_oth_arr[$i] > $THRESH_HOUR_OTH && $btc_1h_old_arr[$i] > $THRESH_HOUR_OLD);
    $bots_btc_15m_ready = ($btc_15m_oth_arr[$i] > $THRESH_BTC_15M_OTH && $btc_15m_old_arr[$i] > $THRESH_BTC_15M_OLD);
    $bots_eth_15m_ready = ($eth_15m_oth_arr[$i] > $THRESH_ETH_15M_OTH && $eth_15m_old_arr[$i] > $THRESH_ETH_15M_OLD);
    $trigger_15m_ready  = ($bots_btc_15m_ready && $bots_eth_15m_ready);

    $verdict = false;
    if ($btc_day_strong && $btc_hour_strong)         $verdict = true;
    elseif ($btc_day_strong && $trigger_15m_ready)    $verdict = true;
    elseif ($btc_hour_strong && $trigger_15m_ready)   $verdict = true;

    if (!$verdict) continue;

    $entry_idx = $i + 1;
    if ($entry_idx >= $N - 1) break;

    $entry_price = (float)$h15_btc[$entry_idx][1];

    $sim = simulateShort($h15_btc, $entry_idx + 1, $entry_price, $SL_PCT, $TP_PCT, $MAX_CANDLES);

    $c1 = $h15_btc[$i - 2];
    $c2 = $h15_btc[$i - 1];
    $c3 = $h15_btc[$i];

    $kfForModel = [
        'kf'             => $btc_15m_old_arr[$i],
        'kf_btc_15m_oth' => $btc_15m_oth_arr[$i],
        'kf_eth_15m_oth' => $eth_15m_oth_arr[$i],
        'kf_eth_15m_old' => $eth_15m_old_arr[$i],
        'kf_btc_1h_oth'  => $btc_1h_oth_arr[$i],
        'kf_btc_1h_old'  => $btc_1h_old_arr[$i],
        'kf_btc_1d_oth'  => $btc_1d_oth_arr[$i],
        'kf_btc_1d_old'  => $btc_1d_old_arr[$i],
    ];

    $signal_time_str = gmdate('Y-m-d H:i:s', (int)($h15_btc[$i][0] / 1000));

    $sl_res = callSLModel($python_exe, $python_script_sl, $c1, $c2, $c3, $entry_price, $signal_time_str, $kfForModel, $SYMBOL_META);
    $sl_prob = $sl_res['probability'] ?? null;
    $sl_thr  = $sl_res['threshold']   ?? $DEFAULT_SL_THRESHOLD;
    $last_sl_thr = $sl_thr;

    $sl_approved = ($sl_prob !== null && $sl_prob < $sl_thr);
    $sl_pct = $sl_prob !== null ? round($sl_prob * 100, 1) . '%' : 'n/a';
    $dec = $sl_approved ? 'take' : 'skip';

    printf(" %s | %-7s | %+7.3f%% | %6s %s\n",
        $signal_time_str, $sim['result'], $sim['pnl_pct'], $sl_pct, $dec);

    if ($sl_approved) {
        $taken++;
        if ($sim['result'] === 'TP')      $m_tp++;
        elseif ($sim['result'] === 'SL')  $m_sl++;
        else                              $m_to++;
        $m_pnl += $sim['pnl_pct'];

        // Следующая сделка возможна после закрытия этой
        $next_available_idx = $entry_idx + $sim['candles'] + 1;
    } else {
        $filtered++;
        // Если сигнал отсеян, следующая свеча тоже может дать сигнал
        $next_available_idx = $entry_idx + 1;
    }
}

$elapsed = round(microtime(true) - $t_start, 2);
$total_signals = $taken + $filtered;

// ============================================
// ИТОГИ — ТОЛЬКО МОДЕЛЬ
// ============================================
echo "\n" . str_repeat("=", 80) . "\n";
echo "  РЕЗУЛЬТАТ МОДЕЛИ (за {$elapsed}с)\n";
echo str_repeat("=", 80) . "\n\n";

echo "Всего сигналов бота:  {$total_signals}\n";
echo "Взято моделью:        {$taken}\n";
echo "Отсеяно моделью:      {$filtered}\n";

if ($total_signals > 0) {
    $filtered_pct = ($filtered / $total_signals) * 100;
    echo "Отсеяно %:            " . round($filtered_pct, 1) . "%\n";
}

echo "\n";

if ($taken > 0) {
    echo "TP:                   {$m_tp}\n";
    echo "SL:                   {$m_sl}\n";
    echo "TIMEOUT:              {$m_to}\n";
    echo "\n";
    $wr = ($m_tp / $taken) * 100;
    $exp = $m_pnl / $taken;
    echo "Winrate:              " . round($wr, 2) . "%\n";
    echo "Expectancy:           " . round($exp, 4) . "%\n";
    echo "Суммарный PnL:        " . round($m_pnl, 2) . "%\n";
    echo "\nПорог модели:         P(SL) < {$last_sl_thr}\n";
} else {
    echo "⚠️  Модель не взяла ни одной сделки\n";
}

echo "\n" . str_repeat("=", 80) . "\n";