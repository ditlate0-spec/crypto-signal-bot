<?php

namespace App\Services;

class ModelService
{
    private string $pythonExe;
    private string $scriptPath;

    public function __construct()
    {
        $this->pythonExe  = config('services.model.python_exe', 'python');
        $this->scriptPath = base_path('python/predict_one.py');
    }

    /**
     * Спросить у модели вероятность TP.
     *
     * @param array $candles  [0 => [ts, open, high, low, close, vol], 1 => ..., 2 => ...]
     * @param float $entryPrice
     * @param string $signalTime  UTC 'Y-m-d H:i:s'
     * @param array $kfData       массив с kf-признаками
     * @return array|null         ['probability_tp' => 0.87, 'decision' => 'TAKE', 'threshold' => 0.84] или null
     */
    public function predict(array $candles, float $entryPrice, string $signalTime, array $kfData = []): ?array
    {
        if (!file_exists($this->scriptPath)) {
            \Log::error('[ModelService] script not found: ' . $this->scriptPath);
            return null;
        }

        // Собираем вход
        $input = [
            'candles' => array_map(fn($c) => [
                'open'  => (float)$c[1],
                'high'  => (float)$c[2],
                'low'   => (float)$c[3],
                'close' => (float)$c[4],
                'vol'   => (float)$c[5],
            ], $candles),
            'entry_price' => $entryPrice,
            'signal_time' => $signalTime,
            'kf_data'     => $this->normalizeKfData($kfData),
        ];

        // Временные файлы
        $tmpIn  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sig_in_'  . uniqid() . '.json';
        $tmpOut = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sig_out_' . uniqid() . '.json';

        file_put_contents($tmpIn, json_encode($input));

        // Команда
        $cmd = escapeshellarg($this->pythonExe)
             . ' ' . escapeshellarg($this->scriptPath)
             . ' ' . escapeshellarg($tmpIn)
             . ' ' . escapeshellarg($tmpOut)
             . ' 2>&1';

        exec($cmd, $output, $ret);

        $result = null;
        if ($ret === 0 && file_exists($tmpOut)) {
            $raw = file_get_contents($tmpOut);
            $result = json_decode($raw, true);

            if (!is_array($result) || !isset($result['probability_tp'])) {
                \Log::error('[ModelService] bad response: ' . $raw);
                $result = null;
            }
        } else {
            \Log::error('[ModelService] python failed: ' . implode("\n", $output));
        }

        @unlink($tmpIn);
        @unlink($tmpOut);

        return $result;
    }

    /**
     * Приводим kf-массив к нужному формату с дефолтами.
     */
    private function normalizeKfData(array $kfData): array
    {
        $defaults = [
            'kf'             => 0,
            'kf_btc_15m_oth' => 0,
            'kf_eth_15m_oth' => 0,
            'kf_eth_15m_old' => 0,
            'kf_btc_1h_oth'  => 0,
            'kf_btc_1h_old'  => 0,
            'kf_btc_1d_oth'  => 0,
            'kf_btc_1d_old'  => 0,
        ];

        return array_merge($defaults, array_intersect_key($kfData, $defaults));
    }
}