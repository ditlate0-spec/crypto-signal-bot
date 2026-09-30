<?php
$db = mysqli_connect('db', 'root', 'root', 'volta');
mysqli_set_charset($db, 'utf8mb4');

// Train — TP до 2026-03-01
$q1 = mysqli_query($db, "SELECT * FROM backtest_signals 
    WHERE result = 'TP' AND signal_time < '2026-03-01' 
    ORDER BY signal_time");
$train = [];
while ($r = mysqli_fetch_assoc($q1)) $train[] = $r;

// Test — все (TP+SL) с 2026-03-01
$q2 = mysqli_query($db, "SELECT * FROM backtest_signals 
    WHERE signal_time >= '2026-03-01' 
    ORDER BY signal_time");
$test = [];
while ($r = mysqli_fetch_assoc($q2)) $test[] = $r;

function write_csv($path, $rows) {
    if (empty($rows)) { file_put_contents($path, ''); return 0; }
    $fp = fopen($path, 'w');
    fputcsv($fp, array_keys($rows[0]));
    foreach ($rows as $r) fputcsv($fp, $r);
    fclose($fp);
    return count($rows);
}

$n1 = write_csv('/var/www/train_tp.csv', $train);
$n2 = write_csv('/var/www/test_all.csv', $test);

echo "✅ Train TP: {$n1} → /var/www/train_tp.csv\n";
echo "✅ Test all: {$n2} → /var/www/test_all.csv\n";