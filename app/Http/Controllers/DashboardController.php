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
use App\Services\TradingBotService;

class DashboardController extends Controller
{
    public function __construct(
        private FearGreedService    $fearGreed,
        private ModelService        $model,
        private KfBotService        $kfBot,      // Бот №1 → oth_*
        private KfCalculatorService $oldBot,     // Бот №2 → old_bot_signals_*
        private BinanceService      $binance,
        private NewsService         $news,
        private TradingBotService   $tradingBot, // Торговый бот: вердикт → модель → шорт
    ) {}

    public function index()
    {
        // ============================================
        // 1. ПРОГОНЯЕМ KF-БОТОВ
        //    (возвращают живой расчёт, пишут в БД при KF > порога)
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
        // 1.5. ТОРГОВЫЙ БОТ
        //      читает свежие KF из БД → вердикт → модель → шорт
        //      Защита от повторов: TelegramSent::alreadySent()
        //      (одна сделка на 15-минутную свечу)
        // ============================================
        try {
            $this->tradingBot->run();
        } catch (\Throwable $e) {
            \Log::error('[Dashboard] TradingBot failed: ' . $e->getMessage());
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
        // 4. МОДЕЛЬ (для отображения на дашборде)
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

        // ============================================
        // 7. AI-АНАЛИЗ (последний файл из storage/app/analytics/)
        // ============================================
        $latestAnalysis = $this->getLatestAnalysis();

        return view('dashboard', [
            'fearGreed'      => $fearGreed,
            'fgDescribe'     => $fgDescribe,
            'cryptoNews'     => $cryptoNews,
            'modelResult'    => $modelResult,

            'kfLive'         => $kfLive,
            'oldLive'        => $oldLive,

            'hist1d'         => $hist1d,
            'hist1h'         => $hist1h,
            'hist15m'        => $hist15m,

            'histOld1d'      => $histOld1d,
            'histOld1h'      => $histOld1h,
            'histOld15m'     => $histOld15m,

            'latestAnalysis' => $latestAnalysis,
        ]);
    }

    /**
     * Читает последний AI-анализ из storage/app/analytics/analysis_*.txt
     */
    private function getLatestAnalysis(): ?array
    {
        $dir = storage_path('app/private/analytics');

        if (!is_dir($dir)) {
            return null;
        }

        $files = glob($dir . '/analysis_*.txt');
        if (empty($files)) {
            return null;
        }

        // Сортируем по дате изменения — новые первыми
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $latestFile = $files[0];

        $basename = basename($latestFile);

        // Парсим дату из имени: analysis_2026-09-27_10-27-32.txt
        preg_match('/analysis_(\d{4}-\d{2}-\d{2})_(\d{2}-\d{2}-\d{2})/', $basename, $m);

        $date = null;
        if (!empty($m[1]) && !empty($m[2])) {
            try {
                $date = \Carbon\Carbon::parse($m[1] . ' ' . str_replace('-', ':', $m[2]));
            } catch (\Throwable $e) {
                $date = null;
            }
        }

        return [
            'content' => file_get_contents($latestFile),
            'date'    => $date,
            'file'    => $basename,
        ];
    }
}