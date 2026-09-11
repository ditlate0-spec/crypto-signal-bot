<?php
// ============================================
// 1. ПОДКЛЮЧЕНИЕ К БД
// ============================================
$host = getenv('DB_HOST') ?: 'localhost';
$dbuser = getenv('DB_USER') ?: 'root';
$dbpassword = getenv('DB_PASSWORD') ?: '';
$dbname = "volta";
$dbarticles = "oth";
$connection = mysqli_connect($host, $dbuser, $dbpassword, $dbname);
mysqli_set_charset($connection, "utf8mb4");

// ============================================
// ЗАГРУЗКА KF-СИГНАЛОВ
// ============================================
ob_start();
require 'coin/BTCUSDT1D.php';
$output_1d = ob_get_clean();

ob_start();
require 'coin/BTCUSDT1H.php';
$output_1h = ob_get_clean();

ob_start();
require 'coin/BTCUSDT15M.php';
$output_15m = ob_get_clean();

// Старый бот 15M
ob_start();
require 'coin/old_bot_15m.php';
$old_bot_15m = ob_get_clean();

// Старый бот 1D
ob_start();
require 'coin/old_bot_1D.php';
$old_bot_1d = ob_get_clean();

// Старый бот 1H
ob_start();
require 'coin/old_bot_1H.php';
$old_bot_1h = ob_get_clean();



require_once 'coin/news.php';
$crypto_news = getCryptoNewsSentiment('BTC');

// ============================================
// ПОЛУЧЕНИЕ ИНДЕКСА СТРАХА И ЖАДНОСТИ
// ============================================
function getFearGreedIndex() {
    $cacheFile = __DIR__ . '/fear_greed_cache.json';
    $cacheTime = 3600;
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached) return $cached;
    }
    
    $url = "https://api.alternative.me/fng/?limit=1";
    $response = @file_get_contents($url);
    
    if ($response === false) {
        return ['error' => 'Не удалось получить индекс'];
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['data'][0])) {
        return ['error' => 'Ошибка парсинга индекса'];
    }
    
    $item = $data['data'][0];
    
    $result = [
        'value' => (int)$item['value'],
        'value_classification' => $item['value_classification'],
        'timestamp' => (int)$item['timestamp']
    ];
    
    file_put_contents($cacheFile, json_encode($result));
    
    return $result;
}

$fear_greed = getFearGreedIndex();

// ============================================
// 2. ЛОГИКА ОТПРАВКИ В НЕЙРОСЕТЬ
// ============================================
$now = new DateTime('now', new DateTimeZone('UTC'));
$current_minute = (int)$now->format('i');
$current_hour = (int)$now->format('H');

$send_to_neural = true;
$timeframe = '15m';
$limit = 150;
$label = '';

// ============================================
// 3. ФУНКЦИЯ ПОЛУЧЕНИЯ СВЕЧЕЙ С BINANCE
// ============================================
function getCandlesFromBinance($symbol = 'BTCUSDT', $interval = '1h', $limit = 500) {
    $url = "https://api.binance.com/api/v3/klines?symbol={$symbol}&interval={$interval}&limit=" . ($limit + 1);
    $response = @file_get_contents($url);
    if ($response === false) {
        return ['error' => 'Не удалось получить данные с Binance'];
    }
    $data = json_decode($response, true);
    if (!$data) {
        return ['error' => 'Ошибка парсинга данных'];
    }
    $candles = [];
    for ($i = 0; $i < count($data) - 1; $i++) {
        $candle = $data[$i];
        $candles[] = [
            'open' => (float)$candle[1],
            'high' => (float)$candle[2],
            'low' => (float)$candle[3],
            'close' => (float)$candle[4],
            'volume' => (float)$candle[5],
            'timestamps' => date('Y-m-d H:i:s', $candle[0]/1000)
        ];
    }
    return $candles;
}

function callNeuralNetworkMulti($candles_15m, $candles_1h, $candles_1d, $symbol) {
    global $connection;
 file_put_contents(__DIR__ . '/python_debug.log', 
        "\n=== " . date('Y-m-d H:i:s') . " ===\nSTART\n", 
        FILE_APPEND);
    $payload = [];
    if (!empty($candles_15m)) $payload['15m'] = $candles_15m;
    if (!empty($candles_1h))  $payload['1h']  = $candles_1h;
    if (!empty($candles_1d))  $payload['1d']  = $candles_1d;

    if (empty($payload)) {
        return [];
    }

    $data = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $filePath = __DIR__ . '/Kronos-master/test_data.json';
    file_put_contents($filePath, $data);

$command = "cd " . __DIR__ . "/Kronos-master && HF_HOME=/tmp/hf_cache python3 kronos_analyzer.py 2>&1";
    $output = shell_exec($command);
  file_put_contents(__DIR__ . '/python_debug.log', 
        "COMMAND: $command\n" .
        "OUTPUT: " . var_export($output, true) . "\n", 
        FILE_APPEND);
    $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');
    $output = trim($output);

    if (preg_match('/\{.*\}/s', $output, $matches)) {
        $output = $matches[0];
    }

    $result = json_decode($output, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Ошибка парсинга JSON: ' . json_last_error_msg(), 'raw' => $output];
    }
  file_put_contents(__DIR__ . '/python_debug.log', 
        "JSON decoded OK, keys: " . implode(',', array_keys($result)) . "\n", 
        FILE_APPEND);
    if (isset($result['error'])) {
        return $result;
    }

    foreach (['15m', '1h', '1d'] as $tf) {
        if (!isset($result[$tf]) || isset($result[$tf]['error'])) {
            continue;
        }

        $r = $result[$tf];
        $symbol_escaped = mysqli_real_escape_string($connection, $symbol);
        $tf_escaped     = mysqli_real_escape_string($connection, $tf);
        $signal_escaped = mysqli_real_escape_string($connection, $r['signal'] ?? 'NONE');
        $json_data      = mysqli_real_escape_string($connection, json_encode($r, JSON_UNESCAPED_UNICODE));
        $created_at     = gmdate('Y-m-d H:i:s');

      $query = "INSERT INTO `neural_predictions` 
                  (`symbol`, `timeframe`, `signal`, `confidence`, `change_percent`, `current_price`, `future_price`, `candles_analyzed`, `json_data`, `created_at`) 
                  VALUES 
                  ('$symbol_escaped', '$tf_escaped', '$signal_escaped', 
                   '{$r['confidence']}', '{$r['change_percent']}', '{$r['current_price']}', 
                   '{$r['future_price']}', '{$r['candles_analyzed']}', '$json_data', '$created_at')";

        $insert_result = mysqli_query($connection, $query);

        file_put_contents(__DIR__ . '/python_debug.log', 
            "TF: $tf\nQUERY: $query\nRESULT: " . var_export($insert_result, true) . "\n" .
            "ERROR: " . mysqli_error($connection) . "\n", 
            FILE_APPEND);

    }

    return $result;
}

// ============================================
// 5. ПОЛУЧЕНИЕ ПОСЛЕДНЕГО ПРОГНОЗА ИЗ БД
// ============================================
function getLastPrediction($symbol, $timeframe) {
    global $connection;
    $symbol_escaped = mysqli_real_escape_string($connection, $symbol);
    $timeframe_escaped = mysqli_real_escape_string($connection, $timeframe);
    
    $query = "SELECT * FROM `neural_predictions` 
              WHERE `symbol` = '$symbol_escaped' AND `timeframe` = '$timeframe_escaped' 
              ORDER BY `created_at` DESC LIMIT 1";
    $result = mysqli_query($connection, $query);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $data = json_decode($row['json_data'], true);
        if ($data) {
            $data['created_at'] = $row['created_at'];
            return $data;
        }
    }
    return null;
}

// ============================================
// 6. ВЫПОЛНЯЕМ ОТПРАВКУ — С КЭШЕМ ПО ТАЙМФРЕЙМАМ
// ============================================
$symbol = 'BTCUSDT';
$neural_result = null;

if ($send_to_neural) {

    $now_utc = gmdate('Y-m-d H:i:s');

    $check_1h = mysqli_query($connection, "SELECT `id` FROM `neural_predictions` 
        WHERE `symbol` = '$symbol' AND `timeframe` = '1h' 
        AND `created_at` >= DATE_SUB('$now_utc', INTERVAL 1 HOUR) LIMIT 1");
    $need_1h = (mysqli_num_rows($check_1h) == 0);

    $check_1d = mysqli_query($connection, "SELECT `id` FROM `neural_predictions` 
        WHERE `symbol` = '$symbol' AND `timeframe` = '1d' 
        AND `created_at` >= DATE_SUB('$now_utc', INTERVAL 1 DAY) LIMIT 1");
    $need_1d = (mysqli_num_rows($check_1d) == 0);

    $candles_15m = getCandlesFromBinance($symbol, '15m', 150);
    $candles_1h  = $need_1h ? getCandlesFromBinance($symbol, '1h', 500) : [];
    $candles_1d  = $need_1d ? getCandlesFromBinance($symbol, '1d', 60)  : [];

    $result_all = callNeuralNetworkMulti($candles_15m, $candles_1h, $candles_1d, $symbol);

    $neural_result = isset($result_all['15m']) ? $result_all['15m'] : null;
}
$pred_1d = getLastPrediction('BTCUSDT', '1d');
$pred_1h = getLastPrediction('BTCUSDT', '1h');
$pred_15m = getLastPrediction('BTCUSDT', '15m');
require 'coin/telegram_sender.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Торговый бот + Нейросеть + Новости + Индекс</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0d1117;
            color: #e6edf3;
            padding: 8px;
            font-size: 12px;
            -webkit-font-smoothing: antialiased;
        }
        .container { max-width: 1100px; margin: 0 auto; }
        h1 {
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 2px;
            background: linear-gradient(90deg, #f7931a, #ff6b35);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .subtitle {
            color: #8b949e;
            margin-bottom: 10px;
            font-size: 11px;
        }

    .section {
    background: #161b22;
    border-radius: 6px;
    padding: 8px 10px;
    border: 1px solid #30363d;
    margin-bottom: 8px;
}

.section-title {
    font-size: 11px;
    font-weight: 700;
    color: #f0f6fc;
    margin-bottom: 6px;
    padding-bottom: 4px;
    border-bottom: 1px solid #30363d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

       .fg-value {
    font-size: 14px;
    font-weight: 800;
    letter-spacing: -0.3px;
    line-height: 1.2;
}
        .fg-hint {
    font-size: 11px;
    color: #8b949e;
    margin-top: 2px;
}
        .fg-detail {
    font-size: 11px;
    color: #8b949e;
    margin-top: 4px;
}
        .fg-time {
    font-size: 10px;
    color: #30363d;
    margin-top: 4px;
}

      .news-counters {
    font-size: 11px;
    margin-bottom: 4px;
}
.news-list {
    max-height: 120px;
    overflow-y: auto;
    border-top: 1px solid #30363d;
    padding-top: 4px;
}
.news-item {
    font-size: 10px;
    color: #c9d1d9;
    margin-bottom: 3px;
    padding: 3px 6px;
    background: #0d1117;
    border-radius: 3px;
    border-left: 2px solid;
    line-height: 1.2;
}
.news-item .source {
    font-size: 8px;
    color: #30363d;
    margin-top: 1px;
}

      .neural-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(90px, 1fr));
    gap: 4px;
}
.neural-item {
    background: #0d1117;
    padding: 4px 8px;
    border-radius: 4px;
    border: 1px solid #21262d;
}
.neural-item .label {
    font-size: 8px;
    color: #8b949e;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.neural-item .value {
    font-size: 12px;
    font-weight: 700;
    margin-top: 1px;
}

        .kf-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        .kf-card {
            background: #0d1117;
            border-radius: 6px;
            padding: 8px 10px;
            border: 1px solid #21262d;
        }
        .kf-card .tf {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #8b949e;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .kf-content {
            font-size: 11px;
            color: #c9d1d9;
            line-height: 1.3;
        }

        .history-section {
            background: #161b22;
            border-radius: 8px;
            border: 1px solid #30363d;
            margin-bottom: 10px;
        }
        .history-section .header {
            padding: 8px 12px;
            background: #0d1117;
            border-bottom: 1px solid #30363d;
            font-weight: 700;
            font-size: 12px;
            color: #f0f6fc;
            border-radius: 8px 8px 0 0;
        }
        .layer {
            overflow-x: auto;
            max-height: 260px;
            overflow-y: auto;
        }
        .layer::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .layer::-webkit-scrollbar-track {
            background: #0d1117;
        }
        .layer::-webkit-scrollbar-thumb {
            background: #30363d;
            border-radius: 3px;
        }
        .layer::-webkit-scrollbar-thumb:hover {
            background: #484f58;
        }
        .layer table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }
        .layer th {
            background: #0d1117;
            color: #8b949e;
            font-weight: 600;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
            padding: 5px 8px;
            border-bottom: 1px solid #30363d;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .layer td {
            padding: 5px 8px;
            border-bottom: 1px solid #21262d;
            color: #c9d1d9;
            font-size: 11px;
        }
        .layer tr:hover td { background: #1c2128; }

        .signal-tag {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 600;
            white-space: nowrap;
        }
        .signal-tag.danger { background: #2d1b1b; color: #ff6b6b; }
        .signal-tag.warning { background: #2d2416; color: #f0883e; }
        .signal-tag.safe { background: #162b3d; color: #58a6ff; }
        .signal-tag.none { background: #1c1c1c; color: #8b949e; }

        .no-data { text-align: center; padding: 20px; color: #8b949e; font-style: italic; font-size: 11px; }
        .update-info {
            text-align: right;
            font-size: 10px;
            color: #8b949e;
            margin-top: 4px;
        }

    @media (max-width: 768px) {
        .kf-grid { grid-template-columns: 1fr; }
    }
    </style>
</head>
<body>
<div class="container">

    <h1>📊 Торговый бот + Нейросеть + Новости + Индекс</h1>
    <div class="subtitle">Мониторинг сигналов + прогноз Kronos</div>

    <!-- ============================================
         БЛОК 1: ИНДЕКС СТРАХА И ЖАДНОСТИ
         ============================================ -->
    <div class="section">
        <div class="section-title">📌 Индекс страха и жадности</div>
        <?php if ($fear_greed && !isset($fear_greed['error'])): ?>
            <?php 
            $fg_value = $fear_greed['value'];
            
            if ($fg_value <= 25) {
                $fg_color = '#3fb950';
                $fg_text = 'ЦЕНА ПАДАЕТ';
                $fg_hint = 'Экстремальный страх — возможно дно';
            } elseif ($fg_value <= 45) {
                $fg_color = '#58a6ff';
                $fg_text = 'ВОЗМОЖНО ПАДАЕТ';
                $fg_hint = 'Страх на рынке';
            } elseif ($fg_value <= 55) {
                $fg_color = '#8b949e';
                $fg_text = 'НЕОПРЕДЕЛЁННОСТЬ';
                $fg_hint = 'Рынок в боковике';
            } elseif ($fg_value <= 75) {
                $fg_color = '#f0883e';
                $fg_text = 'ВОЗМОЖНО РАСТЁТ';
                $fg_hint = 'Жадность на рынке';
            } else {
                $fg_color = '#ff6b6b';
                $fg_text = 'ЦЕНА РАСТЁТ';
                $fg_hint = 'Экстремальная жадность — возможен разворот';
            }
            ?>
            <div class="fg-value" style="color: <?= $fg_color ?>;"><?= $fg_text ?></div>
            <div class="fg-hint"><?= $fg_hint ?></div>
            <div class="fg-detail">Значение: <strong style="color: <?= $fg_color ?>;"><?= $fg_value ?></strong> / 100</div>
            <div class="fg-time">🕐 <?= date('d.m.Y H:i', $fear_greed['timestamp']) ?></div>
        <?php else: ?>
            <div style="color: #ff6b6b;">❌ <?= $fear_greed['error'] ?? 'Нет данных' ?></div>
        <?php endif; ?>
    </div>

    <!-- ============================================
         БЛОК 2: НОВОСТИ
         ============================================ -->
    <div class="section">
        <div class="section-title">📰 Новости и тональность</div>
        <?php if ($crypto_news && !isset($crypto_news['error'])): ?>
            <div class="news-counters">
                <span style="color: #3fb950;">🟢 Позитивных: <?= $crypto_news['positive_count'] ?></span>
                &nbsp;&nbsp;
                <span style="color: #ff6b6b;">🔴 Негативных: <?= $crypto_news['negative_count'] ?></span>
                &nbsp;&nbsp;
                <span style="color: #8b949e;">⚪ Нейтральных: <?= $crypto_news['neutral_count'] ?></span>
            </div>
            <div class="news-list">
                <?php foreach ($crypto_news['headlines'] as $headline): ?>
                    <?php 
                    $h_sent = $headline['sentiment'];
                    $h_icon = $h_sent == 'positive' ? '🟢' : ($h_sent == 'negative' ? '🔴' : '⚪');
                    $h_color = $h_sent == 'positive' ? '#3fb950' : ($h_sent == 'negative' ? '#ff6b6b' : '#8b949e');
                    ?>
                    <div class="news-item" style="border-left-color: <?= $h_color ?>;">
                        <?= $h_icon ?> <?= htmlspecialchars($headline['title']) ?>
                        <div class="source">
                            <?= htmlspecialchars($headline['source']) ?> • <?= date('d.m H:i', strtotime($headline['date'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="color: #ff6b6b;">❌ <?= $crypto_news['error'] ?? 'Нет данных' ?></div>
        <?php endif; ?>
    </div>

    <!-- ============================================
         БЛОК 3: НЕЙРОСЕТЬ
         ============================================ -->
    <div class="section">
        <div class="section-title">🧠 Прогноз нейросети (Kronos)</div>
        <?php if ($send_to_neural && $neural_result && !isset($neural_result['error'])): ?>
            <div class="neural-grid">
          
                <div class="neural-item">
                    <div class="label">Уверенность</div>
                    <div class="value" style="color: #f0f6fc;"><?= round($neural_result['confidence'] * 100) ?>%</div>
                </div>
                <div class="neural-item">
                    <div class="label">Текущая цена</div>
                    <div class="value" style="color: #f0f6fc;">$<?= number_format($neural_result['current_price'], 2) ?></div>
                </div>
                <div class="neural-item">
                    <div class="label">Прогноз</div>
                    <div class="value" style="color: <?= $neural_result['change_percent'] > 0 ? '#58a6ff' : ($neural_result['change_percent'] < 0 ? '#ff6b6b' : '#f0883e') ?>">$<?= number_format($neural_result['future_price'], 2) ?></div>
                </div>
                <div class="neural-item">
                    <div class="label">Изменение</div>
                    <div class="value" style="color: <?= $neural_result['change_percent'] > 0 ? '#58a6ff' : ($neural_result['change_percent'] < 0 ? '#ff6b6b' : '#f0883e') ?>"><?= $neural_result['change_percent'] > 0 ? '+' : '' ?><?= $neural_result['change_percent'] ?>%</div>
                </div>
                <div class="neural-item">
                    <div class="label">Свечей</div>
                    <div class="value" style="color: #8b949e;"><?= $neural_result['candles_analyzed'] ?? $limit ?> (<?= $timeframe ?>)</div>
                </div>
            </div>
        <?php elseif ($send_to_neural && isset($neural_result['error'])): ?>
            <div style="color: #ff6b6b; font-size: 11px;">❌ <?= htmlspecialchars($neural_result['error']) ?></div>
        <?php else: ?>
            <div style="color: #8b949e; font-size: 11px;">
                ⏳ Следующий прогноз в 
                <?php
                $next = ceil($current_minute / 15) * 15;
                if ($next >= 60) { $next_hour = $current_hour + 1; $next_min = '00'; }
                else { $next_hour = $current_hour; $next_min = str_pad($next, 2, '0', STR_PAD_LEFT); }
                echo sprintf('%02d:%s', $next_hour, $next_min);
                ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============================================
         БЛОК 4: KF-СИГНАЛЫ
         ============================================ -->
    <div class="section">
        <div class="section-title">📊 KF-сигналы (вероятность падения)</div>
     <div class="kf-grid">
            <div class="kf-card">
                <div class="tf">📊 1 ДЕНЬ</div>
                <?php if ($pred_1d): ?>
                    <div class="kf-content">
                        <strong>Уверенность: <?= round($pred_1d['confidence'] * 100) ?>%</strong><br>
                        $<?= number_format($pred_1d['current_price'], 2) ?> → $<?= number_format($pred_1d['future_price'], 2) ?>
                        <span style="color: <?= $pred_1d['change_percent'] > 0 ? '#58a6ff' : ($pred_1d['change_percent'] < 0 ? '#ff6b6b' : '#f0883e') ?>">
                            (<?= $pred_1d['change_percent'] > 0 ? '+' : '' ?><?= $pred_1d['change_percent'] ?>%)
                        </span>
                        <div style="font-size: 9px; color: #30363d; margin-top: 2px;">
                            🕐 <?= date('d.m.Y H:i:s', strtotime($pred_1d['created_at'])) ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="color: #8b949e; font-size: 11px;">Нет данных</div>
                <?php endif; ?>
                <div class="kf-content" style="margin-top: 6px; padding-top: 6px; border-top: 1px solid #30363d;">
                    <?= $output_1d ?>
                </div>
            </div>
            <div class="kf-card">
                <div class="tf">🕐 1 ЧАС</div>
                <?php if ($pred_1h): ?>
                    <div class="kf-content">
                        <strong>Уверенность: <?= round($pred_1h['confidence'] * 100) ?>%</strong><br>
                        $<?= number_format($pred_1h['current_price'], 2) ?> → $<?= number_format($pred_1h['future_price'], 2) ?>
                        <span style="color: <?= $pred_1h['change_percent'] > 0 ? '#58a6ff' : ($pred_1h['change_percent'] < 0 ? '#ff6b6b' : '#f0883e') ?>">
                            (<?= $pred_1h['change_percent'] > 0 ? '+' : '' ?><?= $pred_1h['change_percent'] ?>%)
                        </span>
                        <div style="font-size: 9px; color: #30363d; margin-top: 2px;">
                            🕐 <?= date('d.m.Y H:i:s', strtotime($pred_1h['created_at'])) ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="color: #8b949e; font-size: 11px;">Нет данных</div>
                <?php endif; ?>
                <div class="kf-content" style="margin-top: 6px; padding-top: 6px; border-top: 1px solid #30363d;">
                    <?= $output_1h ?>
                </div>
            </div>
            <div class="kf-card">
                <div class="tf">⏱️ 15 МИНУТ</div>
                <?php if ($pred_15m): ?>
                    <div class="kf-content">
                        <strong>Уверенность: <?= round($pred_15m['confidence'] * 100) ?>%</strong><br>
                        $<?= number_format($pred_15m['current_price'], 2) ?> → $<?= number_format($pred_15m['future_price'], 2) ?>
                        <span style="color: <?= $pred_15m['change_percent'] > 0 ? '#58a6ff' : ($pred_15m['change_percent'] < 0 ? '#ff6b6b' : '#f0883e') ?>">
                            (<?= $pred_15m['change_percent'] > 0 ? '+' : '' ?><?= $pred_15m['change_percent'] ?>%)
                        </span>
                        <div style="font-size: 9px; color: #30363d; margin-top: 2px;">
                            🕐 <?= date('d.m.Y H:i:s', strtotime($pred_15m['created_at'])) ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="color: #8b949e; font-size: 11px;">Нет данных</div>
                <?php endif; ?>
                <div class="kf-content" style="margin-top: 6px; padding-top: 6px; border-top: 1px solid #30363d;">
                    <?= $output_15m ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================
         БЛОК 5: ИСТОРИЯ KF-СИГНАЛОВ
         ============================================ -->
    <div class="history-section">
        <div class="header">📜 История KF-сигналов</div>
        <div class="layer">
            <table>
                <thead>
                    <tr>
                        <th>Монета</th>
                        <th>1D</th>
                        <th>1H</th>
                        <th>15M</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $query = mysqli_query($connection, "
                    SELECT 
                        base.Nazvanie,
                        MAX(CASE WHEN src = '1d'  THEN kf END) AS kf_1d,
                        MAX(CASE WHEN src = '1h'  THEN kf END) AS kf_1h,
                        MAX(CASE WHEN src = '15m' THEN kf END) AS kf_15m,
                        MAX(base.dt) AS dt
                    FROM (
                        SELECT Nazvanie, kf, DATE(data) AS day, data AS dt, '1d'  AS src FROM `oth_1d`
                        UNION ALL
                        SELECT Nazvanie, kf, DATE(data) AS day, data AS dt, '1h'  AS src FROM `oth_1h`
                        UNION ALL
                        SELECT Nazvanie, kf, DATE(data) AS day, data AS dt, '15m' AS src FROM `oth_15m`
                    ) AS base
                    GROUP BY base.Nazvanie, base.day
                    ORDER BY base.day DESC
                    LIMIT 100
                ");
                if (mysqli_num_rows($query) > 0):
                    while ($row = mysqli_fetch_assoc($query)):
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($row['Nazvanie']) ?></strong></td>
                        <td><span class="signal-tag <?= $row['kf_1d'] > 55 ? 'danger' : 'none' ?>"><?= $row['kf_1d'] ? round($row['kf_1d'], 1) . '%' : '-' ?></span></td>
                        <td><span class="signal-tag <?= $row['kf_1h'] > 45 ? 'warning' : 'none' ?>"><?= $row['kf_1h'] ? round($row['kf_1h'], 1) . '%' : '-' ?></span></td>
                        <td><span class="signal-tag <?= $row['kf_15m'] > 45 ? 'warning' : 'none' ?>"><?= $row['kf_15m'] ? round($row['kf_15m'], 1) . '%' : '-' ?></span></td>
                        <td><?= htmlspecialchars($row['dt']) ?></td>
                    </tr>
                <?php
                    endwhile;
                else:
                ?>
                    <tr><td colspan="5" class="no-data">📭 Нет сохранённых сигналов</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============================================
         БЛОК 6: СТАРЫЕ БОТЫ (1D + 1H + 15M)
         ============================================ -->
    <div class="section">
        <div class="section-title">📊 Слив по трем (слив по тренду)</div>
        <div class="kf-grid">
            <div class="kf-card">
                <div class="tf">📊 1 ДЕНЬ</div>
                <div class="kf-content" style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Монета</th>
                                <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Сигнал</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?= $old_bot_1d ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="kf-card">
                <div class="tf">🕐 1 ЧАС</div>
                <div class="kf-content" style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Монета</th>
                                <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Сигнал</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?= $old_bot_1h ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="kf-card">
                <div class="tf">⏱️ 15 МИНУТ</div>
                <div class="kf-content" style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Монета</th>
                                <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Сигнал</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?= $old_bot_15m ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================
         БЛОК 7: ИСТОРИЯ СТАРЫХ СИГНАЛОВ
         ============================================ -->
    <div class="history-section">
        <div class="header">📜 История по трем</div>
        <div class="layer">
            <table>
                <thead>
                    <tr>
                        <th>Монета</th>
                        <th>1D</th>
                        <th>1H</th>
                        <th>15M</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $query_old = mysqli_query($connection, "
                    SELECT 
                        base.symbol,
                        MAX(CASE WHEN src = '1d'  THEN kf END) AS kf_1d,
                        MAX(CASE WHEN src = '1h'  THEN kf END) AS kf_1h,
                        MAX(CASE WHEN src = '15m' THEN kf END) AS kf_15m,
                        MAX(base.dt) AS dt
                    FROM (
                        SELECT symbol, kf, DATE(created_at) AS day, created_at AS dt, '1d'  AS src FROM `old_bot_signals_1d`
                        UNION ALL
                        SELECT symbol, kf, DATE(created_at) AS day, created_at AS dt, '1h'  AS src FROM `old_bot_signals_1h`
                        UNION ALL
                        SELECT symbol, kf, DATE(created_at) AS day, created_at AS dt, '15m' AS src FROM `old_bot_signals_15m`
                    ) AS base
                    GROUP BY base.symbol, base.day
                    ORDER BY base.day DESC
                    LIMIT 100
                ");
                if (mysqli_num_rows($query_old) > 0):
                    while ($row_old = mysqli_fetch_assoc($query_old)):
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($row_old['symbol']) ?></strong></td>
                        <td><span class="signal-tag <?= !empty($row_old['kf_1d']) ? 'danger' : 'none' ?>"><?= $row_old['kf_1d'] ? round($row_old['kf_1d'], 1) . '%' : '-' ?></span></td>
                        <td><span class="signal-tag <?= !empty($row_old['kf_1h']) ? 'warning' : 'none' ?>"><?= $row_old['kf_1h'] ? round($row_old['kf_1h'], 1) . '%' : '-' ?></span></td>
                        <td><span class="signal-tag <?= !empty($row_old['kf_15m']) ? 'warning' : 'none' ?>"><?= $row_old['kf_15m'] ? round($row_old['kf_15m'], 1) . '%' : '-' ?></span></td>
                        <td><?= htmlspecialchars($row_old['dt']) ?></td>
                    </tr>
                <?php
                    endwhile;
                else:
                ?>
                    <tr><td colspan="5" class="no-data">📭 Нет сохранённых сигналов</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="update-info">🔄 Автообновление каждые 15 минут (в 00, 15, 30, 45)</div>

</div>

<script>
function scheduleReload() {
    const now = new Date();
    const minutes = now.getMinutes();
    
    let nextMinute = Math.ceil(minutes / 15) * 15;
    if (nextMinute === minutes) nextMinute += 15;
    if (nextMinute >= 60) nextMinute = 0;
    
    const target = new Date();
    target.setMinutes(nextMinute);
    target.setSeconds(0);
    target.setMilliseconds(0);
    
    if (nextMinute === 0) {
        target.setHours(now.getHours() + 1);
    }
    
    const delay = target.getTime() - now.getTime();
    
    console.log('📌 Следующее обновление: ' + target.toLocaleTimeString() + ' (через ' + Math.round(delay/1000) + ' секунд)');
    setTimeout(function(){ location.reload(); }, delay);
}

scheduleReload();
</script>

</body>
</html>