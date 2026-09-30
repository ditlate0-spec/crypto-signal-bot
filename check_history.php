<?php
$h15 = json_decode(file_get_contents('/var/www/history_15m.json'), true);
$h1h = json_decode(file_get_contents('/var/www/history_1h.json'), true);
$h1d = json_decode(file_get_contents('/var/www/history_1d.json'), true);
$h15e = json_decode(file_get_contents('/var/www/history_15m_eth.json'), true);

echo '15m BTC: ' . count($h15) . ' свечей, ' . gmdate('Y-m-d H:i', $h15[0][0]/1000) . ' → ' . gmdate('Y-m-d H:i', $h15[count($h15)-1][0]/1000) . PHP_EOL;
echo '15m ETH: ' . count($h15e) . ' свечей, ' . gmdate('Y-m-d H:i', $h15e[0][0]/1000) . ' → ' . gmdate('Y-m-d H:i', $h15e[count($h15e)-1][0]/1000) . PHP_EOL;
echo '1h BTC:  ' . count($h1h) . ' свечей, ' . gmdate('Y-m-d H:i', $h1h[0][0]/1000) . ' → ' . gmdate('Y-m-d H:i', $h1h[count($h1h)-1][0]/1000) . PHP_EOL;
echo '1d BTC:  ' . count($h1d) . ' свечей, ' . gmdate('Y-m-d H:i', $h1d[0][0]/1000) . ' → ' . gmdate('Y-m-d H:i', $h1d[count($h1d)-1][0]/1000) . PHP_EOL;

echo PHP_EOL;

// Проверка: 15m свеча 2025-06-15 12:00 UTC
$testTs = strtotime('2025-06-15 12:00:00 UTC') * 1000;

// Бинарный поиск (как в бэктесте)
function findIdx($arr, $ts) {
    $lo = 0; $hi = count($arr) - 1; $found = -1;
    while ($lo <= $hi) {
        $mid = (int)(($lo + $hi) / 2);
        if ($arr[$mid][0] <= $ts) { $found = $mid; $lo = $mid + 1; }
        else { $hi = $mid - 1; }
    }
    return $found;
}

$i15 = findIdx($h15, $testTs);
$i1h = findIdx($h1h, $testTs);
$i1d = findIdx($h1d, $testTs);

echo "Тестовая 15m свеча: 2025-06-15 12:00 UTC" . PHP_EOL;
echo "  Найдена 15m: " . gmdate('Y-m-d H:i', $h15[$i15][0]/1000) . " (open=" . $h15[$i15][1] . ")" . PHP_EOL;
echo "  Найдена 1h:  " . gmdate('Y-m-d H:i', $h1h[$i1h][0]/1000) . " (open=" . $h1h[$i1h][1] . ")" . PHP_EOL;
echo "  Найдена 1d:  " . gmdate('Y-m-d H:i', $h1d[$i1d][0]/1000) . " (open=" . $h1d[$i1d][1] . ")" . PHP_EOL;