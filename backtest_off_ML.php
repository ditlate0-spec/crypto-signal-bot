<?php
// ============================================
// БЭКТЕСТ БЕЗ ML-ФИЛЬТРА.
// Жёсткий TP (-0.21%) и SL (+0.5%).
// Одна сделка за раз.
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

// Параметры сделки
$SL_PCT      = 0.5;
$TP_PCT      = -0.23;
$MAX_CANDLES = 70;

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

/**
 * Симуляция шорта с жёстким TP и SL.
 */
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

$total_signals = 0;
$tp_count   = 0; $sl_count   = 0; $timeout_count = 0;
$sum_pnl = 0.0;

$next_available_idx = 2;

echo "🔍 Прогон сигналов...\n";
echo "==========================================================================================\n";
echo " Дата                | Result  | Candles | PnL\n";
echo "==========================================================================================\n";

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

    $sim = simulateShort(
        $h15_btc,
        $entry_idx + 1,
        $entry_price,
        $SL_PCT,
        $TP_PCT,
        $MAX_CANDLES
    );

    $total_signals++;
    if ($sim['result'] === 'TP')         $tp_count++;
    elseif ($sim['result'] === 'SL')     $sl_count++;
    else                                 $timeout_count++;
    $sum_pnl += $sim['pnl_pct'];

    $signal_time_str = gmdate('Y-m-d H:i:s', (int)($h15_btc[$i][0] / 1000));

    printf(" %s | %-7s | %7d | %+7.3f%%\n",
        $signal_time_str,
        $sim['result'],
        $sim['candles'],
        $sim['pnl_pct']
    );

    // Следующая сделка возможна после закрытия этой
    $next_available_idx = $entry_idx + $sim['candles'] + 1;
}

$elapsed = round(microtime(true) - $t_start, 2);

// ============================================
// ИТОГОВАЯ СТАТИСТИКА
// ============================================
echo "\n";
echo "==========================================================================================\n";
echo "  ИТОГИ (за {$elapsed}с)\n";
echo "==========================================================================================\n\n";

echo "ВСЕ СИГНАЛЫ БОТА:\n";
echo "  Всего сигналов:   {$total_signals}\n";
echo "  TP:               {$tp_count}\n";
echo "  SL:               {$sl_count}\n";
echo "  TIMEOUT:          {$timeout_count}\n";
if ($total_signals > 0) {
    $wr  = ($tp_count / $total_signals) * 100;
    $exp = $sum_pnl / $total_signals;
    echo "  Winrate:          " . round($wr, 2) . "%\n";
    echo "  Expectancy:       " . round($exp, 4) . "%\n";
    echo "  Суммарный PnL:    " . round($sum_pnl, 2) . "%\n";
}

echo "\n==========================================================================================\n";