<?php
// ============================================
// БЭКТЕСТ С ML-ФИЛЬТРОМ.
// После сигнала бота вызывается predict_one.py.
// Сделка открывается, только если ML даёт TAKE (или TAKE+MAYBE).
// Жёсткий TP/SL, одна сделка за раз — как в backtest_off_ML.php.
// ============================================

ob_implicit_flush(true);
if (ob_get_level()) ob_end_flush();

set_time_limit(0);
ini_set('memory_limit', '4G');

// ============================================
// НАСТРОЙКИ ML
// ============================================
$PYTHON_BIN       = 'python3';                 // или 'python'
$PREDICT_SCRIPT   = __DIR__ . '/predict_one.py';
$ML_TMP_DIR       = __DIR__ . '/ml_tmp';
$ML_TIMEOUT_SEC   = 30;                        // таймаут на один вызов python

// Какие решения модели считать «входом»
// 'TAKE'        — только TAKE (строго)
// 'TAKE_MAYBE'  — TAKE + MAYBE
$ML_ACCEPT_MODE   = 'TAKE';

// Если python упал/недоступен:
// true  — игнорировать ML и входить (как в чистом бэктесте)
// false — пропускать сигнал
$ML_FAIL_OPEN     = false;

if (!is_dir($ML_TMP_DIR)) @mkdir($ML_TMP_DIR, 0777, true);

// ============================================
// ПУТИ К ИСТОРИИ
// ============================================
$history_15m_btc_file = __DIR__ . '/history_15m.json';
$history_15m_eth_file = __DIR__ . '/history_15m_eth.json';
$history_1h_file      = __DIR__ . '/history_1h.json';
$history_1d_file      = __DIR__ . '/history_1d.json';

// ============================================
// ПОРОГИ СТРАТЕГИИ (те же, что в backtest_off_ML.php)
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
$TP_PCT      = -0.23;
$MAX_CANDLES = 70;

// ============================================
// ЗАГРУЗКА
// ============================================
foreach ([$history_15m_btc_file, $history_15m_eth_file, $history_1h_file, $history_1d_file, $PREDICT_SCRIPT] as $f) {
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
echo "✅ 1d BTC:  " . count($h1d) . " свечей\n";
echo "🤖 ML режим: $ML_ACCEPT_MODE, fail_open=" . ($ML_FAIL_OPEN ? 'true' : 'false') . "\n\n";

// ============================================
// ФУНКЦИИ KF (скопированы 1:1 из backtest_off_ML.php)
// ============================================
function calcKF_oth_1d($cena1, $cena2, $cena3, $ob1, $ob2, $ob3, $mincena1, $mincena2, $mincena3) {
    $PrIzm1 = (($cena2 - $cena1) / $cena1) * 100;
    $PrIzm2 = (($cena3 - $cena2) / $cena2) * 100;
    $PrOb1  = (($ob2 - $ob1) / $ob1) * 100;
    $PrOb2  = (($ob3 - $ob2) / $ob2) * 100;
    $lov1   = (($mincena1 - $cena1) / $cena1) * 100;
    $lov2   = (($mincena2 - $cena2) / $cena2) * 100;
    $lov3   = (($mincena3 - $cena3) / $cena3) * 100;

    $lov2_high=-3; $lov2_low=-4; $prOb1_high=12; $kf_mid=45; $kf_add_high=65;
    $prIzm1_high=0.001; $prIzm1_low=0.1; $kf_high=8; $kf_low=20; $kf_base=18;
    $lov3_high=-20; $lov3_low=-4; $prOb2_high=10; $prOb2_mid=18;
    $kf_add_low=40; $kf_add_base=8; $prOb2_trigger=15; $prIzm2_crash=-6; $lov_crash=-1.5;

    $KF = 0;
    if ($cena1 > $cena2) {
        if ($lov2 < $lov2_high) { $KF = 0; }
        else if ($lov2 < $lov2_low) { $KF = 0; }
        else {
            if ($PrOb1 > $prOb1_high) {
                if ($PrIzm1 < $prIzm1_high) $KF = $kf_high;
                else if ($PrIzm1 < $prIzm1_low) $KF = $kf_high/2;
                else $KF = 0;
            } else if ($PrOb1 > 25) $KF = $kf_mid;
            else if ($PrOb1 < 10) $KF = $kf_low;
            else $KF = $kf_base;
        }
        if ($cena2 > $cena3) {
            if ($lov3 < $lov3_high) $KF += 5;
            else if ($lov3 < $lov3_low) $KF += 5;
            else {
                if ($PrOb2 > $prOb2_high) $KF += $kf_add_high;
                else if ($PrOb2 < $prOb2_mid) $KF += $kf_add_low;
                else $KF += $kf_add_base;

                if ($PrOb2 > $prOb2_trigger) {
                    if ($PrIzm2 < $prIzm2_crash) $KF = 0;
                    else {
                        if ($lov1 < $lov_crash) $KF -= 10;
                        if ($lov2 < $lov_crash) $KF -= 10;
                        if ($lov3 < $lov_crash) $KF -= 15;
                    }
                } else {
                    if ($lov1 < -8) $KF -= 40;
                    else if ($lov1 < -7) $KF -= 35;
                    else if ($lov1 < -6) $KF -= 30;
                    else if ($lov1 < -5) $KF -= 25;
                    else if ($lov1 < -4) $KF -= 20;
                    else {
                        if ($lov2 < -8) $KF -= 40;
                        else if ($lov2 < -7) $KF -= 35;
                        else if ($lov2 < -6) $KF -= 30;
                        else if ($lov2 < -5) $KF -= 25;
                        else if ($lov2 < -4) $KF -= 20;
                    }
                }
            }
        }
    } else { $KF = 0; }
    return $KF;
}
function calcKF_old_1d($cena1, $cena2, $cena3, $ob1, $ob2, $ob3, $mincena1, $mincena2, $mincena3) {
    return calcKF_oth_1d($cena1, $cena2, $cena3, $ob1, $ob2, $ob3, $mincena1, $mincena2, $mincena3);
}
function calcKF_oth_1h($cena1, $cena2, $cena3, $ob1, $ob2, $ob3, $mincena1, $mincena2, $mincena3) {
    $PrIzm1 = (($cena2 - $cena1) / $cena1) * 100;
    $PrIzm2 = (($cena3 - $cena2) / $cena2) * 100;
    $PrOb1  = (($ob2 - $ob1) / $ob1) * 100;
    $PrOb2  = (($ob3 - $ob2) / $ob2) * 100;
    $lov1   = (($mincena1 - $cena1) / $cena1) * 100;
    $lov2   = (($mincena2 - $cena2) / $cena2) * 100;
    $lov3   = (($mincena3 - $cena3) / $cena3) * 100;

    $lov2_high=-3; $lov2_low=0; $prOb1_high=150; $kf_mid=50; $kf_add_high=40;
    $prIzm1_high=3; $prIzm1_low=5; $kf_high=10; $kf_low=20; $kf_base=35;
    $lov3_high=-1.5; $lov3_low=-1; $prOb2_high=35; $prOb2_mid=20;
    $kf_add_low=35; $kf_add_base=45; $prOb2_trigger=20; $prIzm2_crash=-2; $lov_crash=-1;

    $KF = 0;
    if ($cena1 > $cena2) {
        if ($lov2 < $lov2_high) { $KF = 0; }
        else if ($lov2 < $lov2_low) { $KF = 0; }
        else {
            if ($PrOb1 > $prOb1_high) {
                if ($PrIzm1 < $prIzm1_high) $KF = $kf_high;
                else if ($PrIzm1 < $prIzm1_low) $KF = $kf_high/2;
                else $KF = 0;
            } else if ($PrOb1 > 25) $KF = $kf_mid;
            else if ($PrOb1 < 10) $KF = $kf_low;
            else $KF = $kf_base;
        }
        if (-0.1 < $PrIzm1 && $PrIzm1 < 0) $KF = 0;
        else if (-0.2 < $PrIzm1 && $PrIzm1 < 0) $KF = 5;

        if ($cena2 > $cena3) {
            if ($lov3 < $lov3_high) $KF += 5;
            else if ($lov3 < $lov3_low) $KF += 5;
            else {
                if ($PrOb2 > $prOb2_high) $KF += $kf_add_high;
                else if ($PrOb2 < -20) $KF += $kf_add_low;
                else if ($PrOb2 < 0) $KF += 15;
                else if ($PrOb2 < $prOb2_mid) $KF += 35;
                else $KF += $kf_add_base;

                if ($PrOb2 > $prOb2_trigger) {
                    if ($PrIzm2 < $prIzm2_crash) $KF = 0;
                    else {
                        if ($lov1 < $lov_crash) $KF -= 10;
                        if ($lov2 < $lov_crash) $KF -= 10;
                        if ($lov3 < $lov_crash) $KF -= 20;
                    }
                } else {
                    if ($lov1 < -5) $KF -= 40;
                    else if ($lov1 < -4) $KF -= 35;
                    else if ($lov1 < -3) $KF -= 30;
                    else if ($lov1 < -2) $KF -= 25;
                    else if ($lov1 < -1) $KF -= 20;
                    else {
                        if ($lov2 < -5) $KF -= 40;
                        else if ($lov2 < -4) $KF -= 35;
                        else if ($lov2 < -3) $KF -= 30;
                        else if ($lov2 < -2) $KF -= 25;
                        else if ($lov2 < -1) $KF -= 20;
                    }
                }
            }
        }
    }
    return $KF;
}
function calcKF_old_1h($cena1, $cena2, $cena3, $ob1, $ob2, $ob3, $mincena1, $mincena2, $mincena3) {
    $PrIzm1 = (($cena2 - $cena1) / $cena1) * 100;
    $PrIzm2 = (($cena3 - $cena2) / $cena2) * 100;
    $PrOb1  = (($ob2 - $ob1) / $ob1) * 100;
    $PrOb2  = (($ob3 - $ob2) / $ob2) * 100;
    $lov1   = (($mincena1 - $cena1) / $cena1) * 100;
    $lov2   = (($mincena2 - $cena2) / $cena2) * 100;
    $lov3   = (($mincena3 - $cena3) / $cena3) * 100;

    $KF = 0;
    if ($cena1 > $cena2) {
        if ($lov2 < -1.5) $KF = 0;
        else if ($lov2 < -1) $KF = 0;
        else {
            if ($PrOb1 > 130) {
                if ($PrIzm1 < 3) $KF = 10;
                else if ($PrIzm1 < 7) $KF = 5;
                else $KF = 0;
            } else if ($PrOb1 > 25) $KF = 45;
            else if ($PrOb1 < 10) $KF = 20;
            else $KF = 35;
        }
        if (-0.1 < $PrIzm1 && $PrIzm1 < 0) $KF = 0;
        else if (-0.2 < $PrIzm1 && $PrIzm1 < 0) $KF = 5;

        if ($cena2 > $cena3) {
            if ($lov3 < -1.5) $KF += 5;
            else if ($lov3 < -1) $KF += 5;
            else {
                if ($PrOb2 > 30) $KF += 55;
                else if ($PrOb2 < -20) $KF += 5;
                else if ($PrOb2 < 0) $KF += 15;
                else if ($PrOb2 < 15) $KF += 35;
                else $KF += 45;

                if ($PrOb2 > 25) {
                    if ($PrIzm2 < -2) { }
                    else {
                        if ($lov1 < -1) $KF -= 10;
                        if ($lov2 < -1) $KF -= 10;
                        if ($lov3 < -1) $KF -= 20;
                    }
                } else {
                    if ($lov1 < -5) $KF -= 40;
                    else if ($lov1 < -4) $KF -= 35;
                    else if ($lov1 < -3) $KF -= 30;
                    else if ($lov1 < -2) $KF -= 25;
                    else if ($lov1 < -1) $KF -= 20;
                    else {
                        if ($lov2 < -5) $KF -= 40;
                        else if ($lov2 < -4) $KF -= 35;
                        else if ($lov2 < -3) $KF -= 30;
                        else if ($lov2 < -2) $KF -= 25;
                        else if ($lov2 < -1) $KF -= 20;
                    }
                }
            }
        }
    }
    return $KF;
}
function calcKF_oth_15m($cena1, $cena2, $cena3, $ob1, $ob2, $ob3, $mincena1, $mincena2, $mincena3) {
    $PrIzm1 = (($cena2 - $cena1) / $cena1) * 100;
    $PrIzm2 = (($cena3 - $cena2) / $cena2) * 100;
    $PrOb1  = (($ob2 - $ob1) / $ob1) * 100;
    $PrOb2  = (($ob3 - $ob2) / $ob2) * 100;
    $lov1   = (($mincena1 - $cena1) / $cena1) * 100;
    $lov2   = (($mincena2 - $cena2) / $cena2) * 100;
    $lov3   = (($mincena3 - $cena3) / $cena3) * 100;

    $lov2_high=-2.5; $lov2_low=-0.4; $prOb1_high=70; $prIzm1_low=-0.9; $lov_crash=-0.3;
    $lov3_high=-1.2; $lov3_low=-0.6; $prOb2_high=40; $prIzm2_crash=-0.5; $prIzm1_high=-0.3; $prOb2_trigger=45;
    $kf_high=10; $kf_mid=70; $kf_low=30; $kf_base=8;
    $kf_add_high=20; $kf_add_low=35; $kf_add_base=40; $kf_add_mid=45; $kf_add_high2=45;

    $KF = 0;
    if ($cena1 > $cena2) {
        if ($lov2 < $lov2_high) $KF = 0;
        else if ($lov2 < $lov2_low) $KF = 0;
        else {
            if ($PrOb1 > $prOb1_high) {
                if ($PrIzm1 < $prIzm1_high) $KF = $kf_high;
                else if ($PrIzm1 < $prIzm1_low) $KF = $kf_high/2;
                else $KF = 0;
            } else if ($PrOb1 > 15) $KF = $kf_mid;
            else if ($PrOb1 < 0) $KF = $kf_low;
            else $KF = $kf_base;
        }
        if (-0.02 < $PrIzm1 && $PrIzm1 < 0) $KF = 0;
        else if (-0.04 < $PrIzm1 && $PrIzm1 < 0) $KF = 10;

        if ($cena2 > $cena3) {
            if ($lov3 < $lov3_high) $KF += 5;
            else if ($lov3 < $lov3_low) $KF += 5;
            else {
                if ($PrOb2 > $prOb2_high) {
                    $KF += $kf_add_high;
                    if ($PrIzm2 < -0.13) $KF += 15;
                } else if ($PrOb2 < -20) $KF += $kf_add_low;
                else if ($PrOb2 < 0) $KF += $kf_add_base;
                else if ($PrOb2 < 15) $KF += $kf_add_mid;
                else $KF += $kf_add_high2;

                if ($PrOb2 > $prOb2_trigger) {
                    if ($PrIzm2 < $prIzm2_crash) $KF = 0;
                    else {
                        if ($lov1 < $lov_crash) $KF -= 15;
                        if ($lov2 < $lov_crash) $KF -= 20;
                        if ($lov3 < $lov_crash) $KF -= 30;
                    }
                } else {
                    if ($lov1 < -0.5) $KF -= 40;
                    else if ($lov1 < -0.4) $KF -= 35;
                    else if ($lov1 < -0.3) $KF -= 30;
                    else if ($lov1 < -0.25) $KF -= 25;
                    else if ($lov1 < -0.2) $KF -= 20;
                    else {
                        if ($lov2 < -0.5) $KF -= 40;
                        else if ($lov2 < -0.4) $KF -= 35;
                        else if ($lov2 < -0.3) $KF -= 30;
                        else if ($lov2 < -0.25) $KF -= 25;
                        else if ($lov2 < -0.2) $KF -= 20;
                    }
                }
            }
        }
    }
    return $KF;
}
function calcKF_old_15m($cena1, $cena2, $cena3, $ob1, $ob2, $ob3, $mincena1, $mincena2, $mincena3) {
    $PrIzm1 = (($cena2 - $cena1) / $cena1) * 100;
    $PrIzm2 = (($cena3 - $cena2) / $cena2) * 100;
    $PrOb1  = (($ob2 - $ob1) / $ob1) * 100;
    $PrOb2  = (($ob3 - $ob2) / $ob2) * 100;
    $lov1   = (($mincena1 - $cena1) / $cena1) * 100;
    $lov2   = (($mincena2 - $cena2) / $cena2) * 100;
    $lov3   = (($mincena3 - $cena3) / $cena3) * 100;

    $KF = 0;
    if ($cena1 > $cena2) {
        if ($lov2 < -0.40) $KF = 0;
        else if ($lov2 < -0.30) $KF = 0;
        else {
            if ($PrOb1 > 80) {
                if ($PrIzm1 < -0.1) $KF = 7;
                else if ($PrIzm1 < -0.2) $KF = 3;
                else $KF = 0;
            } else if ($PrOb1 > 15) $KF = 55;
            else if ($PrOb1 < 0) $KF = 30;
            else $KF = 7;
        }
        if (-0.02 < $PrIzm1 && $PrIzm1 < 0) $KF = 0;
        else if (-0.04 < $PrIzm1 && $PrIzm1 < 0) $KF = 10;

        if ($cena2 > $cena3) {
            if ($lov3 < -0.4) $KF += 5;
            else if ($lov3 < -0.26) $KF += 5;
            else {
                if ($PrOb2 > 30) {
                    $KF += 22;
                    if ($PrIzm2 < -0.13) $KF += 15;
                } else if ($PrOb2 < -20) $KF += 40;
                else if ($PrOb2 < 0) $KF += 37;
                else if ($PrOb2 < 15) $KF += 50;
                else $KF += 50;

                if ($PrOb2 > 25) {
                    if ($PrIzm2 < -0.5) { }
                    else {
                        if ($lov1 < -0.18) $KF -= 15;
                        if ($lov2 < -0.18) $KF -= 20;
                        if ($lov3 < -0.18) $KF -= 30;
                    }
                } else {
                    if ($lov1 < -0.5) $KF -= 40;
                    else if ($lov1 < -0.4) $KF -= 35;
                    else if ($lov1 < -0.3) $KF -= 30;
                    else if ($lov1 < -0.25) $KF -= 25;
                    else if ($lov1 < -0.2) $KF -= 20;
                    else {
                        if ($lov2 < -0.5) $KF -= 40;
                        else if ($lov2 < -0.4) $KF -= 35;
                        else if ($lov2 < -0.3) $KF -= 30;
                        else if ($lov2 < -0.25) $KF -= 25;
                        else if ($lov2 < -0.2) $KF -= 20;
                    }
                }
            }
        }
    }
    return $KF;
}

// ============================================
// ВСПОМОГАТЕЛЬНЫЕ
// ============================================
function findCandleAt($history, $ts_ms) {
    $lo = 0; $hi = count($history) - 1; $found = -1;
    while ($lo <= $hi) {
        $mid = (int)(($lo + $hi) / 2);
        if ($history[$mid][0] <= $ts_ms) { $found = $mid; $lo = $mid + 1; }
        else { $hi = $mid - 1; }
    }
    return $found;
}

function simulateShort($h15, $start_idx, $entry_price, $SL_PCT, $TP_PCT, $MAX_CANDLES) {
    $sl_price = $entry_price * (1 + $SL_PCT / 100);
    $tp_price = $entry_price * (1 + $TP_PCT / 100);

    $last  = count($h15);
    $limit = min($start_idx + $MAX_CANDLES, $last - 1);

    for ($i = $start_idx; $i <= $limit; $i++) {
        $low  = (float)$h15[$i][3];
        $high = (float)$h15[$i][2];

        if ($high >= $sl_price) {
            return ['result'=>'SL','pnl_pct'=>-$SL_PCT,'candles'=>$i - $start_idx];
        }
        if ($low <= $tp_price) {
            return ['result'=>'TP','pnl_pct'=>abs($TP_PCT),'candles'=>$i - $start_idx];
        }
    }

    $close  = (float)$h15[$limit][4];
    $change = (($close - $entry_price) / $entry_price) * 100;
    return ['result'=>'TIMEOUT','pnl_pct'=>$change,'candles'=>$limit - $start_idx];
}

/**
 * Упаковка одной свечи в формат, ожидаемый predict_one.py.
 */
function packCandle($c) {
    return [
        'open'  => (float)$c[1],
        'high'  => (float)$c[2],
        'low'   => (float)$c[3],
        'close' => (float)$c[4],
        'vol'   => (float)$c[5],
    ];
}

/**
 * Вызов predict_one.py. Возвращает массив с полями probability_tp, decision, threshold
 * либо null при ошибке.
 */
function callPredictor($python_bin, $script, $tmp_dir, $payload, $timeout_sec, &$err_out = null) {
    $in_path  = $tmp_dir . '/ml_in_' . getmypid() . '.json';
    $out_path = $tmp_dir . '/ml_out_' . getmypid() . '.json';

    file_put_contents($in_path, json_encode($payload, JSON_UNESCAPED_UNICODE));

    $cmd = escapeshellarg($python_bin) . ' ' . escapeshellarg($script) . ' '
         . escapeshellarg($in_path) . ' ' . escapeshellarg($out_path) . ' 2>&1';

    $descriptors = [
        0 => ['pipe','r'],
        1 => ['pipe','w'],
        2 => ['pipe','w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        $err_out = "proc_open failed";
        @unlink($in_path); @unlink($out_path);
        return null;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $start = microtime(true);
    $stdout = ''; $stderr = '';
    while (true) {
        $status = proc_get_status($proc);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        if (!$status['running']) break;
        if (microtime(true) - $start > $timeout_sec) {
            proc_terminate($proc, 9);
            $err_out = "timeout";
            fclose($pipes[1]); fclose($pipes[2]);
            proc_close($proc);
            @unlink($in_path); @unlink($out_path);
            return null;
        }
        usleep(20000);
    }
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);

    if (!file_exists($out_path)) {
        $err_out = "no output file. stderr: " . trim($stderr);
        @unlink($in_path);
        return null;
    }

    $raw = file_get_contents($out_path);
    @unlink($in_path); @unlink($out_path);

    $res = json_decode($raw, true);
    if (!is_array($res)) {
        $err_out = "bad json: " . substr($raw, 0, 200);
        return null;
    }
    return $res;
}

// ============================================
// ПРЕДРАСЧЁТ KF ДЛЯ ВСЕХ СВЕЧЕЙ
// ============================================
echo "🔧 Предрасчёт KF для всех свечей...\n";

$N = count($h15_btc);

$btc_15m_oth_arr = array_fill(0, $N, 0.0);
$btc_15m_old_arr = array_fill(0, $N, 0.0);
$eth_15m_oth_arr = array_fill(0, $N, 0.0);
$eth_15m_old_arr = array_fill(0, $N, 0.0);
$btc_1h_oth_arr  = array_fill(0, $N, 0.0);
$btc_1h_old_arr  = array_fill(0, $N, 0.0);
$btc_1d_oth_arr  = array_fill(0, $N, 0.0);
$btc_1d_old_arr  = array_fill(0, $N, 0.0);

for ($i = 2; $i < $N; $i++) {
    $c1 = $h15_btc[$i - 2];
    $c2 = $h15_btc[$i - 1];
    $c3 = $h15_btc[$i];

    $btc_15m_oth_arr[$i] = calcKF_oth_15m(
        (float)$c1[4], (float)$c2[4], (float)$c3[4],
        (float)$c1[5], (float)$c2[5], (float)$c3[5],
        (float)$c1[3], (float)$c2[3], (float)$c3[3]
    );
    $btc_15m_old_arr[$i] = calcKF_old_15m(
        (float)$c1[4], (float)$c2[4], (float)$c3[4],
        (float)$c1[5], (float)$c2[5], (float)$c3[5],
        (float)$c1[3], (float)$c2[3], (float)$c3[3]
    );

    $ts = $h15_btc[$i][0];

    $idx_eth = findCandleAt($h15_eth, $ts);
    if ($idx_eth >= 2) {
        $e1 = $h15_eth[$idx_eth - 2];
        $e2 = $h15_eth[$idx_eth - 1];
        $e3 = $h15_eth[$idx_eth];
        $eth_15m_oth_arr[$i] = calcKF_oth_15m(
            (float)$e1[4], (float)$e2[4], (float)$e3[4],
            (float)$e1[5], (float)$e2[5], (float)$e3[5],
            (float)$e1[3], (float)$e2[3], (float)$e3[3]
        );
        $eth_15m_old_arr[$i] = calcKF_old_15m(
            (float)$e1[4], (float)$e2[4], (float)$e3[4],
            (float)$e1[5], (float)$e2[5], (float)$e3[5],
            (float)$e1[3], (float)$e2[3], (float)$e3[3]
        );
    }

    $idx_1h = findCandleAt($h1h, $ts);
    if ($idx_1h >= 2) {
        $h1c1 = $h1h[$idx_1h - 2];
        $h1c2 = $h1h[$idx_1h - 1];
        $h1c3 = $h1h[$idx_1h];
        $btc_1h_oth_arr[$i] = calcKF_oth_1h(
            (float)$h1c1[4], (float)$h1c2[4], (float)$h1c3[4],
            (float)$h1c1[5], (float)$h1c2[5], (float)$h1c3[5],
            (float)$h1c1[3], (float)$h1c2[3], (float)$h1c3[3]
        );
        $btc_1h_old_arr[$i] = calcKF_old_1h(
            (float)$h1c1[4], (float)$h1c2[4], (float)$h1c3[4],
            (float)$h1c1[5], (float)$h1c2[5], (float)$h1c3[5],
            (float)$h1c1[3], (float)$h1c2[3], (float)$h1c3[3]
        );
    }

    $idx_1d = findCandleAt($h1d, $ts);
    if ($idx_1d >= 2) {
        $d1 = $h1d[$idx_1d - 2];
        $d2 = $h1d[$idx_1d - 1];
        $d3 = $h1d[$idx_1d];
        $btc_1d_oth_arr[$i] = calcKF_oth_1d(
            (float)$d1[4], (float)$d2[4], (float)$d3[4],
            (float)$d1[5], (float)$d2[5], (float)$d3[5],
            (float)$d1[3], (float)$d2[3], (float)$d3[3]
        );
        $btc_1d_old_arr[$i] = calcKF_old_1d(
            (float)$d1[4], (float)$d2[4], (float)$d3[4],
            (float)$d1[5], (float)$d2[5], (float)$d3[5],
            (float)$d1[3], (float)$d2[3], (float)$d3[3]
        );
    }
}

echo "✅ KF предрасчитан\n\n";

// ============================================
// ОСНОВНОЙ ПРОГОН
// ============================================
$THRESH_DAY_OTH     = $BASE['THRESH_DAY_OTH'];
$THRESH_DAY_OLD     = $BASE['THRESH_DAY_OLD'];
$THRESH_HOUR_OTH    = $BASE['THRESH_HOUR_OTH'];
$THRESH_HOUR_OLD    = $BASE['THRESH_HOUR_OLD'];
$THRESH_BTC_15M_OTH = $BASE['THRESH_BTC_15M_OTH'];
$THRESH_BTC_15M_OLD = $BASE['THRESH_BTC_15M_OLD'];
$THRESH_ETH_15M_OTH = $BASE['THRESH_ETH_15M_OTH'];
$THRESH_ETH_15M_OLD = $BASE['THRESH_ETH_15M_OLD'];

$total_signals = 0;   // всего сигналов бота
$ml_taken      = 0;   // модель дала TAKE (или TAKE+MAYBE)
$ml_skipped    = 0;   // модель отклонила
$ml_errors     = 0;   // ошибки вызова python

$tp_count   = 0; $sl_count   = 0; $timeout_count = 0;
$sum_pnl = 0.0;

// Отдельная статистика по сигналам, которые модель пропустила — чтобы видеть,
// сколько профита/убытка мы «спасли» или «потеряли».
$skip_tp_count = 0; $skip_sl_count = 0; $skip_timeout_count = 0;
$skip_sum_pnl = 0.0;

$next_available_idx = 2;

echo "🔍 Прогон сигналов...\n";
echo "============================================================================================================\n";
echo " Дата                | ML prob | ML dec  | ML thr | Result  | Candles | PnL      | Action\n";
echo "============================================================================================================\n";

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
    $signal_time_str = gmdate('Y-m-d H:i:s', (int)($h15_btc[$i][0] / 1000));

    $total_signals++;

    // ---- Формируем payload для модели ----
    $payload = [
        'signal_time' => $signal_time_str,
        'entry_price' => $entry_price,
        'candles' => [
            packCandle($h15_btc[$i - 2]),
            packCandle($h15_btc[$i - 1]),
            packCandle($h15_btc[$i]),
        ],
        'kf_data' => [
            'kf_btc_15m_oth' => $btc_15m_oth_arr[$i],
            'kf_btc_15m_old' => $btc_15m_old_arr[$i],
            'kf_eth_15m_oth' => $eth_15m_oth_arr[$i],
            'kf_eth_15m_old' => $eth_15m_old_arr[$i],
            'kf_btc_1h_oth'  => $btc_1h_oth_arr[$i],
            'kf_btc_1h_old'  => $btc_1h_old_arr[$i],
            'kf_btc_1d_oth'  => $btc_1d_oth_arr[$i],
            'kf_btc_1d_old'  => $btc_1d_old_arr[$i],
        ],
    ];

    // ---- Вызов модели ----
    $ml_err = null;
    $ml_res = callPredictor($PYTHON_BIN, $PREDICT_SCRIPT, $ML_TMP_DIR, $payload, $ML_TIMEOUT_SEC, $ml_err);

    $ml_prob = null; $ml_dec = 'ERR'; $ml_thr = null;
    $accept = false;

    if ($ml_res === null) {
        $ml_errors++;
        if ($ML_FAIL_OPEN) {
            $accept = true;
            $ml_dec = 'FAIL_OPEN';
        } else {
            $accept = false;
            $ml_dec = 'FAIL_CLOSE';
        }
    } else {
        $ml_prob = $ml_res['probability_tp'] ?? null;
        $ml_dec  = $ml_res['decision'] ?? 'ERR';
        $ml_thr  = $ml_res['threshold'] ?? null;

        if ($ml_dec === 'TAKE') {
            $accept = true;
        } elseif ($ml_dec === 'MAYBE' && $ML_ACCEPT_MODE === 'TAKE_MAYBE') {
            $accept = true;
        } else {
            $accept = false;
        }
    }

    // ---- Симуляция (считаем её всегда, чтобы видеть упущенный PnL) ----
    $sim = simulateShort(
        $h15_btc,
        $entry_idx + 1,
        $entry_price,
        $SL_PCT,
        $TP_PCT,
        $MAX_CANDLES
    );

    // ---- Учёт ----
    if ($accept) {
        $ml_taken++;
        if ($sim['result'] === 'TP')       $tp_count++;
        elseif ($sim['result'] === 'SL')   $sl_count++;
        else                                $timeout_count++;
        $sum_pnl += $sim['pnl_pct'];

        // Следующая сделка возможна после закрытия этой
        $next_available_idx = $entry_idx + $sim['candles'] + 1;
        $action = 'TAKE';
    } else {
        $ml_skipped++;
        if ($sim['result'] === 'TP')       $skip_tp_count++;
        elseif ($sim['result'] === 'SL')   $skip_sl_count++;
        else                                $skip_timeout_count++;
        $skip_sum_pnl += $sim['pnl_pct'];

        // ВАЖНО: если модель отклонила сигнал — мы НЕ занимаем позицию,
        // но следующий сигнал бота может прийти раньше окончания этой
        // виртуальной сделки. Чтобы чистый ML-бэктест «одна сделка за раз»
        // оставался корректным, сдвигаем индекс так же, как если бы
        // сделка была открыта. Если хотите разрешить накладывать сделки —
        // закомментируйте строку ниже.
        $next_available_idx = $entry_idx + $sim['candles'] + 1;
        $action = 'SKIP';
    }

    $ml_prob_str = is_null($ml_prob) ? '   -   ' : sprintf('%6.4f', $ml_prob);
    $ml_thr_str  = is_null($ml_thr)  ? '   -  ' : sprintf('%5.3f', $ml_thr);

    printf(" %s | %s | %-7s | %s | %-7s | %7d | %+7.3f%% | %s%s\n",
        $signal_time_str,
        $ml_prob_str,
        $ml_dec,
        $ml_thr_str,
        $sim['result'],
        $sim['candles'],
        $sim['pnl_pct'],
        $action,
        ($ml_err && $action === 'SKIP') ? " ($ml_err)" : ""
    );
}

$elapsed = round(microtime(true) - $t_start, 2);

// ============================================
// ИТОГОВАЯ СТАТИСТИКА
// ============================================
echo "\n";
echo "============================================================================================================\n";
echo "  ИТОГИ (за {$elapsed}с)\n";
echo "============================================================================================================\n\n";

$wr = $total_signals > 0 ? ($tp_count / max(1,$ml_taken)) * 100 : 0;
$exp = $ml_taken > 0 ? $sum_pnl / $ml_taken : 0;

echo "ВСЕ СИГНАЛЫ БОТА:\n";
echo "  Всего сигналов:        {$total_signals}\n";
echo "  ML взял (TAKE" . ($ML_ACCEPT_MODE==='TAKE_MAYBE' ? '+MAYBE' : '') . "):  {$ml_taken}\n";
echo "  ML пропустил:          {$ml_skipped}\n";
echo "  Ошибок вызова Python:  {$ml_errors}\n\n";

echo "РЕЗУЛЬТАТЫ СДЕЛОК, КОТОРЫЕ ВЗЯЛ ML:\n";
echo "  TP:                    {$tp_count}\n";
echo "  SL:                    {$sl_count}\n";
echo "  TIMEOUT:               {$timeout_count}\n";
if ($ml_taken > 0) {
    echo "  Winrate (TP/взятые):   " . round($wr, 2) . "%\n";
    echo "  Expectancy:            " . round($exp, 4) . "%\n";
    echo "  Суммарный PnL:         " . round($sum_pnl, 2) . "%\n";
}

echo "\nУПУЩЕННЫЕ СИГНАЛЫ (ML сказал SKIP/MAYBE):\n";
echo "  Всего пропущено:       {$ml_skipped}\n";
echo "  Из них TP:             {$skip_tp_count}\n";
echo "  Из них SL:             {$skip_sl_count}\n";
echo "  Из них TIMEOUT:        {$skip_timeout_count}\n";
if ($ml_skipped > 0) {
    $skip_wr = ($skip_tp_count / $ml_skipped) * 100;
    $skip_exp = $skip_sum_pnl / $ml_skipped;
    echo "  Winrate (пропущ.):     " . round($skip_wr, 2) . "%\n";
    echo "  Expectancy (пропущ.):  " . round($skip_exp, 4) . "%\n";
    echo "  Суммарный PnL:         " . round($skip_sum_pnl, 2) . "%\n";
}

echo "\n============================================================================================================\n";