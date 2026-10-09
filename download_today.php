<?php
// ============================================
// УНИВЕРСАЛЬНАЯ ЗАГРУЗКА ИСТОРИИ
// Использование: php download_history.php [дней_назад]
// Пример:        php download_history.php 7
// ============================================

$days = isset($argv[1]) ? (int)$argv[1] : 7;
if ($days < 3) $days = 3;

$start_ms = (time() - $days * 86400) * 1000;
$end_ms   = round(microtime(true) * 1000);

echo "🔄 Загрузка истории за {$days} дней\n";
echo "   с " . gmdate('Y-m-d H:i', $start_ms / 1000) . " UTC";
echo "   по " . gmdate('Y-m-d H:i', $end_ms / 1000) . " UTC\n\n";

/**
 * Универсальная загрузка всех свечей через пагинацию.
 * Binance отдаёт максимум 1000 свечей за запрос.
 */
function fetchKlines($symbol, $interval, $start_ms, $end_ms) {
    $all = [];
    $cursor = $start_ms;

    while ($cursor < $end_ms) {
        $url = "https://api.binance.com/api/v3/klines?" . http_build_query([
            'symbol'    => $symbol,
            'interval'  => $interval,
            'startTime' => $cursor,
            'endTime'   => $end_ms,
            'limit'     => 1000
        ]);

        $ctx = stream_context_create([
            'http' => ['timeout' => 20, 'ignore_errors' => true]
        ]);
        $resp = @file_get_contents($url, false, $ctx);

        if ($resp === false) {
            echo "❌ Ошибка сети для {$symbol} {$interval}\n";
            break;
        }

        $chunk = json_decode($resp, true);
        if (!is_array($chunk) || count($chunk) === 0) break;

        $all = array_merge($all, $chunk);

        // Следующий курсор — на 1 мс позже последней свечи
        $last_open = (int)$chunk[count($chunk) - 1][0];
        $next = $last_open + 1;
        if ($next <= $cursor) break; // защита от зацикливания
        $cursor = $next;

        if (count($chunk) < 1000) break; // всё, что было — забрали
    }

    return $all;
}

$tasks = [
    ['BTCUSDT', '15m', 'history_15m.json'],
    ['ETHUSDT', '15m', 'history_15m_eth.json'],
    ['BTCUSDT', '1h',  'history_1h.json'],
    ['BTCUSDT', '1d',  'history_1d.json'],
];

foreach ($tasks as [$symbol, $interval, $file]) {
    echo "📥 {$symbol} {$interval}... ";
    $data = fetchKlines($symbol, $interval, $start_ms, $end_ms);

    if (empty($data)) {
        echo "❌ пусто\n";
        continue;
    }

    file_put_contents(__DIR__ . '/' . $file, json_encode($data));
    echo "✅ " . count($data) . " свечей -> {$file}\n";
}

echo "\n✅ Готово. Теперь можно запускать бэктест:\n";
echo "   php backtest_off_ML.php\n";