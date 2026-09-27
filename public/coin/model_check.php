<?php
// ============================================
// coin/model_check.php
// Обёртка для вызова Python-модели из PHP
// ============================================

define('PYTHON_EXE', 'python');
define('MODEL_SCRIPT', __DIR__ . '/predict_one.py');

/**
 * @param array $c1,c2,c3  Свечи: [ts_ms, open, high, low, close, vol]
 * @param float $entry_price
 * @param string $signal_time  UTC 'Y-m-d H:i:s'
 * @param array $kf_data
 * @return array|null
 */
function checkWithModel($c1, $c2, $c3, $entry_price, $signal_time, $kf_data)
{
    $input = [
        'candles' => [
            ['open'=>(float)$c1[1], 'high'=>(float)$c1[2], 'low'=>(float)$c1[3],
             'close'=>(float)$c1[4], 'vol'=>(float)$c1[5]],
            ['open'=>(float)$c2[1], 'high'=>(float)$c2[2], 'low'=>(float)$c2[3],
             'close'=>(float)$c2[4], 'vol'=>(float)$c2[5]],
            ['open'=>(float)$c3[1], 'high'=>(float)$c3[2], 'low'=>(float)$c3[3],
             'close'=>(float)$c3[4], 'vol'=>(float)$c3[5]],
        ],
        'entry_price' => (float)$entry_price,
        'signal_time' => $signal_time,
        'kf_data'     => $kf_data,
    ];

    $tmp_in  = sys_get_temp_dir() . '/sig_in_'  . getmypid() . '_' . mt_rand() . '.json';
    $tmp_out = sys_get_temp_dir() . '/sig_out_' . getmypid() . '_' . mt_rand() . '.json';

    file_put_contents($tmp_in, json_encode($input));

    $cmd = PYTHON_EXE . ' ' . escapeshellarg(MODEL_SCRIPT) . ' '
         . escapeshellarg($tmp_in) . ' ' . escapeshellarg($tmp_out) . ' 2>&1';

    exec($cmd, $output, $ret);

    $result = null;
    if ($ret === 0 && file_exists($tmp_out)) {
        $result = json_decode(file_get_contents($tmp_out), true);
    }

    @unlink($tmp_in);
    @unlink($tmp_out);

    return $result;
}