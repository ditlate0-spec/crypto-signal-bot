<?php
require_once 'conn.php';
$host = "localhost";
$dbuser = "root";
$dbpassword = "";
$dbname = "volta";
$connection = mysqli_connect($host, $dbuser, $dbpassword, $dbname);
mysqli_set_charset($connection, "utf8mb4");
$number = ['BTCUSDT', 'ETHUSDT'];
$current_hour_start = gmdate('Y-m-d H:00:00');

$check_cache = mysqli_query($connection, "SELECT * FROM `old_bot_signals_1h` 
    WHERE `created_at` >= '$current_hour_start' LIMIT 1");

if (mysqli_num_rows($check_cache) > 0) {
    $rows = mysqli_query($connection, "SELECT `symbol`, `kf`, `text` FROM `old_bot_signals_1h` 
        WHERE `created_at` >= '$current_hour_start'
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
    $response = sendRequest("api/v3/klines?symbol=$BTCUSDT&interval=1h&limit=4");
    usleep(100000);

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
    // ПОРОГИ ДЛЯ 1H
    // ============================================
    $KF = 0;
    $text = 'калибровка.';

    if ($cena1 > $cena2) {
        if ($lov2 < -1.5) {
            $KF = 0;
            $text = 'вероятность слива крайне мала.';
        } else if ($lov2 < -1) {
            $KF = 0;
            $text = 'вероятность слива мала.';
        } else {
            if ($PrOb1 > 130) {
                if ($PrIzm1 < 3) {
                    $KF = 10;
                    $text = 'слабый сигнал.';
                } else if ($PrIzm1 < 7) {
                    $KF = 5;
                    $text = 'очень слабый сигнал.';
                } else {
                    $KF = 0;
                    $text = 'слив был.';
                }
            } else if ($PrOb1 > 25) {
                $KF = 45;
                $text = 'средний сигнал.';
            } else if ($PrOb1 < 10) {
                $KF = 20;
                $text = 'слабый сигнал.';
            } else {
                $KF = 35;
                $text = 'базовый сигнал.';
            }
        }

        if (-0.1 < $PrIzm1 && $PrIzm1 < 0) {
            $KF = 0;
            $text = 'калибровка.';
        } else if (-0.2 < $PrIzm1 && $PrIzm1 < 0) {
            $KF = 5;
            $text = 'очень слабый сигнал.';
        }

        if ($cena2 > $cena3) {
            if ($lov3 < -1.5) {
                $text = 'вероятность слива мала.';
                $KF += 5;
            } else if ($lov3 < -1) {
                $text = 'вероятность слива мала. Но цена может падать и дальше.';
                $KF += 5;
            } else {
                if ($PrOb2 > 30) {
                    $KF += 55;
                    $text = 'вероятно слив.';
                } else if ($PrOb2 < -20) {
                    $KF += 5;
                    $text = 'слабый сигнал.';
                } else if ($PrOb2 < 0) {
                    $KF += 15;
                    $text = 'слабый сигнал.';
                } else if ($PrOb2 < 15) {
                    $KF += 35;
                    $text = 'средний сигнал.';
                } else {
                    $KF += 45;
                    $text = 'средний сигнал.';
                }

                if ($PrOb2 > 25) {
                    if ($PrIzm2 < -2) {
                        $text = 'слив был в том часу.';
                    } else {
                        if ($lov1 < -1) $KF -= 10;
                        if ($lov2 < -1) $KF -= 10;
                        if ($lov3 < -1) $KF -= 20;
                        $text = 'вероятно слив в этом часу.';
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
                    $text = 'слив в этом часу или следующем.';
                }
            }
        }
    }

    // ============================================
    // ЗАПИСЬ В БД + TELEGRAM
    // ============================================
    if ($KF > 45) {
        $current_hour_start = gmdate('Y-m-d H:00:00');

        $check_query = mysqli_query($connection, "SELECT * FROM `old_bot_signals_1h` 
            WHERE `symbol` = '$BTCUSDT' 
            AND `created_at` >= '$current_hour_start' LIMIT 1");
        if (mysqli_num_rows($check_query) == 0) {

            $text_escaped = mysqli_real_escape_string($connection, $text);
            $now = gmdate('Y-m-d H:i:s');
            $query = mysqli_query($connection, "INSERT INTO `old_bot_signals_1h` 
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