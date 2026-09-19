<?php

namespace App\Http\Controllers;

use App\Models\Oth15m;
use App\Models\Oth1h;
use App\Models\Oth1d;
use App\Models\OldBotSignal15m;
use App\Models\OldBotSignal1h;
use App\Models\OldBotSignal1d;
use App\Services\FearGreedService;
use App\Services\ModelService;
use App\Services\KfBotService;
use App\Services\KfCalculatorService;
use App\Services\BinanceService;
use App\Services\NewsService;

class DashboardController extends Controller
{
    public function __construct(
        private FearGreedService    $fearGreed,
        private ModelService        $model,
        private KfBotService        $kfBot,      // Бот №1 → oth_*
        private KfCalculatorService $oldBot,     // Бот №2 → old_bot_signals_*
        private BinanceService      $binance,
        private NewsService         $news,
    ) {}

    public function index()
    {
        // ============================================
        // 1. ПРОГОНЯЕМ ОБА БОТА (возвращают живой расчёт,
        //    пишут в БД при KF > порога)
        // ============================================
        $kfLive  = [];
        $oldLive = [];

        try {
            $kfLive  = $this->kfBot->runAll();
            $oldLive = $this->oldBot->runAll();
        } catch (\Throwable $e) {
            \Log::error('[Dashboard] bot run failed: ' . $e->getMessage());
        }

        // ============================================
        // 2. FEAR & GREED
        // ============================================
        $fearGreed = $this->fearGreed->get();
        $fgDescribe = null;
        if (!isset($fearGreed['error'])) {
            $fgDescribe = $this->fearGreed->describe($fearGreed['value']);
        }

        // ============================================
        // 3. НОВОСТИ
        // ============================================
        $cryptoNews = $this->news->getSentiment('BTC');

        // ============================================
        // 4. МОДЕЛЬ
        // ============================================
        $modelResult = null;
        try {
            $candles = $this->binance->getCandles('BTCUSDT', '15m', 4);
            if ($candles['success'] && count($candles['data']) >= 4) {
                $k  = $candles['data'];
                $c3 = $k[3];
                $modelResult = $this->model->predict(
                    [$k[1], $k[2], $k[3]],
                    (float)$c3[4],
                    gmdate('Y-m-d H:i:s', (int)($c3[0] / 1000))
                );
            }
        } catch (\Throwable $e) {
            \Log::error('[Dashboard] model failed: ' . $e->getMessage());
            $modelResult = null;
        }

        // ============================================
        // 5. ИСТОРИЯ KF-СИГНАЛОВ (Бот №1)
        // ============================================
        $hist1d  = Oth1d::orderBy('data', 'desc')->limit(30)->get();
        $hist1h  = Oth1h::orderBy('data', 'desc')->limit(30)->get();
        $hist15m = Oth15m::orderBy('data', 'desc')->limit(30)->get();

        // ============================================
        // 6. ИСТОРИЯ ПО ТРЁМ (Бот №2)
        // ============================================
        $histOld1d  = OldBotSignal1d::orderBy('created_at', 'desc')->limit(30)->get();
        $histOld1h  = OldBotSignal1h::orderBy('created_at', 'desc')->limit(30)->get();
        $histOld15m = OldBotSignal15m::orderBy('created_at', 'desc')->limit(30)->get();

        return view('dashboard', [
            'fearGreed'   => $fearGreed,
            'fgDescribe'  => $fgDescribe,
            'cryptoNews'  => $cryptoNews,
            'modelResult' => $modelResult,

            'kfLive'      => $kfLive,
            'oldLive'     => $oldLive,

            'hist1d'      => $hist1d,
            'hist1h'      => $hist1h,
            'hist15m'     => $hist15m,

            'histOld1d'   => $histOld1d,
            'histOld1h'   => $histOld1h,
            'histOld15m'  => $histOld15m,
        ]);
    }
}