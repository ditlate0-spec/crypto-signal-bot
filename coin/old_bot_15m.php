<?php
require_once 'conn.php';

$number = ['BTCUSDT', 'ETHUSDT'];

for ($S = 0; $S < count($number); $S++) {
    $BTCUSDT = $number[$S];
    $response = sendRequest("api/v3/klines?symbol=$BTCUSDT&interval=15m&limit=4");
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

    $KF = 0;
    $text = 'калибровка.';

    if ($cena1 > $cena2) {
        if ($lov2 < -0.40) {
            $text = 'вероятность слива крайне мала.';
            $KF = 0;
        } else if ($lov2 < -0.30) {
            $text = 'вероятность слива мала.';
            $KF = 0;
        } else {
            if ($PrOb1 > 80) {
                if ($PrIzm1 < -0.1) {
                    $KF = 7;
                    $text = 'слив возможен.';
                } else if ($PrIzm1 < -0.2) {
                    $KF = 3;
                    $text = 'слив возможен.';
                } else {
                    $text = 'слив был.';
                    $KF = 0;
                }
            } else if ($PrOb1 > 15) {
                $KF = 55;
                $text = 'вероятно слив.';
            } else if ($PrOb1 < 0) {
                $KF = 30;
                $text = 'слабый сигнал.';
            } else {
                $KF = 7;
                $text = 'средний сигнал.';
            }
        }

        if (-0.02 < $PrIzm1 && $PrIzm1 < 0) {
            $KF = 0;
            $text = 'калибровка.';
        } else if (-0.04 < $PrIzm1 && $PrIzm1 < 0) {
            $KF = 10;
            $text = 'слабый сигнал.';
        }

        if ($cena2 > $cena3) {
            if ($lov3 < -0.4) {
                $text = 'вероятность слива мала.';
                $KF += 5;
            } else if ($lov3 < -0.26) {
                $text = 'вероятность слива мала. Но цена может падать и дальше.';
                $KF += 5;
            } else {
                if ($PrOb2 > 30) {
                    $KF += 22;
                    if ($PrIzm2 < -0.13) {
                        $KF += 15;
                    }
                    $text = 'вероятно слив.';
                } else if ($PrOb2 < -20) {
                    $KF += 40;
                    $text = 'слабый сигнал.';
                } else if ($PrOb2 < 0) {
                    $KF += 37;
                    $text = 'слабый сигнал.';
                } else if ($PrOb2 < 15) {
                    $KF += 50;
                    $text = 'средний сигнал.';
                } else {
                    $KF += 50;
                    $text = 'средний сигнал.';
                }

                if ($PrOb2 > 25) {
                    if ($PrIzm2 < -0.5) {
                        $text = 'слив был в прошлые 15м.';
                    } else {
                        if ($lov1 < -0.18) $KF -= 15;
                        if ($lov2 < -0.18) $KF -= 20;
                        if ($lov3 < -0.18) $KF -= 30;
                        $text = 'вероятно слив в эти 15м.';
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
                    $text = 'слив в эти 15м или через 15м.';
                }
            }
        }
    }

    // ============================================
    // ЗАПИСЬ В БД + TELEGRAM
    // ============================================
    if ($KF > 45) {
        $minute = (int)gmdate('i');
        $slot = floor($minute / 15) * 15;
        $slot_start = gmdate('Y-m-d H:') . str_pad($slot, 2, '0', STR_PAD_LEFT) . ':00';

        $check_query = mysqli_query($connection, "SELECT * FROM `old_bot_signals_15m` 
            WHERE `symbol` = '$BTCUSDT' 
            AND `created_at` >= '$slot_start' LIMIT 1");
        if (mysqli_num_rows($check_query) == 0) {

            $text_escaped = mysqli_real_escape_string($connection, $text);
            $now = gmdate('Y-m-d H:i:s');
            $query = mysqli_query($connection, "INSERT INTO `old_bot_signals_15m` 
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