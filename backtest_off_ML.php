<?php
// ============================================
// БЭКТЕСТ БЕЗ ML И RSI.
// Логика входа = TradingBotService::buildVerdict (КАСКАДНАЯ).
//
// КАСКАДНАЯ ЛОГИКА РЕЖИМА (1d → 1h → 15m):
//   День:
//     oldDayKf > 83               → 1d
//     oldDayKf ∈ [45, 83]         → смотрим час
//     oldDayKf < 45               → сигнала нет
//   Час (только если день в [45, 83]):
//     btc_hour_strong && oldHourKf > 83  → 1h
//     btc_hour_strong && oldHourKf ≤ 83  → смотрим 15m
//     !btc_hour_strong                   → смотрим 15m
//   15m (если дошли):
//     trigger_15m_ready                  → 15m
//   Отдельная ветка: !btc_day_strong && btc_hour_strong && trigger_15m_ready → 15m
//
// ПРАВИЛА БЛОКИРОВКИ:
//   1. Внутри одного окна — не больше одной сделки каждого режима.
//   2. Если в ПРЕДЫДУЩЕМ окне режима была сделка — в СЛЕДУЮЩЕМ не открываем.
//   3. Старший режим блокирует младшие:
//      - 1d-сделка блокирует 1h и 15m до конца дня
//      - 1h-сделка блокирует 15m до конца часа
//
// ПЛЕЧО ПО РЕЖИМУ:
//   - 1d  → 25x
//   - 1h  → 35x
//   - 15m → 50x
//   PnL в % от депозита = pnl_price_pct * leverage.
//   SL 0.9% по цене (не зависит от плеча).
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
// ПОРОГИ (каскад)
// ============================================
$THRESH_DAY_OTH        = 20;
$THRESH_DAY_OLD        = 45;   // нижняя граница «день средний»
$THRESH_DAY_OLD_STRONG = 85;   // > 83 → сразу 1d

$THRESH_HOUR_OTH        = 10;
$THRESH_HOUR_OLD        = 55;  // нижняя граница «час сильный»
$THRESH_HOUR_OLD_STRONG = 65;  // > 83 → 1h

$THRESH_BTC_15M_OTH = 30;
$THRESH_BTC_15M_OLD = 55;
$THRESH_ETH_15M_OTH = 30;
$THRESH_ETH_15M_OLD = 70;
$DAY_START_HOUR = 3;
// Режимы трейлинга (activate%, callback%)
$TRAIL_PARAMS = [
    '15m' => ['activate' => 0.2, 'callback' => 0.1],
    '1h'  => ['activate' => 0.3, 'callback' => 0.2],
    '1d'  => ['activate' => 0.8, 'callback' => 0.5],
];

// ============================================
// ПЛЕЧО И SL
// ============================================
$LEVERAGE = [
    '1d'  => 45,
    '1h'  => 45,
    '15m' => 50,
];

$SL_PCT      = 0.9;
$MAX_CANDLES = 170;

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
echo "✅ 1d BTC:  " . count($h1d) . " свечей\n";
echo "✅ Плечо: 1d={$LEVERAGE['1d']}x, 1h={$LEVERAGE['1h']}x, 15m={$LEVERAGE['15m']}x, SL={$SL_PCT}%\n";
echo "✅ Каскад: 1d(>83) / 1h(>83) / 15m\n\n";

// ============================================
// ФУНКЦИИ KF
// ============================================
function calcKF_oth_1d($c1,$c2,$c3,$o1,$o2,$o3,$m1,$m2,$m3){
    $PrIzm1=(($c2-$c1)/$c1)*100; $PrIzm2=(($c3-$c2)/$c2)*100;
    $PrOb1 =(($o2-$o1)/$o1)*100; $PrOb2 =(($o3-$o2)/$o2)*100;
    $lov1  =(($m1-$c1)/$c1)*100; $lov2  =(($m2-$c2)/$c2)*100; $lov3=(($m3-$c3)/$c3)*100;
    $lov2_high=-3;$lov2_low=-4;$prOb1_high=12;$kf_mid=45;$kf_add_high=65;
    $prIzm1_high=0.001;$prIzm1_low=0.1;$kf_high=8;$kf_low=20;$kf_base=18;
    $lov3_high=-20;$lov3_low=-4;$prOb2_high=10;$prOb2_mid=18;
    $kf_add_low=40;$kf_add_base=8;$prOb2_trigger=15;$prIzm2_crash=-6;$lov_crash=-1.5;
    $KF=0;
    if($c1>$c2){
        if($lov2<$lov2_high)$KF=0; else if($lov2<$lov2_low)$KF=0;
        else{
            if($PrOb1>$prOb1_high){
                if($PrIzm1<$prIzm1_high)$KF=$kf_high;
                else if($PrIzm1<$prIzm1_low)$KF=$kf_high/2;
                else $KF=0;
            } else if($PrOb1>25)$KF=$kf_mid;
            else if($PrOb1<10)$KF=$kf_low;
            else $KF=$kf_base;
        }
        if($c2>$c3){
            if($lov3<$lov3_high)$KF+=5; else if($lov3<$lov3_low)$KF+=5;
            else{
                if($PrOb2>$prOb2_high)$KF+=$kf_add_high;
                else if($PrOb2<$prOb2_mid)$KF+=$kf_add_low;
                else $KF+=$kf_add_base;
                if($PrOb2>$prOb2_trigger){
                    if($PrIzm2<$prIzm2_crash)$KF=0;
                    else{
                        if($lov1<$lov_crash)$KF-=10;
                        if($lov2<$lov_crash)$KF-=10;
                        if($lov3<$lov_crash)$KF-=15;
                    }
                } else {
                    if($lov1<-8)$KF-=40; else if($lov1<-7)$KF-=35; else if($lov1<-6)$KF-=30;
                    else if($lov1<-5)$KF-=25; else if($lov1<-4)$KF-=20;
                    else{
                        if($lov2<-8)$KF-=40; else if($lov2<-7)$KF-=35; else if($lov2<-6)$KF-=30;
                        else if($lov2<-5)$KF-=25; else if($lov2<-4)$KF-=20;
                    }
                }
            }
        }
    } else $KF=0;
    return $KF;
}
function calcKF_old_1d($c1,$c2,$c3,$o1,$o2,$o3,$m1,$m2,$m3){
    return calcKF_oth_1d($c1,$c2,$c3,$o1,$o2,$o3,$m1,$m2,$m3);
}
function calcKF_oth_1h($c1,$c2,$c3,$o1,$o2,$o3,$m1,$m2,$m3){
    $PrIzm1=(($c2-$c1)/$c1)*100;$PrIzm2=(($c3-$c2)/$c2)*100;
    $PrOb1=(($o2-$o1)/$o1)*100;$PrOb2=(($o3-$o2)/$o2)*100;
    $lov1=(($m1-$c1)/$c1)*100;$lov2=(($m2-$c2)/$c2)*100;$lov3=(($m3-$c3)/$c3)*100;
    $KF=0;
    if($c1>$c2){
        if($lov2<-3)$KF=0; else if($lov2<0)$KF=0;
        else{
            if($PrOb1>150){ if($PrIzm1<3)$KF=10; else if($PrIzm1<5)$KF=5; else $KF=0; }
            else if($PrOb1>25)$KF=50; else if($PrOb1<10)$KF=20; else $KF=35;
        }
        if($c2>$c3){
            if($lov3<-1.5)$KF+=5; else if($lov3<-1)$KF+=5;
            else{
                if($PrOb2>35)$KF+=40; else if($PrOb2<-20)$KF+=35;
                else if($PrOb2<0)$KF+=15; else if($PrOb2<20)$KF+=35; else $KF+=45;
                if($PrOb2>20){
                    if($PrIzm2<-2){ } else {
                        if($lov1<-1)$KF-=10; if($lov2<-1)$KF-=10; if($lov3<-1)$KF-=20;
                    }
                } else {
                    if($lov1<-5)$KF-=40; else if($lov1<-4)$KF-=35; else if($lov1<-3)$KF-=30;
                    else if($lov1<-2)$KF-=25; else if($lov1<-1)$KF-=20;
                    else{
                        if($lov2<-5)$KF-=40; else if($lov2<-4)$KF-=35; else if($lov2<-3)$KF-=30;
                        else if($lov2<-2)$KF-=25; else if($lov2<-1)$KF-=20;
                    }
                }
            }
        }
    }
    return $KF;
}
function calcKF_old_1h($c1,$c2,$c3,$o1,$o2,$o3,$m1,$m2,$m3){
    $PrIzm1=(($c2-$c1)/$c1)*100;$PrIzm2=(($c3-$c2)/$c2)*100;
    $PrOb1=(($o2-$o1)/$o1)*100;$PrOb2=(($o3-$o2)/$o2)*100;
    $lov1=(($m1-$c1)/$c1)*100;$lov2=(($m2-$c2)/$c2)*100;$lov3=(($m3-$c3)/$c3)*100;
    $KF=0;
    if($c1>$c2){
        if($lov2<-1.5)$KF=0; else if($lov2<-1)$KF=0;
        else{
            if($PrOb1>130){ if($PrIzm1<3)$KF=10; else if($PrIzm1<7)$KF=5; else $KF=0; }
            else if($PrOb1>25)$KF=45; else if($PrOb1<10)$KF=20; else $KF=35;
        }
        if(-0.1<$PrIzm1 && $PrIzm1<0)$KF=0; else if(-0.2<$PrIzm1 && $PrIzm1<0)$KF=5;
        if($c2>$c3){
            if($lov3<-1.5)$KF+=5; else if($lov3<-1)$KF+=5;
            else{
                if($PrOb2>30)$KF+=55; else if($PrOb2<-20)$KF+=5;
                else if($PrOb2<0)$KF+=15; else if($PrOb2<15)$KF+=35; else $KF+=45;
                if($PrOb2>25){
                    if($PrIzm2<-2){ } else {
                        if($lov1<-1)$KF-=10; if($lov2<-1)$KF-=10; if($lov3<-1)$KF-=20;
                    }
                } else {
                    if($lov1<-5)$KF-=40; else if($lov1<-4)$KF-=35; else if($lov1<-3)$KF-=30;
                    else if($lov1<-2)$KF-=25; else if($lov1<-1)$KF-=20;
                    else{
                        if($lov2<-5)$KF-=40; else if($lov2<-4)$KF-=35; else if($lov2<-3)$KF-=30;
                        else if($lov2<-2)$KF-=25; else if($lov2<-1)$KF-=20;
                    }
                }
            }
        }
    }
    return $KF;
}
function calcKF_oth_15m($c1,$c2,$c3,$o1,$o2,$o3,$m1,$m2,$m3){
    $PrIzm1=(($c2-$c1)/$c1)*100;$PrIzm2=(($c3-$c2)/$c2)*100;
    $PrOb1=(($o2-$o1)/$o1)*100;$PrOb2=(($o3-$o2)/$o2)*100;
    $lov1=(($m1-$c1)/$c1)*100;$lov2=(($m2-$c2)/$c2)*100;$lov3=(($m3-$c3)/$c3)*100;
    $KF=0;
    if($c1>$c2){
        if($lov2<-2.5)$KF=0; else if($lov2<-0.4)$KF=0;
        else{
            if($PrOb1>70){ if($PrIzm1<-0.3)$KF=10; else if($PrIzm1<-0.9)$KF=5; else $KF=0; }
            else if($PrOb1>15)$KF=70; else if($PrOb1<0)$KF=30; else $KF=8;
        }
        if(-0.02<$PrIzm1 && $PrIzm1<0)$KF=0; else if(-0.04<$PrIzm1 && $PrIzm1<0)$KF=10;
        if($c2>$c3){
            if($lov3<-1.2)$KF+=5; else if($lov3<-0.6)$KF+=5;
            else{
                if($PrOb2>40){ $KF+=20; if($PrIzm2<-0.13)$KF+=15; }
                else if($PrOb2<-20)$KF+=35; else if($PrOb2<0)$KF+=40;
                else if($PrOb2<15)$KF+=45; else $KF+=45;
                if($PrOb2>45){
                    if($PrIzm2<-0.5)$KF=0;
                    else{ if($lov1<-0.3)$KF-=15; if($lov2<-0.3)$KF-=20; if($lov3<-0.3)$KF-=30; }
                } else {
                    if($lov1<-0.5)$KF-=40; else if($lov1<-0.4)$KF-=35; else if($lov1<-0.3)$KF-=30;
                    else if($lov1<-0.25)$KF-=25; else if($lov1<-0.2)$KF-=20;
                    else{
                        if($lov2<-0.5)$KF-=40; else if($lov2<-0.4)$KF-=35; else if($lov2<-0.3)$KF-=30;
                        else if($lov2<-0.25)$KF-=25; else if($lov2<-0.2)$KF-=20;
                    }
                }
            }
        }
    }
    return $KF;
}
function calcKF_old_15m($c1,$c2,$c3,$o1,$o2,$o3,$m1,$m2,$m3){
    $PrIzm1=(($c2-$c1)/$c1)*100;$PrIzm2=(($c3-$c2)/$c2)*100;
    $PrOb1=(($o2-$o1)/$o1)*100;$PrOb2=(($o3-$o2)/$o2)*100;
    $lov1=(($m1-$c1)/$c1)*100;$lov2=(($m2-$c2)/$c2)*100;$lov3=(($m3-$c3)/$c3)*100;
    $KF=0;
    if($c1>$c2){
        if($lov2<-0.40)$KF=0; else if($lov2<-0.30)$KF=0;
        else{
            if($PrOb1>80){ if($PrIzm1<-0.1)$KF=7; else if($PrIzm1<-0.2)$KF=3; else $KF=0; }
            else if($PrOb1>15)$KF=55; else if($PrOb1<0)$KF=30; else $KF=7;
        }
        if(-0.02<$PrIzm1 && $PrIzm1<0)$KF=0; else if(-0.04<$PrIzm1 && $PrIzm1<0)$KF=10;
        if($c2>$c3){
            if($lov3<-0.4)$KF+=5; else if($lov3<-0.26)$KF+=5;
            else{
                if($PrOb2>30){ $KF+=22; if($PrIzm2<-0.13)$KF+=15; }
                else if($PrOb2<-20)$KF+=40; else if($PrOb2<0)$KF+=37;
                else if($PrOb2<15)$KF+=50; else $KF+=50;
                if($PrOb2>25){
                    if($PrIzm2<-0.5){ } else {
                        if($lov1<-0.18)$KF-=15; if($lov2<-0.18)$KF-=20; if($lov3<-0.18)$KF-=30;
                    }
                } else {
                    if($lov1<-0.5)$KF-=40; else if($lov1<-0.4)$KF-=35; else if($lov1<-0.3)$KF-=30;
                    else if($lov1<-0.25)$KF-=25; else if($lov1<-0.2)$KF-=20;
                    else{
                        if($lov2<-0.5)$KF-=40; else if($lov2<-0.4)$KF-=35; else if($lov2<-0.3)$KF-=30;
                        else if($lov2<-0.25)$KF-=25; else if($lov2<-0.2)$KF-=20;
                    }
                }
            }
        }
    }
    return $KF;
}

// ============================================
// ХЕЛПЕРЫ
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

function modeBucketId($ts_ms, $mode, $dayStartHour = 3) {
    $sec = intval($ts_ms / 1000);
    if ($mode === '1d')  return intdiv($sec - $dayStartHour * 3600, 86400);
    if ($mode === '1h')  return intdiv($sec, 3600);
    return intdiv($sec, 900);
}

function modeWindowEndTs($ts_ms, $mode) {
    $dt = new DateTime('@' . intval($ts_ms / 1000));
    $dt->setTimezone(new DateTimeZone('UTC'));

     if ($mode === '1d') {
    // конец торгового дня = 02:59:59 UTC следующего календарного дня
    if ((int)$dt->format('H') >= 3) {
        // если сейчас 03:00–23:59 — конец окна завтра в 02:59:59
        $dt->modify('+1 day')->setTime(2, 59, 59);
    } else {
        // если сейчас 00:00–02:59 — конец окна сегодня в 02:59:59
        $dt->setTime(2, 59, 59);
    }
} elseif ($mode === '1h') {
        $dt->setTime((int)$dt->format('H'), 59, 59);
    } else {
        $m = (int)$dt->format('i');
        $m = $m - ($m % 15) + 14;
        $dt->setTime((int)$dt->format('H'), $m, 59);
    }
    return $dt->getTimestamp() * 1000;
}

function simulateShortTrailing($h15, $start_idx, $entry_price, $sl_pct, $activate_pct, $callback_pct, $max_candles) {
    $sl_price         = $entry_price * (1 + $sl_pct / 100);
    $activation_price = $entry_price * (1 - $activate_pct / 100);

    $last  = count($h15);
    $limit = min($start_idx + $max_candles, $last - 1);

    $trailing_active = false;
    $best_low        = null;

    for ($i = $start_idx; $i <= $limit; $i++) {
        $low  = (float)$h15[$i][3];
        $high = (float)$h15[$i][2];

        if ($high >= $sl_price) {
            $pnl = -(($sl_price - $entry_price) / $entry_price) * 100;
            return ['result'=>'SL','pnl_pct'=>$pnl,'candles'=>$i - $start_idx];
        }

        if (!$trailing_active && $low <= $activation_price) {
            $trailing_active = true;
            $best_low = $low;
        }

        if ($trailing_active) {
            if ($low < $best_low) $best_low = $low;

            $stop_price = $best_low * (1 + $callback_pct / 100);
            if ($high >= $stop_price) {
                $pnl = (($entry_price - $stop_price) / $entry_price) * 100;
                return ['result'=>'TRAIL','pnl_pct'=>$pnl,'candles'=>$i - $start_idx];
            }
        }
    }

    $close  = (float)$h15[$limit][4];
    $change = (($entry_price - $close) / $entry_price) * 100;
    return ['result'=>'TIMEOUT','pnl_pct'=>$change,'candles'=>$limit - $start_idx];
}

// ============================================
// ПРЕДРАСЧЁТ KF
// ============================================
echo "🔧 Предрасчёт KF...\n";
$N = count($h15_btc);

$btc_15m_oth = array_fill(0, $N, 0.0); $btc_15m_old = array_fill(0, $N, 0.0);
$eth_15m_oth = array_fill(0, $N, 0.0); $eth_15m_old = array_fill(0, $N, 0.0);
$btc_1h_oth  = array_fill(0, $N, 0.0); $btc_1h_old  = array_fill(0, $N, 0.0);
$btc_1d_oth  = array_fill(0, $N, 0.0); $btc_1d_old  = array_fill(0, $N, 0.0);

for ($i = 2; $i < $N; $i++) {
    $c1=$h15_btc[$i-2]; $c2=$h15_btc[$i-1]; $c3=$h15_btc[$i];
    $btc_15m_oth[$i]=calcKF_oth_15m((float)$c1[4],(float)$c2[4],(float)$c3[4],(float)$c1[5],(float)$c2[5],(float)$c3[5],(float)$c1[3],(float)$c2[3],(float)$c3[3]);
    $btc_15m_old[$i]=calcKF_old_15m((float)$c1[4],(float)$c2[4],(float)$c3[4],(float)$c1[5],(float)$c2[5],(float)$c3[5],(float)$c1[3],(float)$c2[3],(float)$c3[3]);

    $ts = $h15_btc[$i][0];

    $idx_eth = findCandleAt($h15_eth, $ts);
    if ($idx_eth >= 2) {
        $e1=$h15_eth[$idx_eth-2]; $e2=$h15_eth[$idx_eth-1]; $e3=$h15_eth[$idx_eth];
        $eth_15m_oth[$i]=calcKF_oth_15m((float)$e1[4],(float)$e2[4],(float)$e3[4],(float)$e1[5],(float)$e2[5],(float)$e3[5],(float)$e1[3],(float)$e2[3],(float)$e3[3]);
        $eth_15m_old[$i]=calcKF_old_15m((float)$e1[4],(float)$e2[4],(float)$e3[4],(float)$e1[5],(float)$e2[5],(float)$e3[5],(float)$e1[3],(float)$e2[3],(float)$e3[3]);
    }

    $idx_1h = findCandleAt($h1h, $ts);
    if ($idx_1h >= 2) {
        $h1=$h1h[$idx_1h-2]; $h2=$h1h[$idx_1h-1]; $h3=$h1h[$idx_1h];
        $btc_1h_oth[$i]=calcKF_oth_1h((float)$h1[4],(float)$h2[4],(float)$h3[4],(float)$h1[5],(float)$h2[5],(float)$h3[5],(float)$h1[3],(float)$h2[3],(float)$h3[3]);
        $btc_1h_old[$i]=calcKF_old_1h((float)$h1[4],(float)$h2[4],(float)$h3[4],(float)$h1[5],(float)$h2[5],(float)$h3[5],(float)$h1[3],(float)$h2[3],(float)$h3[3]);
    }

    $idx_1d = findCandleAt($h1d, $ts);
    if ($idx_1d >= 2) {
        $d1=$h1d[$idx_1d-2]; $d2=$h1d[$idx_1d-1]; $d3=$h1d[$idx_1d];
        $btc_1d_oth[$i]=calcKF_oth_1d((float)$d1[4],(float)$d2[4],(float)$d3[4],(float)$d1[5],(float)$d2[5],(float)$d3[5],(float)$d1[3],(float)$d2[3],(float)$d3[3]);
        $btc_1d_old[$i]=calcKF_old_1d((float)$d1[4],(float)$d2[4],(float)$d3[4],(float)$d1[5],(float)$d2[5],(float)$d3[5],(float)$d1[3],(float)$d2[3],(float)$d3[3]);
    }
}
echo "✅ KF предрасчитан\n\n";

// ============================================
// ПРОГОН
// ============================================
$total = 0; $sl = 0; $trail = 0; $timeout = 0;
$sum_pnl_lev = 0.0;
$sum_pnl_price = 0.0;

$blocked_by_1d = 0;
$blocked_by_1h = 0;
$blocked_by_prev_1d = 0;
$blocked_by_prev_1h = 0;
$blocked_by_prev_15m = 0;

$by_mode = [
    '1d'  => ['n'=>0, 'pnl_lev'=>0.0, 'trail'=>0, 'sl'=>0, 'timeout'=>0],
    '1h'  => ['n'=>0, 'pnl_lev'=>0.0, 'trail'=>0, 'sl'=>0, 'timeout'=>0],
    '15m' => ['n'=>0, 'pnl_lev'=>0.0, 'trail'=>0, 'sl'=>0, 'timeout'=>0],
];

// Независимые таймеры блокировки
$block_until_1d  = 0;
$block_until_1h  = 0;
$block_until_15m = 0;

// Bucket последнего окна сделкb по каждому режиму
$last_bucket_1d  = null;
$last_bucket_1h  = null;
$last_bucket_15m = null;

echo "🔍 Прогон сигналов...\n";
echo str_repeat("=", 130) . "\n";
echo sprintf(" %-19s | %-4s | %-5s | %-7s | %-9s | %-9s | %-9s\n",
    "Время (UTC)", "Mode", "Lev", "Result", "Candles", "PnL(price)", "PnL(lev)");
echo str_repeat("=", 130) . "\n";

$t0 = microtime(true);

for ($i = 2; $i < $N - 2; $i++) {

    // ============================================
    // БЛОКИРОВКИ
    // ============================================
    $blocked_all    = ($i <= $block_until_1d);
    $blocked_1h_15m = ($i <= $block_until_1h);
    $blocked_15m    = ($i <= $block_until_15m);

    $btcDayStrong  = ($btc_1d_oth[$i] > $THRESH_DAY_OTH && $btc_1d_old[$i] > $THRESH_DAY_OLD);
    $btcHourStrong = ($btc_1h_oth[$i] > $THRESH_HOUR_OTH && $btc_1h_old[$i] > $THRESH_HOUR_OLD);

    $btc15Ready = ($btc_15m_oth[$i] > $THRESH_BTC_15M_OTH && $btc_15m_old[$i] > $THRESH_BTC_15M_OLD);
    $eth15Ready = ($eth_15m_oth[$i] > $THRESH_ETH_15M_OTH && $eth_15m_old[$i] > $THRESH_ETH_15M_OLD);
    $trigger15mReady = ($btc15Ready && $eth15Ready);

    $oldDayKf  = $btc_1d_old[$i];
    $oldHourKf = $btc_1h_old[$i];

    // ============================================
    // КАСКАДНАЯ ЛОГИКА РЕЖИМА
    // ============================================
    $enter = false;
    $mode  = null;

    if ($btcDayStrong) {
        if ($oldDayKf > $THRESH_DAY_OLD_STRONG) {
            $enter = true; $mode = '1d';
        } elseif ($oldDayKf >= $THRESH_DAY_OLD) {
            if ($btcHourStrong) {
                if ($oldHourKf > $THRESH_HOUR_OLD_STRONG) {
                    $enter = true; $mode = '1h';
                } else {
                    if ($trigger15mReady) { $enter = true; $mode = '15m'; }
                }
            } else {
                if ($trigger15mReady) { $enter = true; $mode = '15m'; }
            }
        }
    } elseif ($btcHourStrong && $trigger15mReady) {
        $enter = true; $mode = '15m';
    }

    if (!$enter) continue;

    // ============================================
    // БЛОКИРОВКИ: внутри окна + «в предыдущем окне была сделка»
    // ============================================
    $signal_ts_ms = (int)$h15_btc[$i][0];

    if ($blocked_all) { $blocked_by_1d++; continue; }
    if ($mode === '1h' && $blocked_1h_15m) { $blocked_by_1h++; continue; }
    if ($mode === '15m' && ($blocked_1h_15m || $blocked_15m)) { $blocked_by_1h++; continue; }

    $bucket = modeBucketId($signal_ts_ms, $mode, $DAY_START_HOUR);

    if ($mode === '1d') {
        if ($last_bucket_1d !== null && $bucket === $last_bucket_1d + 1) { $blocked_by_prev_1d++; continue; }
    } elseif ($mode === '1h') {
        if ($last_bucket_1h !== null && $bucket === $last_bucket_1h + 1) { $blocked_by_prev_1h++; continue; }
    } else {
        if ($last_bucket_15m !== null && $bucket === $last_bucket_15m + 1) { $blocked_by_prev_15m++; continue; }
    }

    $entry_idx = $i + 1;
    if ($entry_idx >= $N - 1) break;

    $entry_price = (float)$h15_btc[$entry_idx][1];

    // ============================================
    // СИМУЛЯЦИЯ
    // ============================================
    $tp_params = $TRAIL_PARAMS[$mode];
    $sim = simulateShortTrailing(
        $h15_btc,
        $entry_idx + 1,
        $entry_price,
        $SL_PCT,
        $tp_params['activate'],
        $tp_params['callback'],
        $MAX_CANDLES
    );

    $lev = $LEVERAGE[$mode];
    $pnl_lev = $sim['pnl_pct'] * $lev;

    // ============================================
    // ОБНОВЛЕНИЕ ТАЙМЕРОВ
    // ============================================
    $window_end_ts = modeWindowEndTs($signal_ts_ms, $mode);
    $window_end_idx = $entry_idx;
    while ($window_end_idx + 1 < $N && $h15_btc[$window_end_idx + 1][0] <= $window_end_ts) {
        $window_end_idx++;
    }

    if ($mode === '1d') {
        if ($window_end_idx > $block_until_1d)  $block_until_1d  = $window_end_idx;
        if ($window_end_idx > $block_until_1h)  $block_until_1h  = $window_end_idx;
        if ($window_end_idx > $block_until_15m) $block_until_15m = $window_end_idx;
    } elseif ($mode === '1h') {
        if ($window_end_idx > $block_until_1h)  $block_until_1h  = $window_end_idx;
        if ($window_end_idx > $block_until_15m) $block_until_15m = $window_end_idx;
    } else {
        if ($window_end_idx > $block_until_15m) $block_until_15m = $window_end_idx;
    }

    if ($mode === '1d')       $last_bucket_1d  = $bucket;
    elseif ($mode === '1h')   $last_bucket_1h  = $bucket;
    else                      $last_bucket_15m = $bucket;

    // ============================================
    // УЧЁТ
    // ============================================
    $total++;
    $sum_pnl_lev   += $pnl_lev;
    $sum_pnl_price += $sim['pnl_pct'];

    if ($sim['result'] === 'TRAIL')      $trail++;
    else if ($sim['result'] === 'SL')    $sl++;
    else                                 $timeout++;

    $by_mode[$mode]['n']++;
    $by_mode[$mode]['pnl_lev'] += $pnl_lev;
    if ($sim['result'] === 'TRAIL')        $by_mode[$mode]['trail']++;
    else if ($sim['result'] === 'SL')      $by_mode[$mode]['sl']++;
    else                                   $by_mode[$mode]['timeout']++;

    $time_str = gmdate('Y-m-d H:i:s', (int)($h15_btc[$i][0] / 1000));
    printf(" %s | %-4s | %4dx | %-7s | %9d | %+8.3f%% | %+8.3f%%\n",
        $time_str, $mode, $lev, $sim['result'], $sim['candles'], $sim['pnl_pct'], $pnl_lev);
}

$elapsed = round(microtime(true) - $t0, 2);

echo "\n" . str_repeat("=", 100) . "\n";
echo "  ИТОГИ (за {$elapsed}с)\n";
echo str_repeat("=", 100) . "\n\n";

echo "ВСЕГО СДЕЛОК: {$total}\n";
echo "Заблокировано 1d (внутри дня): {$blocked_by_1d}\n";
echo "Заблокировано 1h (внутри часа): {$blocked_by_1h}\n";
echo "Заблокировано (в пред. дне была 1d): {$blocked_by_prev_1d}\n";
echo "Заблокировано (в пред. часу была 1h): {$blocked_by_prev_1h}\n";
echo "Заблокировано (в пред. 15м была 15m): {$blocked_by_prev_15m}\n\n";

echo "ОБЩИЕ:\n";
echo "  SL:             {$sl}\n";
echo "  TRAIL:          {$trail}\n";
echo "  TIMEOUT:        {$timeout}\n";
if ($total > 0) {
    echo "  Winrate:        " . round($trail / $total * 100, 2) . "%\n";
    echo "  PnL (по цене):  " . round($sum_pnl_price, 2) . "%\n";
    echo "  PnL (с плечом): " . round($sum_pnl_lev, 2) . "%\n";
    echo "  Expectancy:     " . round($sum_pnl_lev / $total, 4) . "% (с плечом)\n";
}

echo "\nПО РЕЖИМАМ (PnL с плечом):\n";
foreach ($by_mode as $m => $s) {
    if ($s['n'] == 0) continue;
    $wr = round(($s['trail'] / $s['n']) * 100, 2);
    $ex = round($s['pnl_lev'] / $s['n'], 4);
    $lv = $LEVERAGE[$m];
    echo "  [{$m}] lev={$lv}x  сделок={$s['n']}  SL={$s['sl']}  TRAIL={$s['trail']}  TIMEOUT={$s['timeout']}  WR={$wr}%  Exp={$ex}%  PnL=" . round($s['pnl_lev'], 2) . "%\n";
}

echo "\n" . str_repeat("=", 100) . "\n";