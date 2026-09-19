<?php

namespace App\Services;

use App\Models\OldBotSignal15m;
use App\Models\OldBotSignal1h;
use App\Models\OldBotSignal1d;
use Illuminate\Support\Facades\Log;

class KfCalculatorService
{
    // ============================================
    // ПОРОГИ ДЛЯ ЗАПИСИ В БД (бот №2 — old_bot_*)
    // ============================================
    private const THRESHOLD_1D  = -55;
    private const THRESHOLD_1H  = 45;
    private const THRESHOLD_15M = 45;

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
    // 1 ДЕНЬ — с кэшем и записью
    // ============================================
    public function run1d(string $symbol): array
    {
        $dayStart = gmdate('Y-m-d 00:00:00');

        $cached = OldBotSignal1d::where('symbol', $symbol)
            ->where('created_at', '>=', $dayStart)
            ->first();

        if ($cached) {
            return ['kf' => (float)$cached->kf, 'text' => $cached->text, 'cached' => true];
        }

        $candles = $this->binance->getCandles($symbol, '1d', 4);
        if (!$candles['success'] || count($candles['data']) < 3) {
            return ['kf' => 0, 'text' => 'нет данных', 'cached' => false];
        }

        $res  = $this->calc1d($candles['data']);
        $kf   = $res['kf'];
        $text = $res['text'];

        if ($kf > self::THRESHOLD_1D) {
            $today = gmdate('Y-m-d');
            $exists = OldBotSignal1d::where('symbol', $symbol)
                ->whereDate('created_at', $today)
                ->exists();

            if (!$exists) {
                OldBotSignal1d::create([
                    'symbol'     => $symbol,
                    'kf'         => round($kf, 1),
                    'text'       => $text,
                    'created_at' => now('UTC'),
                ]);
                Log::info("[OldBot 1D] {$symbol} KF={$kf} written");
            }
        }

        return ['kf' => round($kf, 1), 'text' => $text, 'cached' => false];
    }

    // ============================================
    // 1 ЧАС — с кэшем и записью
    // ============================================
    public function run1h(string $symbol): array
    {
        $hourStart = gmdate('Y-m-d H:00:00');

        $cached = OldBotSignal1h::where('symbol', $symbol)
            ->where('created_at', '>=', $hourStart)
            ->first();

        if ($cached) {
            return ['kf' => (float)$cached->kf, 'text' => $cached->text, 'cached' => true];
        }

        $candles = $this->binance->getCandles($symbol, '1h', 4);
        if (!$candles['success'] || count($candles['data']) < 3) {
            return ['kf' => 0, 'text' => 'нет данных', 'cached' => false];
        }

        $res  = $this->calc1h($candles['data']);
        $kf   = $res['kf'];
        $text = $res['text'];

        if ($kf > self::THRESHOLD_1H) {
            $exists = OldBotSignal1h::where('symbol', $symbol)
                ->where('created_at', '>=', $hourStart)
                ->exists();

            if (!$exists) {
                OldBotSignal1h::create([
                    'symbol'     => $symbol,
                    'kf'         => round($kf, 1),
                    'text'       => $text,
                    'created_at' => now('UTC'),
                ]);
                Log::info("[OldBot 1H] {$symbol} KF={$kf} written");
            }
        }

        return ['kf' => round($kf, 1), 'text' => $text, 'cached' => false];
    }

    // ============================================
    // 15 МИНУТ — с кэшем и записью
    // ============================================
    public function run15m(string $symbol): array
    {
        $minute = (int)gmdate('i');
        $slot = floor($minute / 15) * 15;
        $slotStart = gmdate('Y-m-d H:') . str_pad($slot, 2, '0', STR_PAD_LEFT) . ':00';

        $cached = OldBotSignal15m::where('symbol', $symbol)
            ->where('created_at', '>=', $slotStart)
            ->first();

        if ($cached) {
            return ['kf' => (float)$cached->kf, 'text' => $cached->text, 'cached' => true];
        }

        $candles = $this->binance->getCandles($symbol, '15m', 4);
        if (!$candles['success'] || count($candles['data']) < 3) {
            return ['kf' => 0, 'text' => 'нет данных', 'cached' => false];
        }

        $res  = $this->calc15m($candles['data']);
        $kf   = $res['kf'];
        $text = $res['text'];

        if ($kf > self::THRESHOLD_15M) {
            $exists = OldBotSignal15m::where('symbol', $symbol)
                ->where('created_at', '>=', $slotStart)
                ->exists();

            if (!$exists) {
                OldBotSignal15m::create([
                    'symbol'     => $symbol,
                    'kf'         => round($kf, 1),
                    'text'       => $text,
                    'created_at' => now('UTC'),
                ]);
                Log::info("[OldBot 15M] {$symbol} KF={$kf} written");
            }
        }

        return ['kf' => round($kf, 1), 'text' => $text, 'cached' => false];
    }

    // ============================================
    // РАСЧЁТ KF — 15M (логика из old_bot_15m.php)
    // ============================================
    public function calc15m(array $candles): array
    {
        [$cena1, $cena2, $cena3, $ob1, $ob2, $ob3, $mincena1, $mincena2, $mincena3]
            = $this->extract($candles);

        $PrIzm1 = (($cena2 - $cena1) / $cena1) * 100;
        $PrIzm2 = (($cena3 - $cena2) / $cena2) * 100;
        $PrOb1  = (($ob2 - $ob1) / $ob1) * 100;
        $PrOb2  = (($ob3 - $ob2) / $ob2) * 100;
        $lov1   = (($mincena1 - $cena1) / $cena1) * 100;
        $lov2   = (($mincena2 - $cena2) / $cena2) * 100;
        $lov3   = (($mincena3 - $cena3) / $cena3) * 100;

        $KF = 0;
        $text = 'калибровка.';

        if ($cena1 > $cena2) {
            if ($lov2 < -0.40) {
                $text = 'вероятность слива крайне мала.';
                $KF = 0;
            } else if ($lov2 < -0.30) {
                $text = 'вероятность слива мала.';
                $KF = 0;
            } else {
                if ($PrOb1 > 80) {
                    if ($PrIzm1 < -0.1) {
                        $KF = 7;
                        $text = 'слив возможен.';
                    } else if ($PrIzm1 < -0.2) {
                        $KF = 3;
                        $text = 'слив возможен.';
                    } else {
                        $text = 'слив был.';
                        $KF = 0;
                    }
                } else if ($PrOb1 > 15) {
                    $KF = 55;
                    $text = 'вероятно слив.';
                } else if ($PrOb1 < 0) {
                    $KF = 30;
                    $text = 'слабый сигнал.';
                } else {
                    $KF = 7;
                    $text = 'средний сигнал.';
                }
            }

            if (-0.02 < $PrIzm1 && $PrIzm1 < 0) {
                $KF = 0;
                $text = 'калибровка.';
            } else if (-0.04 < $PrIzm1 && $PrIzm1 < 0) {
                $KF = 10;
                $text = 'слабый сигнал.';
            }

            if ($cena2 > $cena3) {
                if ($lov3 < -0.4) {
                    $text = 'вероятность слива мала.';
                    $KF += 5;
                } else if ($lov3 < -0.26) {
                    $text = 'вероятность слива мала. Но цена может падать и дальше.';
                    $KF += 5;
                } else {
                    if ($PrOb2 > 30) {
                        $KF += 22;
                        if ($PrIzm2 < -0.13) {
                            $KF += 15;
                        }
                        $text = 'вероятно слив.';
                    } else if ($PrOb2 < -20) {
                        $KF += 40;
                        $text = 'слабый сигнал.';
                    } else if ($PrOb2 < 0) {
                        $KF += 37;
                        $text = 'слабый сигнал.';
                    } else if ($PrOb2 < 15) {
                        $KF += 50;
                        $text = 'средний сигнал.';
                    } else {
                        $KF += 50;
                        $text = 'средний сигнал.';
                    }

                    if ($PrOb2 > 25) {
                        if ($PrIzm2 < -0.5) {
                            $text = 'слив был в прошлые 15м.';
                        } else {
                            if ($lov1 < -0.18) $KF -= 15;
                            if ($lov2 < -0.18) $KF -= 20;
                            if ($lov3 < -0.18) $KF -= 30;
                            $text = 'вероятно слив в эти 15м.';
                        }
                    } else {
                        if ($lov1 < -0.5) $KF -= 40;
                        else if ($lov1 < -0.4) $KF -= 35;
                        else if ($lov1 < -0.3) $KF -= 30;
                        else if ($lov1 < -0.25) $KF -= 25;
                        else if ($lov1 < -0.2) $KF -= 20;
                        else {
                            if ($lov2 < -0.5) $KF -= 40;
                            else if ($lov2 < -0.4) $KF -= 35;
                            else if ($lov2 < -0.3) $KF -= 30;
                            else if ($lov2 < -0.25) $KF -= 25;
                            else if ($lov2 < -0.2) $KF -= 20;
                        }
                        $text = 'слив в эти 15м или через 15м.';
                    }
                }
            }
        }

        return ['kf' => round($KF, 1), 'text' => $text];
    }

    // ============================================
    // РАСЧЁТ KF — 1H (логика из old_bot_1H.php)
    // ============================================
    public function calc1h(array $candles): array
    {
        [$cena1, $cena2, $cena3, $ob1, $ob2, $ob3, $mincena1, $mincena2, $mincena3]
            = $this->extract($candles);

        $PrIzm1 = (($cena2 - $cena1) / $cena1) * 100;
        $PrIzm2 = (($cena3 - $cena2) / $cena2) * 100;
        $PrOb1  = (($ob2 - $ob1) / $ob1) * 100;
        $PrOb2  = (($ob3 - $ob2) / $ob2) * 100;
        $lov1   = (($mincena1 - $cena1) / $cena1) * 100;
        $lov2   = (($mincena2 - $cena2) / $cena2) * 100;
        $lov3   = (($mincena3 - $cena3) / $cena3) * 100;

        $KF = 0;
        $text = 'калибровка.';

        if ($cena1 > $cena2) {
            if ($lov2 < -1.5) {
                $KF = 0;
                $text = 'вероятность слива крайне мала.';
            } else if ($lov2 < -1) {
                $KF = 0;
                $text = 'вероятность слива мала.';
            } else {
                if ($PrOb1 > 130) {
                    if ($PrIzm1 < 3) {
                        $KF = 10;
                        $text = 'слабый сигнал.';
                    } else if ($PrIzm1 < 7) {
                        $KF = 5;
                        $text = 'очень слабый сигнал.';
                    } else {
                        $KF = 0;
                        $text = 'слив был.';
                    }
                } else if ($PrOb1 > 25) {
                    $KF = 45;
                    $text = 'средний сигнал.';
                } else if ($PrOb1 < 10) {
                    $KF = 20;
                    $text = 'слабый сигнал.';
                } else {
                    $KF = 35;
                    $text = 'базовый сигнал.';
                }
            }

            if (-0.1 < $PrIzm1 && $PrIzm1 < 0) {
                $KF = 0;
                $text = 'калибровка.';
            } else if (-0.2 < $PrIzm1 && $PrIzm1 < 0) {
                $KF = 5;
                $text = 'очень слабый сигнал.';
            }

            if ($cena2 > $cena3) {
                if ($lov3 < -1.5) {
                    $text = 'вероятность слива мала.';
                    $KF += 5;
                } else if ($lov3 < -1) {
                    $text = 'вероятность слива мала. Но цена может падать и дальше.';
                    $KF += 5;
                } else {
                    if ($PrOb2 > 30) {
                        $KF += 55;
                        $text = 'вероятно слив.';
                    } else if ($PrOb2 < -20) {
                        $KF += 5;
                        $text = 'слабый сигнал.';
                    } else if ($PrOb2 < 0) {
                        $KF += 15;
                        $text = 'слабый сигнал.';
                    } else if ($PrOb2 < 15) {
                        $KF += 35;
                        $text = 'средний сигнал.';
                    } else {
                        $KF += 45;
                        $text = 'средний сигнал.';
                    }

                    if ($PrOb2 > 25) {
                        if ($PrIzm2 < -2) {
                            $text = 'слив был в том часу.';
                        } else {
                            if ($lov1 < -1) $KF -= 10;
                            if ($lov2 < -1) $KF -= 10;
                            if ($lov3 < -1) $KF -= 20;
                            $text = 'вероятно слив в этом часу.';
                        }
                    } else {
                        if ($lov1 < -5) $KF -= 40;
                        else if ($lov1 < -4) $KF -= 35;
                        else if ($lov1 < -3) $KF -= 30;
                        else if ($lov1 < -2) $KF -= 25;
                        else if ($lov1 < -1) $KF -= 20;
                        else {
                            if ($lov2 < -5) $KF -= 40;
                            else if ($lov2 < -4) $KF -= 35;
                            else if ($lov2 < -3) $KF -= 30;
                            else if ($lov2 < -2) $KF -= 25;
                            else if ($lov2 < -1) $KF -= 20;
                        }
                        $text = 'слив в этом часу или следующем.';
                    }
                }
            }
        }

        return ['kf' => round($KF, 1), 'text' => $text];
    }

    // ============================================
    // РАСЧЁТ KF — 1D (логика из old_bot_1D.php)
    // ============================================
    public function calc1d(array $candles): array
    {
        [$cena1, $cena2, $cena3, $ob1, $ob2, $ob3, $mincena1, $mincena2, $mincena3]
            = $this->extract($candles);

        $PrIzm1 = (($cena2 - $cena1) / $cena1) * 100;
        $PrIzm2 = (($cena3 - $cena2) / $cena2) * 100;
        $PrOb1  = (($ob2 - $ob1) / $ob1) * 100;
        $PrOb2  = (($ob3 - $ob2) / $ob2) * 100;
        $lov1   = (($mincena1 - $cena1) / $cena1) * 100;
        $lov2   = (($mincena2 - $cena2) / $cena2) * 100;
        $lov3   = (($mincena3 - $cena3) / $cena3) * 100;

        $lov2_high = -3;
        $lov2_low = -4;
        $prOb1_high = 12;
        $kf_mid = 45;
        $kf_add_high = 65;

        $prIzm1_high = 0.001;
        $prIzm1_low = 0.1;
        $kf_high = 8;
        $kf_low = 20;
        $kf_base = 18;

        $lov3_high = -20;
        $lov3_low = -4;
        $prOb2_high = 10;
        $prOb2_mid = 18;
        $kf_add_low = 40;
        $kf_add_base = 8;
        $prOb2_trigger = 15;
        $prIzm2_crash = -6;
        $lov_crash = -1.5;

        $KF = 0;
        $text = 'калибровка.';

        if ($cena1 > $cena2) {
            if ($lov2 < $lov2_high) {
                $KF = 0;
                $text = 'вероятность слива крайне мала.';
            } else if ($lov2 < $lov2_low) {
                $KF = 0;
                $text = 'вероятность слива мала.';
            } else {
                if ($PrOb1 > $prOb1_high) {
                    if ($PrIzm1 < $prIzm1_high) {
                        $KF = $kf_high;
                        $text = 'слабый сигнал.';
                    } else if ($PrIzm1 < $prIzm1_low) {
                        $KF = $kf_high / 2;
                        $text = 'очень слабый сигнал.';
                    } else {
                        $KF = 0;
                        $text = 'слив был.';
                    }
                } else if ($PrOb1 > 25) {
                    $KF = $kf_mid;
                    $text = 'средний сигнал.';
                } else if ($PrOb1 < 10) {
                    $KF = $kf_low;
                    $text = 'слабый сигнал.';
                } else {
                    $KF = $kf_base;
                    $text = 'базовый сигнал.';
                }
            }

            if ($cena2 > $cena3) {
                if ($lov3 < $lov3_high) {
                    $KF += 5;
                } else if ($lov3 < $lov3_low) {
                    $KF += 5;
                } else {
                    if ($PrOb2 > $prOb2_high) {
                        $KF += $kf_add_high;
                    } else if ($PrOb2 < $prOb2_mid) {
                        $KF += $kf_add_low;
                    } else {
                        $KF += $kf_add_base;
                    }

                    if ($PrOb2 > $prOb2_trigger) {
                        if ($PrIzm2 < $prIzm2_crash) {
                            $KF = 0;
                            $text = 'слив был.';
                        } else {
                            if ($lov1 < $lov_crash) $KF -= 10;
                            if ($lov2 < $lov_crash) $KF -= 10;
                            if ($lov3 < $lov_crash) $KF -= 15;
                            $text = 'вероятно слив сегодня.';
                        }
                    } else {
                        if ($lov1 < -8) $KF -= 40;
                        else if ($lov1 < -7) $KF -= 35;
                        else if ($lov1 < -6) $KF -= 30;
                        else if ($lov1 < -5) $KF -= 25;
                        else if ($lov1 < -4) $KF -= 20;
                        else {
                            if ($lov2 < -8) $KF -= 40;
                            else if ($lov2 < -7) $KF -= 35;
                            else if ($lov2 < -6) $KF -= 30;
                            else if ($lov2 < -5) $KF -= 25;
                            else if ($lov2 < -4) $KF -= 20;
                        }
                        $text = 'слив сегодня или завтра.';
                    }
                }
            }
        }

        return ['kf' => round($KF, 1), 'text' => $text];
    }

    // ============================================
    // Достаём 9 значений из массива свечей
    // ============================================
    private function extract(array $candles): array
    {
        $c1 = $candles[0];
        $c2 = $candles[1];
        $c3 = $candles[2];

        return [
            (float)$c1[4], (float)$c2[4], (float)$c3[4],  // close
            (float)$c1[5], (float)$c2[5], (float)$c3[5],  // volume
            (float)$c1[3], (float)$c2[3], (float)$c3[3],  // low
        ];
    }
}