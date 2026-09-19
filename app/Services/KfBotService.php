<?php

namespace App\Services;

use App\Models\Oth15m;
use App\Models\Oth1h;
use App\Models\Oth1d;
use Illuminate\Support\Facades\Log;

class KfBotService
{
    // ============================================
    // ПОРОГИ ДЛЯ ЗАПИСИ В БД
    // ============================================
    private const THRESHOLD_1D  = -55;
    private const THRESHOLD_1H  = -10;
    private const THRESHOLD_15M = -50;

    public function __construct(
        private BinanceService $binance,
    ) {}

    // ============================================
    // ГЛАВНЫЙ МЕТОД — прогон для всех монет и ТФ
    // ============================================
    public function runAll(): array
    {
        $result = [];
        foreach (['BTCUSDT', 'ETHUSDT'] as $sym) {
            $result[$sym]['1d']  = $this->run1d($sym);
            $result[$sym]['1h']  = $this->run1h($sym);
            $result[$sym]['15m'] = $this->run15m($sym);
        }
        return $result;
    }

    // ============================================
    // 1 ДЕНЬ
    // ============================================
    public function run1d(string $symbol): array
    {
        // Кэш: уже считали сегодня?
        $dayStart = gmdate('Y-m-d 00:00:00');
        $cached = Oth1d::where('Nazvanie', $symbol)
            ->where('data', '>=', $dayStart)
            ->first();

        if ($cached) {
            return ['kf' => (float)$cached->kf, 'cached' => true];
        }

        // Считаем
        $candles = $this->binance->getCandles($symbol, '1d', 4);
        if (!$candles['success'] || count($candles['data']) < 3) {
            return ['kf' => 0, 'cached' => false, 'error' => 'no_candles'];
        }

        $kf = $this->calc1d($candles['data']);

        // Пишем в БД, если KF больше порога
        if ($kf > self::THRESHOLD_1D) {
            $today = gmdate('Y-m-d');
            $exists = Oth1d::where('Nazvanie', $symbol)
                ->whereDate('data', $today)
                ->exists();

            if (!$exists) {
                Oth1d::create([
                    'Nazvanie' => $symbol,
                    'kf'       => round($kf, 1),
                    'data'     => now('UTC'),
                ]);
                Log::info("[KfBot 1D] {$symbol} KF={$kf} → written to DB");
            }
        }

        return ['kf' => round($kf, 1), 'cached' => false];
    }

    // ============================================
    // 1 ЧАС
    // ============================================
    public function run1h(string $symbol): array
    {
        $hourStart = gmdate('Y-m-d H:00:00');
        $cached = Oth1h::where('Nazvanie', $symbol)
            ->where('data', '>=', $hourStart)
            ->first();

        if ($cached) {
            return ['kf' => (float)$cached->kf, 'cached' => true];
        }

        $candles = $this->binance->getCandles($symbol, '1h', 4);
        if (!$candles['success'] || count($candles['data']) < 3) {
            return ['kf' => 0, 'cached' => false, 'error' => 'no_candles'];
        }

        $kf = $this->calc1h($candles['data']);

        if ($kf > self::THRESHOLD_1H) {
            $exists = Oth1h::where('Nazvanie', $symbol)
                ->where('data', '>=', $hourStart)
                ->exists();

            if (!$exists) {
                Oth1h::create([
                    'Nazvanie' => $symbol,
                    'kf'       => round($kf, 1),
                    'data'     => now('UTC'),
                ]);
                Log::info("[KfBot 1H] {$symbol} KF={$kf} → written to DB");
            }
        }

        return ['kf' => round($kf, 1), 'cached' => false];
    }

    // ============================================
    // 15 МИНУТ
    // ============================================
    public function run15m(string $symbol): array
    {
        // Округляем текущее время до начала 15-минутки
        $minute = (int)gmdate('i');
        $slot = floor($minute / 15) * 15;
        $slotStart = gmdate('Y-m-d H:') . str_pad($slot, 2, '0', STR_PAD_LEFT) . ':00';

        $cached = Oth15m::where('Nazvanie', $symbol)
            ->where('data', '>=', $slotStart)
            ->first();

        if ($cached) {
            return ['kf' => (float)$cached->kf, 'cached' => true];
        }

        $candles = $this->binance->getCandles($symbol, '15m', 4);
        if (!$candles['success'] || count($candles['data']) < 3) {
            return ['kf' => 0, 'cached' => false, 'error' => 'no_candles'];
        }

        $kf = $this->calc15m($candles['data']);

        if ($kf > self::THRESHOLD_15M) {
            $exists = Oth15m::where('Nazvanie', $symbol)
                ->where('data', '>=', $slotStart)
                ->exists();

            if (!$exists) {
                Oth15m::create([
                    'Nazvanie' => $symbol,
                    'kf'       => round($kf, 1),
                    'data'     => now('UTC'),
                ]);
                Log::info("[KfBot 15M] {$symbol} KF={$kf} → written to DB");
            }
        }

        return ['kf' => round($kf, 1), 'cached' => false];
    }

    // ============================================
    // РАСЧЁТ KF — 1D
    // Логика 1-в-1 из BTCUSDT1D.php
    // ============================================
    private function calc1d(array $candles): float
    {
        [$cena1, $cena2, $cena3, $ob1, $ob2, $ob3, $mincena1, $mincena2, $mincena3] = $this->extract($candles);

        $PrIzm1 = (($cena2 - $cena1) / $cena1) * 100;
        $PrIzm2 = (($cena3 - $cena2) / $cena2) * 100;
        $PrOb1  = (($ob2 - $ob1) / $ob1) * 100;
        $PrOb2  = (($ob3 - $ob2) / $ob2) * 100;
        $lov1   = (($mincena1 - $cena1) / $cena1) * 100;
        $lov2   = (($mincena2 - $cena2) / $cena2) * 100;
        $lov3   = (($mincena3 - $cena3) / $cena3) * 100;

        $lov2_high = -3; $lov2_low = -4;
        $prOb1_high = 12; $kf_mid = 45; $kf_add_high = 65;
        $prIzm1_high = 0.001; $prIzm1_low = 0.1;
        $kf_high = 8; $kf_low = 20; $kf_base = 18;
        $lov3_high = -20; $lov3_low = -4;
        $prOb2_high = 10; $prOb2_mid = 18;
        $kf_add_low = 40; $kf_add_base = 8;
        $prOb2_trigger = 15; $prIzm2_crash = -6; $lov_crash = -1.5;

        $KF = 0;

        if ($cena1 > $cena2) {
            if ($lov2 < $lov2_high) {
                $KF = 0;
            } else if ($lov2 < $lov2_low) {
                $KF = 0;
            } else {
                if ($PrOb1 > $prOb1_high) {
                    if ($PrIzm1 < $prIzm1_high)      $KF = $kf_high;
                    else if ($PrIzm1 < $prIzm1_low)  $KF = $kf_high / 2;
                    else                              $KF = 0;
                } else if ($PrOb1 > 25)  $KF = $kf_mid;
                else if ($PrOb1 < 10)    $KF = $kf_low;
                else                     $KF = $kf_base;
            }

            if ($cena2 > $cena3) {
                if ($lov3 < $lov3_high)      $KF += 5;
                else if ($lov3 < $lov3_low)  $KF += 5;
                else {
                    if ($PrOb2 > $prOb2_high)      $KF += $kf_add_high;
                    else if ($PrOb2 < $prOb2_mid)  $KF += $kf_add_low;
                    else                            $KF += $kf_add_base;

                    if ($PrOb2 > $prOb2_trigger) {
                        if ($PrIzm2 < $prIzm2_crash) {
                            $KF = 0;
                        } else {
                            if ($lov1 < $lov_crash) $KF -= 10;
                            if ($lov2 < $lov_crash) $KF -= 10;
                            if ($lov3 < $lov_crash) $KF -= 15;
                        }
                    } else {
                        if ($lov1 < -8)      $KF -= 40;
                        else if ($lov1 < -7) $KF -= 35;
                        else if ($lov1 < -6) $KF -= 30;
                        else if ($lov1 < -5) $KF -= 25;
                        else if ($lov1 < -4) $KF -= 20;
                        else {
                            if ($lov2 < -8)      $KF -= 40;
                            else if ($lov2 < -7) $KF -= 35;
                            else if ($lov2 < -6) $KF -= 30;
                            else if ($lov2 < -5) $KF -= 25;
                            else if ($lov2 < -4) $KF -= 20;
                        }
                    }
                }
            }
        }

        return $KF;
    }

    // ============================================
    // РАСЧЁТ KF — 1H (из BTCUSDT1H.php)
    // ============================================
    private function calc1h(array $candles): float
    {
        [$cena1, $cena2, $cena3, $ob1, $ob2, $ob3, $mincena1, $mincena2, $mincena3] = $this->extract($candles);

        $PrIzm1 = (($cena2 - $cena1) / $cena1) * 100;
        $PrIzm2 = (($cena3 - $cena2) / $cena2) * 100;
        $PrOb1  = (($ob2 - $ob1) / $ob1) * 100;
        $PrOb2  = (($ob3 - $ob2) / $ob2) * 100;
        $lov1   = (($mincena1 - $cena1) / $cena1) * 100;
        $lov2   = (($mincena2 - $cena2) / $cena2) * 100;
        $lov3   = (($mincena3 - $cena3) / $cena3) * 100;

        $lov2_high = -3; $lov2_low = 0;
        $prOb1_high = 150; $kf_mid = 50; $kf_add_high = 40;
        $prIzm1_high = 3; $prIzm1_low = 5;
        $kf_high = 10; $kf_low = 20; $kf_base = 35;
        $lov3_high = -1.5; $lov3_low = -1;
        $prOb2_high = 35; $prOb2_mid = 20;
        $kf_add_low = 35; $kf_add_base = 45;
        $prOb2_trigger = 20; $prIzm2_crash = -2; $lov_crash = -1;

        $KF = 0;

        if ($cena1 > $cena2) {
            if ($lov2 < $lov2_high) {
                $KF = 0;
            } else if ($lov2 < $lov2_low) {
                $KF = 0;
            } else {
                if ($PrOb1 > $prOb1_high) {
                    if ($PrIzm1 < $prIzm1_high)      $KF = $kf_high;
                    else if ($PrIzm1 < $prIzm1_low)  $KF = $kf_high / 2;
                    else                              $KF = 0;
                } else if ($PrOb1 > 25)  $KF = $kf_mid;
                else if ($PrOb1 < 10)    $KF = $kf_low;
                else                     $KF = $kf_base;
            }

            if (-0.1 < $PrIzm1 && $PrIzm1 < 0)       $KF = 0;
            else if (-0.2 < $PrIzm1 && $PrIzm1 < 0)  $KF = 5;

            if ($cena2 > $cena3) {
                if ($lov3 < $lov3_high)      $KF += 5;
                else if ($lov3 < $lov3_low)  $KF += 5;
                else {
                    if ($PrOb2 > $prOb2_high)      $KF += $kf_add_high;
                    else if ($PrOb2 < -20)         $KF += $kf_add_low;
                    else if ($PrOb2 < 0)           $KF += 15;
                    else if ($PrOb2 < $prOb2_mid)  $KF += 35;
                    else                            $KF += $kf_add_base;

                    if ($PrOb2 > $prOb2_trigger) {
                        if ($PrIzm2 < $prIzm2_crash) {
                            $KF = 0;
                        } else {
                            if ($lov1 < $lov_crash) $KF -= 10;
                            if ($lov2 < $lov_crash) $KF -= 10;
                            if ($lov3 < $lov_crash) $KF -= 20;
                        }
                    } else {
                        if ($lov1 < -5)      $KF -= 40;
                        else if ($lov1 < -4) $KF -= 35;
                        else if ($lov1 < -3) $KF -= 30;
                        else if ($lov1 < -2) $KF -= 25;
                        else if ($lov1 < -1) $KF -= 20;
                        else {
                            if ($lov2 < -5)      $KF -= 40;
                            else if ($lov2 < -4) $KF -= 35;
                            else if ($lov2 < -3) $KF -= 30;
                            else if ($lov2 < -2) $KF -= 25;
                            else if ($lov2 < -1) $KF -= 20;
                        }
                    }
                }
            }
        }

        return $KF;
    }

    // ============================================
    // РАСЧЁТ KF — 15M (из BTCUSDT15M.php)
    // ============================================
    private function calc15m(array $candles): float
    {
        [$cena1, $cena2, $cena3, $ob1, $ob2, $ob3, $mincena1, $mincena2, $mincena3] = $this->extract($candles);

        $PrIzm1 = (($cena2 - $cena1) / $cena1) * 100;
        $PrIzm2 = (($cena3 - $cena2) / $cena2) * 100;
        $PrOb1  = (($ob2 - $ob1) / $ob1) * 100;
        $PrOb2  = (($ob3 - $ob2) / $ob2) * 100;
        $lov1   = (($mincena1 - $cena1) / $cena1) * 100;
        $lov2   = (($mincena2 - $cena2) / $cena2) * 100;
        $lov3   = (($mincena3 - $cena3) / $cena3) * 100;

        $lov2_high = -2.5; $lov2_low = -0.4;
        $prOb1_high = 70; $prIzm1_low = -0.9; $lov_crash = -0.3;
        $lov3_high = -1.2; $lov3_low = -0.6; $prOb2_high = 40;
        $prIzm2_crash = -0.5; $prIzm1_high = -0.3; $prOb2_trigger = 45;
        $kf_high = 10; $kf_mid = 70; $kf_low = 30; $kf_base = 8;
        $kf_add_high = 20; $kf_add_low = 35; $kf_add_base = 40;
        $kf_add_mid = 45; $kf_add_high2 = 45;

        $KF = 0;

        if ($cena1 > $cena2) {
            if ($lov2 < $lov2_high)       $KF = 0;
            else if ($lov2 < $lov2_low)   $KF = 0;
            else {
                if ($PrOb1 > $prOb1_high) {
                    if ($PrIzm1 < $prIzm1_high)      $KF = $kf_high;
                    else if ($PrIzm1 < $prIzm1_low)  $KF = $kf_high / 2;
                    else                              $KF = 0;
                } else if ($PrOb1 > 15)  $KF = $kf_mid;
                else if ($PrOb1 < 0)     $KF = $kf_low;
                else                     $KF = $kf_base;
            }

            if (-0.02 < $PrIzm1 && $PrIzm1 < 0)       $KF = 0;
            else if (-0.04 < $PrIzm1 && $PrIzm1 < 0)  $KF = 10;

            if ($cena2 > $cena3) {
                if ($lov3 < $lov3_high)      $KF += 5;
                else if ($lov3 < $lov3_low)  $KF += 5;
                else {
                    if ($PrOb2 > $prOb2_high) {
                        $KF += $kf_add_high;
                        if ($PrIzm2 < -0.13) $KF += 15;
                    } else if ($PrOb2 < -20)     $KF += $kf_add_low;
                    else if ($PrOb2 < 0)         $KF += $kf_add_base;
                    else if ($PrOb2 < 15)        $KF += $kf_add_mid;
                    else                          $KF += $kf_add_high2;

                    if ($PrOb2 > $prOb2_trigger) {
                        if ($PrIzm2 < $prIzm2_crash) {
                            $KF = 0;
                        } else {
                            if ($lov1 < $lov_crash) $KF -= 15;
                            if ($lov2 < $lov_crash) $KF -= 20;
                            if ($lov3 < $lov_crash) $KF -= 30;
                        }
                    } else {
                        if ($lov1 < -0.5)       $KF -= 40;
                        else if ($lov1 < -0.4)  $KF -= 35;
                        else if ($lov1 < -0.3)  $KF -= 30;
                        else if ($lov1 < -0.25) $KF -= 25;
                        else if ($lov1 < -0.2)  $KF -= 20;
                        else {
                            if ($lov2 < -0.5)       $KF -= 40;
                            else if ($lov2 < -0.4)  $KF -= 35;
                            else if ($lov2 < -0.3)  $KF -= 30;
                            else if ($lov2 < -0.25) $KF -= 25;
                            else if ($lov2 < -0.2)  $KF -= 20;
                        }
                    }
                }
            }
        }

        return $KF;
    }

    // ============================================
    // Извлекаем 9 значений из массива свечей
    // ============================================
    private function extract(array $candles): array
    {
        $c1 = $candles[0]; $c2 = $candles[1]; $c3 = $candles[2];
        return [
            (float)$c1[4], (float)$c2[4], (float)$c3[4],  // close
            (float)$c1[5], (float)$c2[5], (float)$c3[5],  // volume
            (float)$c1[3], (float)$c2[3], (float)$c3[3],  // low
        ];
    }
}