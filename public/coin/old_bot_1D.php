<?php
require_once 'conn.php';
// ПОДКЛЮЧЕНИЕ К БД
$host = "localhost";
$dbuser = "root";
$dbpassword = "";
$dbname = "volta";
$connection = mysqli_connect($host, $dbuser, $dbpassword, $dbname);
mysqli_set_charset($connection, "utf8mb4");

$number = ['BTCUSDT', 'ETHUSDT'];
 // ============================================
// КЭШ: уже считали сегодня?
// ============================================
$current_day_start = gmdate('Y-m-d 00:00:00');

$check_cache = mysqli_query($connection, "SELECT * FROM `old_bot_signals_1d` 
    WHERE `created_at` >= '$current_day_start' LIMIT 1");

if (mysqli_num_rows($check_cache) > 0) {
    $rows = mysqli_query($connection, "SELECT `symbol`, `kf`, `text` FROM `old_bot_signals_1d` 
        WHERE `created_at` >= '$current_day_start'
        ORDER BY `created_at` DESC");

    $printed = [];
    while ($row = mysqli_fetch_assoc($rows)) {
        if (in_array($row['symbol'], $printed)) continue;
        $printed[] = $row['symbol'];
        echo '<tr>';
        echo '<td><strong>' . $row['symbol'] . '</strong></td>';
        echo '<td>' . round($row['kf'], 1) . '% — ' . htmlspecialchars($row['text']) . '</td>';
        echo '</tr>';
    }
    return;
}

for ($S = 0; $S < count($number); $S++) {
    $BTCUSDT = $number[$S];
    $response = sendRequest("api/v3/klines?symbol=$BTCUSDT&interval=1d&limit=4");
    usleep(20000);

    if (empty($response) || !is_array($response)) {
        echo '<tr><td colspan="2">❌ Нет данных</td></tr>';
        continue;
    }

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
    // ПОРОГИ ДЛЯ 1D
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

    $KF = 0;
    $text = 'калибровка.';

    if ($cena1 > $cena2) {
        if ($lov2 < $lov2_high) {
            $KF = 0;
            $text = 'вероятность слива крайне мала.';
        } else if ($lov2 < $lov2_low) {
            $KF = 0;
            $text = 'вероятность слива мала.';
        } else {
            if ($PrOb1 > $prOb1_high) {
                if ($PrIzm1 < $prIzm1_high) {
                    $KF = $kf_high;
                    $text = 'слабый сигнал.';
                } else if ($PrIzm1 < $prIzm1_low) {
                    $KF = $kf_high / 2;
                    $text = 'очень слабый сигнал.';
                } else {
                    $KF = 0;
                    $text = 'слив был.';
                }
            } else if ($PrOb1 > 25) {
                $KF = $kf_mid;
                $text = 'средний сигнал.';
            } else if ($PrOb1 < 10) {
                $KF = $kf_low;
                $text = 'слабый сигнал.';
            } else {
                $KF = $kf_base;
                $text = 'базовый сигнал.';
            }
        }

        if ($cena2 > $cena3) {
            if ($lov3 < $lov3_high) {
                $KF += 5;
            } else if ($lov3 < $lov3_low) {
                $KF += 5;
            } else {
                if ($PrOb2 > $prOb2_high) {
                    $KF += $kf_add_high;
                } else if ($PrOb2 < $prOb2_mid) {
                    $KF += $kf_add_low;
                } else {
                    $KF += $kf_add_base;
                }

                if ($PrOb2 > $prOb2_trigger) {
                    if ($PrIzm2 < $prIzm2_crash) {
                        $KF = 0;
                        $text = 'слив был.';
                    } else {
                        if ($lov1 < $lov_crash) $KF -= 10;
                        if ($lov2 < $lov_crash) $KF -= 10;
                        if ($lov3 < $lov_crash) $KF -= 15;
                        $text = 'вероятно слив сегодня.';
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
                    $text = 'слив сегодня или завтра.';
                }
            }
        }
    } else {
        $KF = 0;
        $text = 'калибровка.';
    }

    // ============================================
    // ЗАПИСЬ В БД + TELEGRAM
    // ============================================
    if ($KF > $signal_threshold) {
        $today = gmdate('Y-m-d');

        $check_query = mysqli_query($connection, "SELECT * FROM `old_bot_signals_1d` 
            WHERE `symbol` = '$BTCUSDT' 
            AND DATE(`created_at`) = '$today' LIMIT 1");
        if (mysqli_num_rows($check_query) == 0) {

            $text_escaped = mysqli_real_escape_string($connection, $text);
            $now = gmdate('Y-m-d H:i:s');
            $query = mysqli_query($connection, "INSERT INTO `old_bot_signals_1d` 
                (`symbol`, `kf`, `text`, `created_at`) 
                VALUES ('$BTCUSDT', " . round($KF, 1) . ", '$text_escaped', '$now');");

        
   
        }
    }

    // === ВЫВОД ===
    echo '<tr>';
    echo '<td><strong>' . $BTCUSDT . '</strong></td>';
    echo '<td>' . round($KF, 1) . '% — ' . $text . '</td>';
    echo '</tr>';
}
?>