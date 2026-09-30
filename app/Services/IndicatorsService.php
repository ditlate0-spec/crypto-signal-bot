<?php

namespace App\Services;

class IndicatorsService
{
    // Пороги
    private const RSI_MIN      = 15;
    private const CUM5_MIN     = -1.1;
    private const CUM5_MAX     = -0.30;
     
    private const BB_PCT_B_MIN = -0.15;
    private const BB_PCT_B_MAX = 0.80;

    // ============================================
    // Главный метод
    // ============================================
    public function check(array $h15, int $idx): array
    {
        if ($idx < 50) {
            return ['error' => 'too early', 'decision' => 'SKIP'];
        }

        $rsi      = $this->calcRsi($h15, $idx, 7);
        $cum5     = $this->calcCumRet($h15, $idx, 5);
        
        $bb_pct_b = $this->calcBBPctB($h15, $idx, 20, 2.0);

        $cond_rsi     = ($rsi !== null && $rsi > self::RSI_MIN);
        $cond_cum_min = ($cum5 !== null && $cum5 > self::CUM5_MIN);
        $cond_cum_max = ($cum5 !== null && $cum5 < self::CUM5_MAX);
        
        $cond_bb_min  = ($bb_pct_b !== null && $bb_pct_b > self::BB_PCT_B_MIN);
        $cond_bb_max  = ($bb_pct_b !== null && $bb_pct_b < self::BB_PCT_B_MAX);

        $decision = ($cond_rsi && $cond_cum_min && $cond_cum_max 
                      && $cond_bb_min && $cond_bb_max) 
                    ? 'TAKE' : 'SKIP';

        return [
            'rsi'         => $rsi !== null ? round($rsi, 2) : null,
            'cum5'        => $cum5 !== null ? round($cum5, 4) : null,
            'bb_pct_b'    => $bb_pct_b !== null ? round($bb_pct_b, 4) : null,
            'decision'    => $decision,
            'thresholds'  => [
                'rsi_min'      => self::RSI_MIN,
                'cum5_min'     => self::CUM5_MIN,
                'cum5_max'     => self::CUM5_MAX,                
                'bb_pct_b_min' => self::BB_PCT_B_MIN,
                'bb_pct_b_max' => self::BB_PCT_B_MAX,
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

    // cum5
    private function calcCumRet(array $h15, int $idx, int $period): ?float
    {
        if ($idx < $period) return null;
        $start = (float)$h15[$idx - $period][4];
        $end   = (float)$h15[$idx][4];
        return $start > 0 ? ($end - $start) / $start * 100 : 0;
    }

    // bulls10
    private function calcBulls(array $h15, int $idx, int $period): ?int
    {
        if ($idx < $period - 1) return null;
        $bulls = 0;
        for ($i = $idx - $period + 1; $i <= $idx; $i++) {
            if ((float)$h15[$i][4] > (float)$h15[$i][1]) $bulls++;
        }
        return $bulls;
    }

    // bb_pct_b (Bollinger Bands %B)
    private function calcBBPctB(array $h15, int $idx, int $period, float $mult): ?float
    {
        if ($idx < $period - 1) return null;

        $sum = 0;
        for ($i = $idx - $period + 1; $i <= $idx; $i++) $sum += (float)$h15[$i][4];
        $sma = $sum / $period;

        $var = 0;
        for ($i = $idx - $period + 1; $i <= $idx; $i++) {
            $var += ((float)$h15[$i][4] - $sma) ** 2;
        }
        $std = sqrt($var / $period);

        $upper = $sma + $mult * $std;
        $lower = $sma - $mult * $std;

        $range = $upper - $lower;
        if ($range <= 0) return 0.5;

        $close = (float)$h15[$idx][4];
        return ($close - $lower) / $range;
    }
}