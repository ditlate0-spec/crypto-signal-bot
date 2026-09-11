<?php
// ============================================
// BTCUSDT15M.php (БЕЗ RSI И MACD, С ЗАПИСЬЮ В БД)
// ============================================

require_once 'conn.php';

// ============================================
// ПОДКЛЮЧЕНИЕ К БД
// ============================================

$host = "localhost";
$dbuser = "root";
$dbpassword = "";
$dbname = "volta";
$dbarticles = "oth";
$connection = mysqli_connect($host, $dbuser, $dbpassword, $dbname);

$number = ['BTCUSDT', 'ETHUSDT'];

for ($S = 0; $S < count($number); $S++) {
    $BTCUSDT = $number[$S];
$response = sendRequest("api/v3/klines?symbol=$BTCUSDT&interval=15m&limit=4");
 usleep(100000);

    if (empty($response)) continue;

    // === ДАННЫЕ СВЕЧЕЙ ===
    $cena1 = (float)$response[0][4];
    $cena2 = (float)$response[1][4];
    $cena3 = (float)$response[2][4];
    $ob1 = (float)$response[0][5];
    $ob2 = (float)$response[1][5];
    $ob3 = (float)$response[2][5];
    $mincena1 = (float)$response[0][3];
    $mincena2 = (float)$response[1][3];
    $mincena3 = (float)$response[2][3];

    $PrIzm1 = (($cena2 - $cena1) / $cena1) * 100;
    $PrIzm2 = (($cena3 - $cena2) / $cena2) * 100;
    $PrOb1 = (($ob2 - $ob1) / $ob1) * 100;
    $PrOb2 = (($ob3 - $ob2) / $ob2) * 100;
    $lov1 = (($mincena1 - $cena1) / $cena1) * 100;
    $lov2 = (($mincena2 - $cena2) / $cena2) * 100;
    $lov3 = (($mincena3 - $cena3) / $cena3) * 100;

    // ============================================
    // ОПТИМАЛЬНЫЕ ПОРОГИ
    // ============================================
    $lov2_high = -2.5;
    $lov2_low = -0.4;
    $prOb1_high = 70;
    $signal_threshold = 50;
    $prIzm1_low = -0.9;
    $lov_crash = -0.3;
    $lov3_high = -1.2;
    $lov3_low = -0.6;
    $prOb2_high = 40;
    $prIzm2_crash = -0.5;
    $prIzm1_high = -0.3;
    $prOb2_trigger = 45;

    $kf_high = 10;
    $kf_mid = 70;
    $kf_low = 30;
    $kf_base = 8;
    $kf_add_high = 20;
    $kf_add_low = 35;
    $kf_add_base = 40;
    $kf_add_mid = 45;
    $kf_add_high2 = 45;

    // === ЛОГИКА 15M ===
    $KF = 0;

    if ($cena1 > $cena2) {
        if ($lov2 < $lov2_high) {
            $KF = 0;
        } else if ($lov2 < $lov2_low) {
            $KF = 0;
        } else {
            if ($PrOb1 > $prOb1_high) {
                if ($PrIzm1 < $prIzm1_high) {
                    $KF = $kf_high;
                } else if ($PrIzm1 < $prIzm1_low) {
                    $KF = $kf_high / 2;
                } else {
                    $KF = 0;
                }
            } else if ($PrOb1 > 15) {
                $KF = $kf_mid;
            } else if ($PrOb1 < 0) {
                $KF = $kf_low;
            } else {
                $KF = $kf_base;
            }
        }

        if (-0.02 < $PrIzm1 && $PrIzm1 < 0) {
            $KF = 0;
        } else if (-0.04 < $PrIzm1 && $PrIzm1 < 0) {
            $KF = 10;
        }

        if ($cena2 > $cena3) {
            if ($lov3 < $lov3_high) {
                $KF += 5;
            } else if ($lov3 < $lov3_low) {
                $KF += 5;
            } else {
                if ($PrOb2 > $prOb2_high) {
                    $KF += $kf_add_high;
                    if ($PrIzm2 < -0.13) {
                        $KF += 15;
                    }
                } else if ($PrOb2 < -20) {
                    $KF += $kf_add_low;
                } else if ($PrOb2 < 0) {
                    $KF += $kf_add_base;
                } else if ($PrOb2 < 15) {
                    $KF += $kf_add_mid;
                } else {
                    $KF += $kf_add_high2;
                }

                if ($PrOb2 > $prOb2_trigger) {
                    if ($PrIzm2 < $prIzm2_crash) {
                        $KF = 0;
                    } else {
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

    // === ВЫВОД И ЗАПИСЬ В БД ===
    echo '<td>';
    echo '<strong>' . $BTCUSDT . '</strong>: ' . round($KF, 1) . '%';
if ($KF > $signal_threshold) {
    $now = gmdate('Y-m-d H:i:s');
    // Округление вниз до 15-минутки
    $minute = (int)gmdate('i');
    $slot = floor($minute / 15) * 15;
    $slot_start = gmdate('Y-m-d H:') . str_pad($slot, 2, '0', STR_PAD_LEFT) . ':00';
    
    $check_query = mysqli_query($connection, "SELECT * FROM `oth_15m` 
        WHERE `Nazvanie` = '$BTCUSDT' 
        AND `data` >= '$slot_start' LIMIT 1");
    if (mysqli_num_rows($check_query) == 0) {
        $query = mysqli_query($connection, "INSERT INTO `oth_15m` 
            (`Nazvanie`, `kf`, `data`) 
            VALUES ('$BTCUSDT', " . round($KF, 1) . ", '$now');");
    }
} echo '</td>';
}
?>