<?php
$files = [
    'history_15m.json'     => ['15m BTC', 900000],
    'history_15m_eth.json' => ['15m ETH', 900000],
    'history_1h.json'      => ['1h BTC',  3600000],
    'history_1d.json'      => ['1d BTC',  86400000],
];

foreach ($files as $file => [$label, $expected_step]) {
    $h = json_decode(file_get_contents(__DIR__.'/'.$file), true);
    if (empty($h)) { echo "$label: ПУСТО\n"; continue; }

    $first = (int)$h[0][0];
    $last  = (int)$h[count($h)-1][0];
    $step  = (int)($h[1][0] - $h[0][0]);
    $unit  = strlen((string)$first) >= 13 ? 'ms' : 'sec';
    $div   = $unit === 'ms' ? 1000 : 1;

    echo "=== $label ($file) ===\n";
    echo "  count:  " . count($h) . "\n";
    echo "  unit:   $unit\n";
    echo "  first:  " . gmdate('Y-m-d H:i:s', intval($first/$div)) . " UTC\n";
    echo "  last:   " . gmdate('Y-m-d H:i:s', intval($last/$div)) . " UTC\n";
    echo "  step:   $step " . ($step === $expected_step ? "✅ OK" : "❌ ЖДЁМ $expected_step") . "\n\n";
}