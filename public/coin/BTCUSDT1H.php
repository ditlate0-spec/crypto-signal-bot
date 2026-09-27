<?php
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

// ============================================
// ОПТИМАЛЬНЫЕ ПОРОГИ ДЛЯ 1H (ВЕРСИЯ 3.0)
// ============================================

$number = ['BTCUSDT', 'ETHUSDT'];
// ============================================
$current_hour_start = gmdate('Y-m-d H:00:00');

$check_cache = mysqli_query($connection, "SELECT * FROM `oth_1h` 
    WHERE `data` >= '$current_hour_start' LIMIT 1");

if (mysqli_num_rows($check_cache) > 0) {
    $rows = mysqli_query($connection, "SELECT `Nazvanie`, `kf` FROM `oth_1h` 
        WHERE `data` >= '$current_hour_start'");
    while ($row = mysqli_fetch_assoc($rows)) {
        echo '<td><strong>' . $row['Nazvanie'] . '</strong>: ' 
            . round($row['kf'], 1) . '%</td>';
    }
    return;
}
for ($S = 0; $S < count($number); $S++) {
    $BTCUSDT = $number[$S];

    $response = sendRequest("api/v3/klines?symbol=$BTCUSDT&interval=1h&limit=4");
    usleep(20000);

    if (empty($response)) continue;

    // ============================================
    // ДАННЫЕ СВЕЧЕЙ
    // ============================================

$d3 = DateTime::createFromFormat('U', $response[2][0] / 1000);
$d3->setTimezone(new DateTimeZone('Europe/Moscow'));
$d3_format = $d3->format('d.m.Y H:i:s');
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
    // ОПТИМАЛЬНЫЕ ПОРОГИ (ВЕРСИЯ 3.0)
    // ============================================

    $lov2_high = -3;
    $lov2_low = 0;
    $prOb1_high = 150;
    $kf_mid = 50;
    $signal_threshold = -10;
    $kf_add_high = 40;
    $prIzm1_high = 3;
    $prIzm1_low = 5;
    $kf_high = 10;
    $kf_low = 20;
    $kf_base = 35;
    $lov3_high = -1.5;
    $lov3_low = -1;
    $prOb2_high = 35;
    $prOb2_mid = 20;
    $kf_add_low = 35;
    $kf_add_base = 45;
    $prOb2_trigger = 20;
    $prIzm2_crash = -2;
    $lov_crash = -1;

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
            } else if ($PrOb1 > 25) {
                $KF = $kf_mid;
            } else if ($PrOb1 < 10) {
                $KF = $kf_low;
            } else {
                $KF = $kf_base;
            }
        }

        if (-0.1 < $PrIzm1 && $PrIzm1 < 0) {
            $KF = 0;
        } else if (-0.2 < $PrIzm1 && $PrIzm1 < 0) {
            $KF = 5;
        }

        if ($cena2 > $cena3) {
            if ($lov3 < $lov3_high) {
                $KF += 5;
            } else if ($lov3 < $lov3_low) {
                $KF += 5;
            } else {
                if ($PrOb2 > $prOb2_high) {
                    $KF += $kf_add_high;
                } else if ($PrOb2 < -20) {
                    $KF += $kf_add_low;
                } else if ($PrOb2 < 0) {
                    $KF += 15;
                } else if ($PrOb2 < $prOb2_mid) {
                    $KF += 35;
                } else {
                    $KF += $kf_add_base;
                }

                if ($PrOb2 > $prOb2_trigger) {
                    if ($PrIzm2 < $prIzm2_crash) {
                        $KF = 0;
                    } else {
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

// === ВЫВОД И ЗАПИСЬ В БД ===

echo '<td>';
echo '<strong>' . $BTCUSDT . '</strong>: ' . round($KF, 1) . '%';
if ($KF > $signal_threshold) {
    $now = gmdate('Y-m-d H:i:s');
    $current_hour_start = gmdate('Y-m-d H:00:00');
    
    $check_query = mysqli_query($connection, "SELECT * FROM `oth_1h` 
        WHERE `Nazvanie` = '$BTCUSDT' 
        AND `data` >= '$current_hour_start' LIMIT 1");
    if (mysqli_num_rows($check_query) == 0) {
        $query = mysqli_query($connection, "INSERT INTO `oth_1h` 
            (`Nazvanie`, `kf`, `data`) 
            VALUES ('$BTCUSDT', " . round($KF, 1) . ", '$now');");
    }
} echo '</td>';
}
?>