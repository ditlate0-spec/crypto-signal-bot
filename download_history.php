<?php
// ============================================
// СКАЧИВАНИЕ ИСТОРИИ С BINANCE SPOT
// Формат: Binance klines (12 полей)
// Период: 2025-01-01 → сейчас
// ============================================

set_time_limit(0);
ini_set('memory_limit', '4G');

$BASE_URL = 'https://api.binance.com/api/v3/klines';
$START_TS = strtotime('2025-01-01 00:00:00 UTC') * 1000;   // ms
$END_TS   = time() * 1000;
$LIMIT    = 1000;

$TASKS = [
    ['symbol' => 'BTCUSDT', 'interval' => '15m', 'file' => __DIR__ . '/history_15m.json'],
    ['symbol' => 'ETHUSDT', 'interval' => '15m', 'file' => __DIR__ . '/history_15m_eth.json'],
    ['symbol' => 'BTCUSDT', 'interval' => '1h',  'file' => __DIR__ . '/history_1h.json'],
    ['symbol' => 'BTCUSDT', 'interval' => '1d',  'file' => __DIR__ . '/history_1d.json'],
];

function httpGet($url, $retries = 5) {
    for ($i = 0; $i < $retries; $i++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $out = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $out !== false) return $out;

        // 429 / 418 — rate limit
        if ($code === 429 || $code === 418) {
            $sleep = 5 + $i * 5;
            echo "   ⚠️ Rate limit ($code), sleep {$sleep}s...\n";
            sleep($sleep);
            continue;
        }

        echo "   ⚠️ HTTP $code, retry " . ($i + 1) . "/{$retries}...\n";
        sleep(2);
    }
    return false;
}

foreach ($TASKS as $task) {
    $symbol   = $task['symbol'];
    $interval = $task['interval'];
    $file     = $task['file'];

    echo "\n📥 {$symbol} {$interval} → " . basename($file) . "\n";

    $all     = [];
    $cursor  = $START_TS;
    $request = 0;

    while ($cursor < $END_TS) {
        $request++;
        $url = $BASE_URL . '?' . http_build_query([
            'symbol'    => $symbol,
            'interval'  => $interval,
            'startTime' => $cursor,
            'endTime'   => $END_TS,
            'limit'     => $LIMIT,
        ]);

        $raw = httpGet($url);
        if ($raw === false) {
            echo "   ❌ Не удалось получить данные после {$request} запросов\n";
            break;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || count($data) === 0) {
            echo "   ℹ️ Данные закончились (пустой ответ)\n";
            break;
        }

        foreach ($data as $c) $all[] = $c;

        $lastTs = (int)$data[count($data) - 1][0];
        $cursor = $lastTs + 1;

        if ($request % 10 === 0) {
            echo "   ... {$request} запросов, свечей: " . count($all) . "\n";
        }

        // Небольшая пауза, чтобы не ловить rate limit
        usleep(120000); // 120ms
    }

    file_put_contents($file, json_encode($all));

    $first = gmdate('Y-m-d H:i', $all[0][0] / 1000);
    $last  = gmdate('Y-m-d H:i', $all[count($all)-1][0] / 1000);
    echo "✅ Сохранено: " . count($all) . " свечей, {$first} → {$last}\n";
}

echo "\n🎉 Готово.\n";