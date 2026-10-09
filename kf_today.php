<?php
// ============================================
// ВЫВОД KF ВСЕХ БОТОВ ЗА СЕГОДНЯ
// ============================================

ob_implicit_flush(true);
if (ob_get_level()) ob_end_flush();
set_time_limit(0);

// ---- Пути ----
$f_15m_btc = __DIR__ . '/history_15m.json';
$f_15m_eth = __DIR__ . '/history_15m_eth.json';
$f_1h      = __DIR__ . '/history_1h.json';
$f_1d      = __DIR__ . '/history_1d.json';

// ---- Пороги (те же, что в бэктесте) ----
$BASE = [
    'THRESH_DAY_OTH'     => 20,
    'THRESH_DAY_OLD'     => 50,
    'THRESH_HOUR_OTH'    => 20,
    'THRESH_HOUR_OLD'    => 50,
    'THRESH_BTC_15M_OTH' => 20,
    'THRESH_BTC_15M_OLD' => 75,
    'THRESH_ETH_15M_OTH' => 0,
    'THRESH_ETH_15M_OLD' => 90,
];

// ---- Загрузка ----
foreach ([$f_15m_btc, $f_15m_eth, $f_1h, $f_1d] as $f) {
    if (!file_exists($f)) die("❌ Не найден: $f\n");
}
$h15_btc = json_decode(file_get_contents($f_15m_btc), true);
$h15_eth = json_decode(file_get_contents($f_15m_eth), true);
$h1h     = json_decode(file_get_contents($f_1h), true);
$h1d     = json_decode(file_get_contents($f_1d), true);

if (empty($h15_btc) || empty($h15_eth) || empty($h1h) || empty($h1d)) {
    die("❌ Пустые данные\n");
}

echo "✅ 15m BTC: " . count($h15_btc) . "\n";
echo "✅ 15m ETH: " . count($h15_eth) . "\n";
echo "✅ 1h BTC:  " . count($h1h) . "\n";
echo "✅ 1d BTC:  " . count($h1d) . "\n\n";

// ============================================
// ФУНКЦИИ KF (те же, что в бэктесте)
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
        if ($lov2 < $lov2_high) $KF = 0;
        else if ($lov2 < $lov2_low) $KF = 0;
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
                    if ($lov1 < -8) $KF -= 40; else if ($lov1 < -7) $KF -= 35;
                    else if ($lov1 < -6) $KF -= 30; else if ($lov1 < -5) $KF -= 25;
                    else if ($lov1 < -4) $KF -= 20;
                    else {
                        if ($lov2 < -8) $KF -= 40; else if ($lov2 < -7) $KF -= 35;
                        else if ($lov2 < -6) $KF -= 30; else if ($lov2 < -5) $KF -= 25;
                        else if ($lov2 < -4) $KF -= 20;
                    }
                }
            }
        }
    }
    return $KF;
}

function calcKF_old_1d($c1,$c2,$c3,$o1,$o2,$o3,$m1,$m2,$m3) {
    return calcKF_oth_1d($c1,$c2,$c3,$o1,$o2,$o3,$m1,$m2,$m3);
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
        if ($lov2 < $lov2_high) $KF = 0;
        else if ($lov2 < $lov2_low) $KF = 0;
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
                    if ($lov1 < -5) $KF -= 40; else if ($lov1 < -4) $KF -= 35;
                    else if ($lov1 < -3) $KF -= 30; else if ($lov1 < -2) $KF -= 25;
                    else if ($lov1 < -1) $KF -= 20;
                    else {
                        if ($lov2 < -5) $KF -= 40; else if ($lov2 < -4) $KF -= 35;
                        else if ($lov2 < -3) $KF -= 30; else if ($lov2 < -2) $KF -= 25;
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
        if ($lov2 < -1.5) $KF = 0; else if ($lov2 < -1) $KF = 0;
        else {
            if ($PrOb1 > 130) {
                if ($PrIzm1 < 3) $KF = 10; else if ($PrIzm1 < 7) $KF = 5; else $KF = 0;
            } else if ($PrOb1 > 25) $KF = 45;
            else if ($PrOb1 < 10) $KF = 20;
            else $KF = 35;
        }
        if (-0.1 < $PrIzm1 && $PrIzm1 < 0) $KF = 0;
        else if (-0.2 < $PrIzm1 && $PrIzm1 < 0) $KF = 5;
        if ($cena2 > $cena3) {
            if ($lov3 < -1.5) $KF += 5; else if ($lov3 < -1) $KF += 5;
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
                    if ($lov1 < -5) $KF -= 40; else if ($lov1 < -4) $KF -= 35;
                    else if ($lov1 < -3) $KF -= 30; else if ($lov1 < -2) $KF -= 25;
                    else if ($lov1 < -1) $KF -= 20;
                    else {
                        if ($lov2 < -5) $KF -= 40; else if ($lov2 < -4) $KF -= 35;
                        else if ($lov2 < -3) $KF -= 30; else if ($lov2 < -2) $KF -= 25;
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
                    if ($lov1 < -0.5) $KF -= 40; else if ($lov1 < -0.4) $KF -= 35;
                    else if ($lov1 < -0.3) $KF -= 30; else if ($lov1 < -0.25) $KF -= 25;
                    else if ($lov1 < -0.2) $KF -= 20;
                    else {
                        if ($lov2 < -0.5) $KF -= 40; else if ($lov2 < -0.4) $KF -= 35;
                        else if ($lov2 < -0.3) $KF -= 30; else if ($lov2 < -0.25) $KF -= 25;
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
                if ($PrIzm1 < -0.1) $KF = 7; else if ($PrIzm1 < -0.2) $KF = 3; else $KF = 0;
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
                    if ($lov1 < -0.5) $KF -= 40; else if ($lov1 < -0.4) $KF -= 35;
                    else if ($lov1 < -0.3) $KF -= 30; else if ($lov1 < -0.25) $KF -= 25;
                    else if ($lov1 < -0.2) $KF -= 20;
                    else {
                        if ($lov2 < -0.5) $KF -= 40; else if ($lov2 < -0.4) $KF -= 35;
                        else if ($lov2 < -0.3) $KF -= 30; else if ($lov2 < -0.25) $KF -= 25;
                        else if ($lov2 < -0.2) $KF -= 20;
                    }
                }
            }
        }
    }
    return $KF;
}

function findCandleAt($history, $ts_ms) {
    $lo = 0; $hi = count($history) - 1; $found = -1;
    while ($lo <= $hi) {
        $mid = (int)(($lo + $hi) / 2);
        if ($history[$mid][0] <= $ts_ms) { $found = $mid; $lo = $mid + 1; }
        else { $hi = $mid - 1; }
    }
    return $found;
}

// ============================================
// СБОР KF ПО ВСЕМ 15m СВЕЧАМ BTC
// ============================================
$N = count($h15_btc);
$rows = [];

for ($i = 2; $i < $N; $i++) {
    $c1 = $h15_btc[$i - 2];
    $c2 = $h15_btc[$i - 1];
    $c3 = $h15_btc[$i];

    $btc15_oth = calcKF_oth_15m(
        (float)$c1[4], (float)$c2[4], (float)$c3[4],
        (float)$c1[5], (float)$c2[5], (float)$c3[5],
        (float)$c1[3], (float)$c2[3], (float)$c3[3]
    );
    $btc15_old = calcKF_old_15m(
        (float)$c1[4], (float)$c2[4], (float)$c3[4],
        (float)$c1[5], (float)$c2[5], (float)$c3[5],
        (float)$c1[3], (float)$c2[3], (float)$c3[3]
    );

    $ts = $h15_btc[$i][0];

    $eth_oth = 0; $eth_old = 0;
    $idx_eth = findCandleAt($h15_eth, $ts);
    if ($idx_eth >= 2) {
        $e1 = $h15_eth[$idx_eth - 2];
        $e2 = $h15_eth[$idx_eth - 1];
        $e3 = $h15_eth[$idx_eth];
        $eth_oth = calcKF_oth_15m(
            (float)$e1[4], (float)$e2[4], (float)$e3[4],
            (float)$e1[5], (float)$e2[5], (float)$e3[5],
            (float)$e1[3], (float)$e2[3], (float)$e3[3]
        );
        $eth_old = calcKF_old_15m(
            (float)$e1[4], (float)$e2[4], (float)$e3[4],
            (float)$e1[5], (float)$e2[5], (float)$e3[5],
            (float)$e1[3], (float)$e2[3], (float)$e3[3]
        );
    }

    $h_oth = 0; $h_old = 0;
    $idx_1h = findCandleAt($h1h, $ts);
    if ($idx_1h >= 2) {
        $h1 = $h1h[$idx_1h - 2]; $h2 = $h1h[$idx_1h - 1]; $h3 = $h1h[$idx_1h];
        $h_oth = calcKF_oth_1h(
            (float)$h1[4], (float)$h2[4], (float)$h3[4],
            (float)$h1[5], (float)$h2[5], (float)$h3[5],
            (float)$h1[3], (float)$h2[3], (float)$h3[3]
        );
        $h_old = calcKF_old_1h(
            (float)$h1[4], (float)$h2[4], (float)$h3[4],
            (float)$h1[5], (float)$h2[5], (float)$h3[5],
            (float)$h1[3], (float)$h2[3], (float)$h3[3]
        );
    }

    $d_oth = 0; $d_old = 0;
    $idx_1d = findCandleAt($h1d, $ts);
    if ($idx_1d >= 2) {
        $d1 = $h1d[$idx_1d - 2]; $d2 = $h1d[$idx_1d - 1]; $d3 = $h1d[$idx_1d];
        $d_oth = calcKF_oth_1d(
            (float)$d1[4], (float)$d2[4], (float)$d3[4],
            (float)$d1[5], (float)$d2[5], (float)$d3[5],
            (float)$d1[3], (float)$d2[3], (float)$d3[3]
        );
        $d_old = calcKF_old_1d(
            (float)$d1[4], (float)$d2[4], (float)$d3[4],
            (float)$d1[5], (float)$d2[5], (float)$d3[5],
            (float)$d1[3], (float)$d2[3], (float)$d3[3]
        );
    }

    $rows[] = [
        'time'    => gmdate('Y-m-d H:i', (int)($ts / 1000)),
        'btc15_oth' => $btc15_oth, 'btc15_old' => $btc15_old,
        'eth15_oth' => $eth_oth,   'eth15_old' => $eth_old,
        'h_oth'     => $h_oth,     'h_old'     => $h_old,
        'd_oth'     => $d_oth,     'd_old'     => $d_old,
    ];
}

// ============================================
// ВЫВОД
// ============================================
echo "Пороги:\n";
echo "  DAY   OTH > {$BASE['THRESH_DAY_OTH']} | OLD > {$BASE['THRESH_DAY_OLD']}\n";
echo "  HOUR  OTH > {$BASE['THRESH_HOUR_OTH']} | OLD > {$BASE['THRESH_HOUR_OLD']}\n";
echo "  BTC15 OTH > {$BASE['THRESH_BTC_15M_OTH']} | OLD > {$BASE['THRESH_BTC_15M_OLD']}\n";
echo "  ETH15 OTH > {$BASE['THRESH_ETH_15M_OTH']} | OLD > {$BASE['THRESH_ETH_15M_OLD']}\n\n";

$hdr = sprintf(
    "%-16s | %8s %8s | %8s %8s | %8s %8s | %8s %8s | %s",
    'Time (UTC)',
    'D.oth','D.old', 'H.oth','H.old', 'B15.o','B15.l', 'E15.o','E15.l', 'Verdict'
);
echo $hdr . "\n";
echo str_repeat('-', strlen($hdr) + 10) . "\n";

foreach ($rows as $r) {
    $day_ok  = ($r['d_oth']   > $BASE['THRESH_DAY_OTH']     && $r['d_old']   > $BASE['THRESH_DAY_OLD']);
    $hour_ok = ($r['h_oth']   > $BASE['THRESH_HOUR_OTH']    && $r['h_old']   > $BASE['THRESH_HOUR_OLD']);
    $btc_ok  = ($r['btc15_oth'] > $BASE['THRESH_BTC_15M_OTH'] && $r['btc15_old'] > $BASE['THRESH_BTC_15M_OLD']);
    $eth_ok  = ($r['eth15_oth'] > $BASE['THRESH_ETH_15M_OTH'] && $r['eth15_old'] > $BASE['THRESH_ETH_15M_OLD']);
    $trig15  = ($btc_ok && $eth_ok);

    $verdict = '—';
    if ($day_ok && $hour_ok)       $verdict = '✅ DAY+HOUR';
    elseif ($day_ok && $trig15)    $verdict = '✅ DAY+15m';
    elseif ($hour_ok && $trig15)   $verdict = '✅ HOUR+15m';

    printf(
        "%-16s | %8.1f %8.1f | %8.1f %8.1f | %8.1f %8.1f | %8.1f %8.1f | %s\n",
        $r['time'],
        $r['d_oth'], $r['d_old'],
        $r['h_oth'], $r['h_old'],
        $r['btc15_oth'], $r['btc15_old'],
        $r['eth15_oth'], $r['eth15_old'],
        $verdict
    );
}

// Сводка
echo "\n=== СВОДКА ===\n";
$c_day  = 0; $c_hour = 0; $c_btc = 0; $c_eth = 0; $c_trig = 0; $c_verdict = 0;
foreach ($rows as $r) {
    if ($r['d_oth']   > $BASE['THRESH_DAY_OTH']     && $r['d_old']   > $BASE['THRESH_DAY_OLD'])     $c_day++;
    if ($r['h_oth']   > $BASE['THRESH_HOUR_OTH']    && $r['h_old']   > $BASE['THRESH_HOUR_OLD'])    $c_hour++;
    if ($r['btc15_oth'] > $BASE['THRESH_BTC_15M_OTH'] && $r['btc15_old'] > $BASE['THRESH_BTC_15M_OLD']) $c_btc++;
    if ($r['eth15_oth'] > $BASE['THRESH_ETH_15M_OTH'] && $r['eth15_old'] > $BASE['THRESH_ETH_15M_OLD']) $c_eth++;
    if ($c_btc && $c_eth) {} // заглушка
}

foreach ($rows as $r) {
    $day_ok  = ($r['d_oth']   > $BASE['THRESH_DAY_OTH']     && $r['d_old']   > $BASE['THRESH_DAY_OLD']);
    $hour_ok = ($r['h_oth']   > $BASE['THRESH_HOUR_OTH']    && $r['h_old']   > $BASE['THRESH_HOUR_OLD']);
    $btc_ok  = ($r['btc15_oth'] > $BASE['THRESH_BTC_15M_OTH'] && $r['btc15_old'] > $BASE['THRESH_BTC_15M_OLD']);
    $eth_ok  = ($r['eth15_oth'] > $BASE['THRESH_ETH_15M_OTH'] && $r['eth15_old'] > $BASE['THRESH_ETH_15M_OLD']);
    if ($btc_ok && $eth_ok) $c_trig++;
    if (($day_ok && $hour_ok) || ($day_ok && $btc_ok && $eth_ok) || ($hour_ok && $btc_ok && $eth_ok)) $c_verdict++;
}
printf("Всего 15m свечей:      %d\n", count($rows));
printf("DAY   прошёл порог:    %d\n", $c_day);
printf("HOUR  прошёл порог:    %d\n", $c_hour);
printf("BTC15 прошёл порог:    %d\n", $c_btc);
printf("ETH15 прошёл порог:    %d\n", $c_eth);
printf("Оба 15m (триггер):     %d\n", $c_trig);
printf("ИТОГОВЫЙ verdict=true: %d\n", $c_verdict);