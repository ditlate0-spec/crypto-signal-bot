<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Торговый бот + Модель + Индекс</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0d1117; color: #e6edf3; padding: 8px; font-size: 12px;
            -webkit-font-smoothing: antialiased;
        }
        .container { max-width: 1100px; margin: 0 auto; }
        h1 {
            font-size: 18px; font-weight: 800; margin-bottom: 2px;
            background: linear-gradient(90deg, #f7931a, #ff6b35);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .subtitle { color: #8b949e; margin-bottom: 10px; font-size: 11px; }
        .section {
            background: #161b22; border-radius: 6px; padding: 8px 10px;
            border: 1px solid #30363d; margin-bottom: 8px;
        }
        .section-title {
            font-size: 11px; font-weight: 700; color: #f0f6fc;
            margin-bottom: 6px; padding-bottom: 4px; border-bottom: 1px solid #30363d;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .fg-value { font-size: 14px; font-weight: 800; line-height: 1.2; }
        .fg-hint { font-size: 11px; color: #8b949e; margin-top: 2px; }
        .fg-detail { font-size: 11px; color: #8b949e; margin-top: 4px; }
        .fg-time { font-size: 10px; color: #30363d; margin-top: 4px; }
        .neural-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(90px, 1fr)); gap: 4px;
        }
        .neural-item {
            background: #0d1117; padding: 4px 8px; border-radius: 4px; border: 1px solid #21262d;
        }
        .neural-item .label {
            font-size: 8px; color: #8b949e; text-transform: uppercase; letter-spacing: 0.3px;
        }
        .neural-item .value { font-size: 12px; font-weight: 700; margin-top: 1px; }
        .kf-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .kf-card {
            background: #0d1117; border-radius: 6px; padding: 8px 10px; border: 1px solid #21262d;
        }
        .kf-card .tf {
            font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px;
            color: #8b949e; font-weight: 600; margin-bottom: 4px;
        }
        .kf-content { font-size: 11px; color: #c9d1d9; line-height: 1.3; }
        .kf-content table { width: 100%; border-collapse: collapse; }
        .kf-content td { padding: 3px 0; color: #c9d1d9; font-size: 11px; }
        .history-section {
            background: #161b22; border-radius: 8px; border: 1px solid #30363d; margin-bottom: 10px;
        }
        .history-section .header {
            padding: 8px 12px; background: #0d1117; border-bottom: 1px solid #30363d;
            font-weight: 700; font-size: 12px; color: #f0f6fc; border-radius: 8px 8px 0 0;
        }
        .layer { overflow-x: auto; max-height: 260px; overflow-y: auto; padding: 8px; }
        .layer table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .layer th {
            background: #0d1117; color: #8b949e; font-weight: 600; font-size: 10px;
            text-transform: uppercase; text-align: left; padding: 5px 8px;
            border-bottom: 1px solid #30363d;
        }
        .layer td {
            padding: 5px 8px; border-bottom: 1px solid #21262d;
            color: #c9d1d9; font-size: 11px;
        }
        .signal-tag {
            display: inline-block; padding: 1px 6px; border-radius: 8px;
            font-size: 10px; font-weight: 600; white-space: nowrap;
        }
        .signal-tag.danger  { background: #2d1b1b; color: #ff6b6b; }
        .signal-tag.warning { background: #2d2416; color: #f0883e; }
        .signal-tag.none    { background: #1c1c1c; color: #8b949e; }
        .update-info { text-align: right; font-size: 10px; color: #8b949e; margin-top: 4px; }
        @media (max-width: 768px) { .kf-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="container">

    <h1>📊 Торговый бот + Модель + Индекс</h1>
    <div class="subtitle">Мониторинг сигналов + фильтр LightGBM</div>

    {{-- БЛОК 1: FEAR & GREED --}}
    <div class="section">
        <div class="section-title">📌 Индекс страха и жадности</div>
        @if($fearGreed && !isset($fearGreed['error']) && $fgDescribe)
            <div class="fg-value" style="color: {{ $fgDescribe['color'] }};">{{ $fgDescribe['text'] }}</div>
            <div class="fg-hint">{{ $fgDescribe['hint'] }}</div>
            <div class="fg-detail">
                Значение: <strong style="color: {{ $fgDescribe['color'] }};">{{ $fearGreed['value'] }}</strong> / 100
            </div>
            <div class="fg-time">🕐 {{ date('d.m.Y H:i', $fearGreed['timestamp']) }}</div>
        @else
            <div style="color: #ff6b6b;">❌ {{ $fearGreed['error'] ?? 'Нет данных' }}</div>
        @endif
    </div>
{{-- БЛОК: НОВОСТИ --}}
<div class="section">
    <div class="section-title">📰 Новости и тональность</div>
    @if($cryptoNews && !isset($cryptoNews['error']))
        <div class="news-counters">
            <span style="color: #3fb950;">🟢 Позитивных: {{ $cryptoNews['positive_count'] }}</span>
            &nbsp;&nbsp;
            <span style="color: #ff6b6b;">🔴 Негативных: {{ $cryptoNews['negative_count'] }}</span>
            &nbsp;&nbsp;
            <span style="color: #8b949e;">⚪ Нейтральных: {{ $cryptoNews['neutral_count'] }}</span>
        </div>
        <div class="news-list">
            @foreach($cryptoNews['headlines'] as $headline)
                @php
                    $h_sent  = $headline['sentiment'];
                    $h_icon  = $h_sent == 'positive' ? '🟢' : ($h_sent == 'negative' ? '🔴' : '⚪');
                    $h_color = $h_sent == 'positive' ? '#3fb950' : ($h_sent == 'negative' ? '#ff6b6b' : '#8b949e');
                @endphp
                <div class="news-item" style="border-left-color: {{ $h_color }};">
                    {{ $h_icon }} {{ $headline['title'] }}
                    <div class="source">
                        {{ $headline['source'] }}
                        @if(!empty($headline['date']))
                            • {{ date('d.m H:i', strtotime($headline['date'])) }}
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div style="color: #ff6b6b;">❌ {{ $cryptoNews['error'] ?? 'Нет данных' }}</div>
    @endif
</div>
    {{-- БЛОК 2: НАША МОДЕЛЬ --}}
    <div class="section">
        <div class="section-title">🎯 Наша модель (фильтр TP/SL)</div>
        @if(!$modelResult)
            <div style="color: #8b949e; font-size: 11px;">⏳ Модель недоступна</div>
        @else
            @php
                $prob = $modelResult['probability_tp'];
                $dec  = $modelResult['decision'];
                $probPct = round($prob * 100, 1);
                if ($dec === 'TAKE')       { $color = '#3fb950'; $emoji = '✅'; $label = 'ВХОД РАЗРЕШЁН'; }
                elseif ($dec === 'MAYBE')  { $color = '#f0883e'; $emoji = '⚠️'; $label = 'ПОГРАНИЧНЫЙ'; }
                else                       { $color = '#ff6b6b'; $emoji = '❌'; $label = 'ПРОПУСК'; }
            @endphp
            <div class="neural-grid">
                <div class="neural-item">
                    <div class="label">Вероятность TP</div>
                    <div class="value" style="color: {{ $color }};">{{ $probPct }}%</div>
                </div>
                <div class="neural-item">
                    <div class="label">Решение</div>
                    <div class="value" style="color: {{ $color }};">{{ $emoji }} {{ $label }}</div>
                </div>
                <div class="neural-item">
                    <div class="label">Порог</div>
                    <div class="value" style="color: #8b949e;">{{ $modelResult['threshold'] }}</div>
                </div>
            </div>
        @endif
    </div>

  {{-- БЛОК 3: KF-СИГНАЛЫ (живой расчёт Бота №1) --}}
<div class="section">
    <div class="section-title">📊 KF-сигналы (вероятность падения)</div>
    <div class="kf-grid">
        @foreach(['1d' => '📊 1 ДЕНЬ', '1h' => '🕐 1 ЧАС', '15m' => '⏱️ 15 МИНУТ'] as $tf => $label)
            <div class="kf-card">
                <div class="tf">{{ $label }}</div>
                <div class="kf-content">
                    <table>
                        <tbody>
                        @foreach(['BTCUSDT', 'ETHUSDT'] as $sym)
                            @php $row = $kfLive[$sym][$tf] ?? null; @endphp
                            <tr>
                                <td>
                                    <strong>{{ $sym }}</strong>:
                                    @if($row)
                                        {{ $row['kf'] }}%
                                    @else
                                        <span style="color:#8b949e;">нет данных</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
</div>
{{-- БЛОК 4: СЛИВ ПО ТРЁМ (без текстов) --}}
<div class="section">
    <div class="section-title">📊 Слив по трем (слив по тренду)</div>
    <div class="kf-grid">
        @foreach(['1d' => '📊 1 ДЕНЬ', '1h' => '🕐 1 ЧАС', '15m' => '⏱️ 15 МИНУТ'] as $tf => $label)
            <div class="kf-card">
                <div class="tf">{{ $label }}</div>
                <div class="kf-content">
                    <table>
                        <tbody>
                        @foreach(['BTCUSDT', 'ETHUSDT'] as $sym)
                            @php $row = $oldLive[$sym][$tf] ?? null; @endphp
                            <tr>
                                <td>
                                    <strong>{{ $sym }}</strong>:
                                    @if($row)
                                        {{ $row['kf'] }}%
                                    @else
                                        <span style="color:#8b949e;">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
</div>
    {{-- БЛОК 5: ИСТОРИЯ KF-СИГНАЛОВ --}}
    <div class="history-section">
        <div class="header">📜 История KF-сигналов</div>
        <div class="layer">
            <div class="kf-grid">
                <div class="kf-card">
                    <div class="tf">📊 1 ДЕНЬ</div>
                    <table>
                        <thead><tr><th>Монета</th><th>KF</th><th>Дата UTC</th></tr></thead>
                        <tbody>
                        @forelse($hist1d as $row)
                            <tr>
                                <td>{{ $row->Nazvanie }}</td>
                                <td><span class="signal-tag {{ $row->kf > 55 ? 'danger' : 'none' }}">{{ round($row->kf, 1) }}%</span></td>
                                <td style="font-size: 10px; color: #8b949e;">{{ $row->data }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" style="text-align:center;padding:20px;color:#8b949e;">Нет данных</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="kf-card">
                    <div class="tf">🕐 1 ЧАС</div>
                    <table>
                        <thead><tr><th>Монета</th><th>KF</th><th>Дата UTC</th></tr></thead>
                        <tbody>
                        @forelse($hist1h as $row)
                            <tr>
                                <td>{{ $row->Nazvanie }}</td>
                                <td><span class="signal-tag {{ $row->kf > 45 ? 'warning' : 'none' }}">{{ round($row->kf, 1) }}%</span></td>
                                <td style="font-size: 10px; color: #8b949e;">{{ $row->data }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" style="text-align:center;padding:20px;color:#8b949e;">Нет данных</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="kf-card">
                    <div class="tf">⏱️ 15 МИНУТ</div>
                    <table>
                        <thead><tr><th>Монета</th><th>KF</th><th>Дата UTC</th></tr></thead>
                        <tbody>
                        @forelse($hist15m as $row)
                            <tr>
                                <td>{{ $row->Nazvanie }}</td>
                                <td><span class="signal-tag {{ $row->kf > 45 ? 'warning' : 'none' }}">{{ round($row->kf, 1) }}%</span></td>
                                <td style="font-size: 10px; color: #8b949e;">{{ $row->data }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" style="text-align:center;padding:20px;color:#8b949e;">Нет данных</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- БЛОК 6: ИСТОРИЯ ПО ТРЁМ --}}
    <div class="history-section">
        <div class="header">📜 История по трём</div>
        <div class="layer">
            <div class="kf-grid">
                <div class="kf-card">
                    <div class="tf">📅 1 ДЕНЬ</div>
                    <table>
                        <thead><tr><th>Монета</th><th>KF</th><th>Дата UTC</th></tr></thead>
                        <tbody>
                        @forelse($histOld1d as $row)
                            <tr>
                                <td>{{ $row->symbol }}</td>
                                <td><span class="signal-tag {{ $row->kf > 55 ? 'danger' : 'none' }}">{{ round($row->kf, 1) }}%</span></td>
                                <td style="font-size: 10px; color: #8b949e;">{{ $row->created_at }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" style="text-align:center;padding:20px;color:#8b949e;">Нет данных</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="kf-card">
                    <div class="tf">🕐 1 ЧАС</div>
                    <table>
                        <thead><tr><th>Монета</th><th>KF</th><th>Дата UTC</th></tr></thead>
                        <tbody>
                        @forelse($histOld1h as $row)
                            <tr>
                                <td>{{ $row->symbol }}</td>
                                <td><span class="signal-tag {{ $row->kf > 45 ? 'warning' : 'none' }}">{{ round($row->kf, 1) }}%</span></td>
                                <td style="font-size: 10px; color: #8b949e;">{{ $row->created_at }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" style="text-align:center;padding:20px;color:#8b949e;">Нет данных</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="kf-card">
                    <div class="tf">⏱️ 15 МИНУТ</div>
                    <table>
                        <thead><tr><th>Монета</th><th>KF</th><th>Дата UTC</th></tr></thead>
                        <tbody>
                        @forelse($histOld15m as $row)
                            <tr>
                                <td>{{ $row->symbol }}</td>
                                <td><span class="signal-tag {{ $row->kf > 45 ? 'warning' : 'none' }}">{{ round($row->kf, 1) }}%</span></td>
                                <td style="font-size: 10px; color: #8b949e;">{{ $row->created_at }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" style="text-align:center;padding:20px;color:#8b949e;">Нет данных</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="update-info">🔄 Автообновление каждые 15 минут</div>

</div>

<script>
    setTimeout(function(){ location.reload(); }, 15 * 60 * 1000);
</script>

</body>
</html>