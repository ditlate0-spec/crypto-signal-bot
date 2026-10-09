<?php

namespace App\Services;

class IndicatorsService
{
    // Порог RSI
    private const RSI_MIN = 15;

    // ============================================
    // Главный метод
    // ============================================
    public function check(array $h15, int $idx): array
    {
        if ($idx < 50) {
            return ['error' => 'too early', 'decision' => 'SKIP'];
        }

        $rsi = $this->calcRsi($h15, $idx, 7);

        $cond_rsi = ($rsi !== null && $rsi > self::RSI_MIN);

        $decision = $cond_rsi ? 'TAKE' : 'SKIP';

        return [
            'rsi'          => $rsi !== null ? round($rsi, 2) : null,
            'decision'     => $decision,
            'rsi_decision' => $decision,   // алиас для бэктеста
            'thresholds'   => [
                'rsi_min' => self::RSI_MIN,
            ],
        ];
    }

    // ============================================
    // RSI(7)
    // ============================================
    private function calcRsi(array $h15, int $idx, int $period): ?float
    {
        if ($idx < $period) return null;
        $gains = 0; $losses = 0;
        for ($i = $idx - $period + 1; $i <= $idx; $i++) {
            $change = (float)$h15[$i][4] - (float)$h15[$i-1][4];
            if ($change > 0) $gains += $change;
            else $losses += abs($change);
        }
        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;
        if ($avgLoss == 0) return 100.0;
        return 100.0 - (100.0 / (1.0 + $avgGain / $avgLoss));
    }
}