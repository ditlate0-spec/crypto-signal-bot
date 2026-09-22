<?php
// ============================================
// 1. ПОДКЛЮЧЕНИЕ К БД
// ============================================
$host = "localhost";
$dbuser = "root";
$dbpassword = "";
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
    $cacheFile = 'C:\\xampp\\htdocs\\botcoin\\fear_greed_cache.json';
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
// ВРЕМЯ UTC
// ============================================
$now = new DateTime('now', new DateTimeZone('UTC'));
$current_minute = (int)$now->format('i');
$current_hour = (int)$now->format('H');

// ============================================
// НАША МОДЕЛЬ (LightGBM)
// ============================================
require_once 'coin/model_live.php';
$model_result = runModelCheck('BTCUSDT');

// ============================================
// ТОРГОВЫЙ БОТ (telegram_sender)
// ============================================
require 'coin/telegram_sender.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Торговый бот + Модель + Новости + Индекс</title>
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

    <h1>📊 Торговый бот + Модель + Новости + Индекс</h1>
    <div class="subtitle">Мониторинг сигналов + фильтр LightGBM</div>

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
         БЛОК 3: НАША МОДЕЛЬ (LightGBM)
         ============================================ -->
    <div class="section">
        <div class="section-title">🎯 Наша модель (фильтр TP/SL)</div>
        <?php if (isset($model_result['error'])): ?>
            <div style="color: #ff6b6b; font-size: 11px;">
                ❌ <?= htmlspecialchars($model_result['error']) ?>
            </div>
        <?php else:
            $prob = $model_result['probability_tp'];
            $dec  = $model_result['decision'];
            $prob_pct = round($prob * 100, 1);

            if ($dec === 'TAKE') {
                $color = '#3fb950';
                $emoji = '✅';
                $label = 'ВХОД РАЗРЕШЁН';
            } elseif ($dec === 'MAYBE') {
                $color = '#f0883e';
                $emoji = '⚠️';
                $label = 'ПОГРАНИЧНЫЙ';
            } else {
                $color = '#ff6b6b';
                $emoji = '❌';
                $label = 'ПРОПУСК';
            }
        ?>
            <div class="neural-grid">
                <div class="neural-item">
                    <div class="label">Вероятность TP</div>
                    <div class="value" style="color: <?= $color ?>;"><?= $prob_pct ?>%</div>
                </div>
                <div class="neural-item">
                    <div class="label">Решение</div>
                    <div class="value" style="color: <?= $color ?>;"><?= $emoji ?> <?= $label ?></div>
                </div>
                <div class="neural-item">
                    <div class="label">Порог</div>
                    <div class="value" style="color: #8b949e;"><?= $model_result['threshold'] ?></div>
                </div>
                <div class="neural-item">
                    <div class="label">Цена входа</div>
                    <div class="value" style="color: #f0f6fc;">$<?= number_format($model_result['entry_price'], 2) ?></div>
                </div>
                <div class="neural-item">
                    <div class="label">Свеча</div>
                    <div class="value" style="color: #8b949e; font-size: 10px;"><?= $model_result['signal_time'] ?></div>
                </div>
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
                <div class="kf-content" style="margin-top: 6px;">
                    <?= $output_1d ?>
                </div>
            </div>
            <div class="kf-card">
                <div class="tf">🕐 1 ЧАС</div>
                <div class="kf-content" style="margin-top: 6px;">
                    <?= $output_1h ?>
                </div>
            </div>
            <div class="kf-card">
                <div class="tf">⏱️ 15 МИНУТ</div>
                <div class="kf-content" style="margin-top: 6px;">
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
        <div class="layer" style="padding: 8px;">
            <div class="kf-grid">
                <?php
                $last_1d = mysqli_fetch_assoc(mysqli_query($connection,
                    "SELECT * FROM `oth_1d` ORDER BY `data` DESC LIMIT 1"));
                $hist_1d = mysqli_query($connection,
                    "SELECT `Nazvanie`, `kf`, `data` FROM `oth_1d` 
                     ORDER BY `data` DESC LIMIT 30");
                ?>
                <div class="kf-card">
                    <div class="tf">📊 1 ДЕНЬ</div>
                    <div class="kf-content" style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Монета</th>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">KF</th>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Дата UTC</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($last_1d): ?>
                                    <tr style="background: #1c2128;">
                                        <td><strong>⭐ <?= htmlspecialchars($last_1d['Nazvanie']) ?></strong></td>
                                        <td><span class="signal-tag <?= $last_1d['kf'] > 55 ? 'danger' : 'none' ?>"><?= round($last_1d['kf'], 1) ?>%</span></td>
                                        <td style="font-size: 10px; color: #8b949e;"><?= $last_1d['data'] ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php while ($row = mysqli_fetch_assoc($hist_1d)): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['Nazvanie']) ?></td>
                                        <td><span class="signal-tag <?= $row['kf'] > 55 ? 'danger' : 'none' ?>"><?= round($row['kf'], 1) ?>%</span></td>
                                        <td style="font-size: 10px; color: #8b949e;"><?= $row['data'] ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php
                $last_1h = mysqli_fetch_assoc(mysqli_query($connection,
                    "SELECT * FROM `oth_1h` ORDER BY `data` DESC LIMIT 1"));
                $hist_1h = mysqli_query($connection,
                    "SELECT `Nazvanie`, `kf`, `data` FROM `oth_1h` 
                     ORDER BY `data` DESC LIMIT 30");
                ?>
                <div class="kf-card">
                    <div class="tf">🕐 1 ЧАС</div>
                    <div class="kf-content" style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Монета</th>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">KF</th>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Дата UTC</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($last_1h): ?>
                                    <tr style="background: #1c2128;">
                                        <td><strong>⭐ <?= htmlspecialchars($last_1h['Nazvanie']) ?></strong></td>
                                        <td><span class="signal-tag <?= $last_1h['kf'] > 45 ? 'warning' : 'none' ?>"><?= round($last_1h['kf'], 1) ?>%</span></td>
                                        <td style="font-size: 10px; color: #8b949e;"><?= $last_1h['data'] ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php while ($row = mysqli_fetch_assoc($hist_1h)): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['Nazvanie']) ?></td>
                                        <td><span class="signal-tag <?= $row['kf'] > 45 ? 'warning' : 'none' ?>"><?= round($row['kf'], 1) ?>%</span></td>
                                        <td style="font-size: 10px; color: #8b949e;"><?= $row['data'] ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php
                $last_15m = mysqli_fetch_assoc(mysqli_query($connection,
                    "SELECT * FROM `oth_15m` ORDER BY `data` DESC LIMIT 1"));
                $hist_15m = mysqli_query($connection,
                    "SELECT `Nazvanie`, `kf`, `data` FROM `oth_15m` 
                     ORDER BY `data` DESC LIMIT 30");
                ?>
                <div class="kf-card">
                    <div class="tf">⏱️ 15 МИНУТ</div>
                    <div class="kf-content" style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Монета</th>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">KF</th>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Дата UTC</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($last_15m): ?>
                                    <tr style="background: #1c2128;">
                                        <td><strong>⭐ <?= htmlspecialchars($last_15m['Nazvanie']) ?></strong></td>
                                        <td><span class="signal-tag <?= $last_15m['kf'] > 45 ? 'warning' : 'none' ?>"><?= round($last_15m['kf'], 1) ?>%</span></td>
                                        <td style="font-size: 10px; color: #8b949e;"><?= $last_15m['data'] ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php while ($row = mysqli_fetch_assoc($hist_15m)): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['Nazvanie']) ?></td>
                                        <td><span class="signal-tag <?= $row['kf'] > 45 ? 'warning' : 'none' ?>"><?= round($row['kf'], 1) ?>%</span></td>
                                        <td style="font-size: 10px; color: #8b949e;"><?= $row['data'] ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ============================================
         БЛОК 6: СТАРЫЕ БОТЫ
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
         БЛОК 7: ИСТОРИЯ ПО ТРЁМ
         ============================================ -->
    <div class="history-section">
        <div class="header">📜 История по трём</div>
        <div class="layer" style="padding: 8px;">
            <div class="kf-grid">
                <?php
                $hist_old_1d = mysqli_query($connection,
                    "SELECT `symbol`, `kf`, `created_at` FROM `old_bot_signals_1d` 
                     ORDER BY `created_at` DESC LIMIT 30");
                ?>
                <div class="kf-card">
                    <div class="tf">📅 1 ДЕНЬ</div>
                    <div class="kf-content" style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Монета</th>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">KF</th>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Дата UTC</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($hist_old_1d) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($hist_old_1d)): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['symbol']) ?></td>
                                            <td><span class="signal-tag <?= $row['kf'] > 55 ? 'danger' : 'none' ?>"><?= round($row['kf'], 1) ?>%</span></td>
                                            <td style="font-size: 10px; color: #8b949e;"><?= $row['created_at'] ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="no-data">Нет данных</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php
                $hist_old_1h = mysqli_query($connection,
                    "SELECT `symbol`, `kf`, `created_at` FROM `old_bot_signals_1h` 
                     ORDER BY `created_at` DESC LIMIT 30");
                ?>
                <div class="kf-card">
                    <div class="tf">🕐 1 ЧАС</div>
                    <div class="kf-content" style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Монета</th>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">KF</th>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Дата UTC</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($hist_old_1h) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($hist_old_1h)): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['symbol']) ?></td>
                                            <td><span class="signal-tag <?= $row['kf'] > 45 ? 'warning' : 'none' ?>"><?= round($row['kf'], 1) ?>%</span></td>
                                            <td style="font-size: 10px; color: #8b949e;"><?= $row['created_at'] ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="no-data">Нет данных</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php
                $hist_old_15m = mysqli_query($connection,
                    "SELECT `symbol`, `kf`, `created_at` FROM `old_bot_signals_15m` 
                     ORDER BY `created_at` DESC LIMIT 30");
                ?>
                <div class="kf-card">
                    <div class="tf">⏱️ 15 МИНУТ</div>
                    <div class="kf-content" style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Монета</th>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">KF</th>
                                    <th style="text-align: left; padding: 4px 6px; border-bottom: 1px solid #30363d; color: #8b949e; font-size: 10px;">Дата UTC</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($hist_old_15m) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($hist_old_15m)): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['symbol']) ?></td>
                                            <td><span class="signal-tag <?= $row['kf'] > 45 ? 'warning' : 'none' ?>"><?= round($row['kf'], 1) ?>%</span></td>
                                            <td style="font-size: 10px; color: #8b949e;"><?= $row['created_at'] ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="no-data">Нет данных</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
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