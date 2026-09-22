<?php
// ============================================
// BTCUSDT1D.PHP - ФИНАЛЬНАЯ ВЕРСИЯ 4.0
// ============================================

require_once 'conn.php';

// ============================================
// ФИНАЛЬНЫЕ ОПТИМАЛЬНЫЕ ПАРАМЕТРЫ
// ============================================

$lov2_high = -3;
$lov2_low = -4;
$prOb1_high = 12;
$kf_mid = 45;
$signal_threshold = -55;
$kf_add_high = 65;

$prIzm1_high = 0.001;
$prIzm1_low = 0.1;
$kf_high = 8;
$kf_low = 20;
$kf_base = 18;

$lov3_high = -20;
$lov3_low = -4;
$prOb2_high = 10;
$prOb2_mid = 18;
$kf_add_low = 40;
$kf_add_base = 8;
$prOb2_trigger = 15;
$prIzm2_crash = -6;
$lov_crash = -1.5;

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
$current_day_start = gmdate('Y-m-d 00:00:00');

echo "<!-- CHECK: current_day_start=$current_day_start -->";

$check_cache = mysqli_query($connection, "SELECT * FROM `oth_1d` 
    WHERE `data` >= '$current_day_start' LIMIT 1");

if (!$check_cache) {
    echo "<!-- QUERY FAILED: " . mysqli_error($connection) . " -->";
}

echo "<!-- ROWS: " . mysqli_num_rows($check_cache) . " -->";

if (mysqli_num_rows($check_cache) > 0) {
    $rows = mysqli_query($connection, "SELECT `Nazvanie`, `kf`, `data` FROM `oth_1d` 
        WHERE `data` >= '$current_day_start'");
    while ($row = mysqli_fetch_assoc($rows)) {
        echo "<!-- CACHED: " . $row['Nazvanie'] . " kf=" . $row['kf'] . " data=" . $row['data'] . " -->";
        echo '<td><strong>' . $row['Nazvanie'] . '</strong>: ' 
            . round($row['kf'], 1) . '%</td>';
    }
    return;
}
for($S = 0; $S < count($number); $S++) {
    $BTCUSDT = $number[$S];

    $response = sendRequest("api/v3/klines?symbol=$BTCUSDT&interval=1d&limit=4");
    usleep(20000);

    if(empty($response)) {
        echo "<tr><td colspan='2'>❌ Нет данных для $BTCUSDT</td></tr>";
        continue;
    }

    $d4 = DateTime::createFromFormat('U', $response[3][0]/1000);
    $d4->setTimezone(new DateTimeZone('Europe/Moscow'));
    $d4_format = $d4->format('d.m.Y H:i:s');

    $cena1 = (float)$response[0][4];
    $cena2 = (float)$response[1][4];
    $cena3 = (float)$response[2][4];

    $PrIzm1 = (($cena2 - $cena1) / $cena1) * 100;
    $PrIzm2 = (($cena3 - $cena2) / $cena2) * 100;

    $mincena1 = (float)$response[0][3];
    $mincena2 = (float)$response[1][3];
    $mincena3 = (float)$response[2][3];
    $lov1 = (($mincena1 - $cena1) / $cena1) * 100;
    $lov2 = (($mincena2 - $cena2) / $cena2) * 100;
    $lov3 = (($mincena3 - $cena3) / $cena3) * 100;

    $ob1 = (float)$response[0][5];
    $ob2 = (float)$response[1][5];
    $ob3 = (float)$response[2][5];

    $PrOb1 = (($ob2 - $ob1) / $ob1) * 100;
    $PrOb2 = (($ob3 - $ob2) / $ob2) * 100;

    echo '<td>';

    $KF = 0;

    if($cena1 > $cena2) {
        if($lov2 < $lov2_high) {
            $KF = 0;
        } else if($lov2 < $lov2_low) {
            $KF = 0;
        } else {
            if($PrOb1 > $prOb1_high) {
                if($PrIzm1 < $prIzm1_high) {
                    $KF = $kf_high;
                } else if($PrIzm1 < $prIzm1_low) {
                    $KF = $kf_high / 2;
                } else {
                    $KF = 0;
                }
            } else if($PrOb1 > 25) {
                $KF = $kf_mid;
            } else if($PrOb1 < 10) {
                $KF = $kf_low;
            } else {
                $KF = $kf_base;
            }
        }

        if($cena2 > $cena3) {
            if($lov3 < $lov3_high) {
                $KF += 5;
            } else if($lov3 < $lov3_low) {
                $KF += 5;
            } else {
                if($PrOb2 > $prOb2_high) {
                    $KF += $kf_add_high;
                } else if($PrOb2 < $prOb2_mid) {
                    $KF += $kf_add_low;
                } else {
                    $KF += $kf_add_base;
                }

                if($PrOb2 > $prOb2_trigger) {
                    if($PrIzm2 < $prIzm2_crash) {
                        $KF = 0;
                    } else {
                        if($lov1 < $lov_crash) $KF -= 10;
                        if($lov2 < $lov_crash) $KF -= 10;
                        if($lov3 < $lov_crash) $KF -= 15;

                        if($KF > $signal_threshold) {
                            $today = gmdate('Y-m-d');
                            $check_query = mysqli_query($connection, "SELECT * FROM `oth_1d` 
                                WHERE `Nazvanie` = '$BTCUSDT' 
                                AND DATE(`data`) = '$today' LIMIT 1");
                            if (mysqli_num_rows($check_query) == 0) {
                                $now = gmdate('Y-m-d H:i:s');
                                $query = mysqli_query($connection, "INSERT INTO `oth_1d` 
                                    (`Nazvanie`, `kf`, `data`) 
                                    VALUES ('$BTCUSDT', " . round($KF, 1) . ", '$now');");
                            }
                        }
                    }
                } else {
                    if($lov1 < -8) $KF -= 40;
                    else if($lov1 < -7) $KF -= 35;
                    else if($lov1 < -6) $KF -= 30;
                    else if($lov1 < -5) $KF -= 25;
                    else if($lov1 < -4) $KF -= 20;
                    else {
                        if($lov2 < -8) $KF -= 40;
                        else if($lov2 < -7) $KF -= 35;
                        else if($lov2 < -6) $KF -= 30;
                        else if($lov2 < -5) $KF -= 25;
                        else if($lov2 < -4) $KF -= 20;
                    }

                    if($KF > $signal_threshold) {
                        $today = gmdate('Y-m-d');
                        $check_query = mysqli_query($connection, "SELECT * FROM `oth_1d` 
                            WHERE `Nazvanie` = '$BTCUSDT' 
                            AND DATE(`data`) = '$today' LIMIT 1");
                        if (mysqli_num_rows($check_query) == 0) {
                            $now = gmdate('Y-m-d H:i:s');
                            $query = mysqli_query($connection, "INSERT INTO `oth_1d` 
                                (`Nazvanie`, `kf`, `data`) 
                                VALUES ('$BTCUSDT', " . round($KF, 1) . ", '$now');");
                        }
                    }
                }
            }
        }
    } else {
        $KF = 0;
    }

    echo '<strong>' . $BTCUSDT . '</strong>: ' . round($KF, 1) . '%';
    if($KF > $signal_threshold) {
        echo ' ⚠️';
    }
    echo '</td>';
    echo '</tr>';


    // ЗАПИСЬ В БД — ПОСЛЕ ВСЕГО РАСЧЁТА
    if($KF > $signal_threshold) {
        $today = gmdate('Y-m-d');
        $check_query = mysqli_query($connection, "SELECT * FROM `oth_1d` 
            WHERE `Nazvanie` = '$BTCUSDT' 
            AND DATE(`data`) = '$today' LIMIT 1");
        if (mysqli_num_rows($check_query) == 0) {
            $now = gmdate('Y-m-d H:i:s');
            mysqli_query($connection, "INSERT INTO `oth_1d` 
                (`Nazvanie`, `kf`, `data`) 
                VALUES ('$BTCUSDT', " . round($KF, 1) . ", '$now');");
        }
    }
    
}

// === ВРЕМЯ ДО СЛЕДУЮЩЕГО ОБНОВЛЕНИЯ ===
$response = sendRequest("api/v3/klines?symbol=BTCUSDT&interval=1m&limit=4");
usleep(20000);
$d4_timestamp = $response[3][0]/1000;
$d4_timestamp = $d4_timestamp + 60*15;
$d4 = ($d4_timestamp - time()) * 1000;

?>