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
use App\Services\IndicatorsService;

class DashboardController extends Controller
{
    public function __construct(
        private FearGreedService    $fearGreed,
        private ModelService        $model,
        private KfBotService        $kfBot,
        private KfCalculatorService $oldBot,
        private BinanceService      $binance,
        private NewsService         $news,
        private TradingBotService   $tradingBot,
        private IndicatorsService   $indicators,
    ) {}

    public function index()
    {
        $t = microtime(true);
        $log = function (string $label) use (&$t) {
            \Log::info("[Dashboard] $label: " . round(microtime(true) - $t, 2) . "s");
            $t = microtime(true);
        };

        // ============================================
        // 1. KF-БОТЫ
        // ============================================
        $kfLive  = [];
        $oldLive = [];
        try {
            $kfLive  = $this->kfBot->runAll();
            $oldLive = $this->oldBot->runAll();
        } catch (\Throwable $e) {
            \Log::error('[Dashboard] KF bots failed: ' . $e->getMessage());
        }
        $log('KF bots');

        // ============================================
        // 1.5. ПРОВЕРКА СИГНАЛА (без открытия сделки)
        //      ML и RSI внутри — только если боты дали сигнал
        // ============================================
$signal = null;
try {
    // Преобразуем live-данные к виду, который ожидает buildVerdict (числа, а не массивы)
    $kfForCheck = ['kf' => [], 'old' => []];
    foreach (['BTCUSDT', 'ETHUSDT'] as $sym) {
        foreach (['1d', '1h', '15m'] as $tf) {
            $kfForCheck['kf'][$sym][$tf]  = (float)($kfLive[$sym][$tf]['kf']  ?? 0);
            $kfForCheck['old'][$sym][$tf] = (float)($oldLive[$sym][$tf]['kf'] ?? 0);
        }
    }

    $signal = $this->tradingBot->checkSignal($kfForCheck);
} catch (\Throwable $e) {
    \Log::error('[Dashboard] checkSignal failed: ' . $e->getMessage());
}
        $log('checkSignal');

        $verdict     = $signal['verdict']     ?? null;
        $modelResult = $signal['modelResult'] ?? null;
        $indicators  = $signal['indicators']  ?? null;
        $checkRsi    = $signal['check_rsi']   ?? false;
        $approved    = $signal['approved']    ?? false;
        $source      = $signal['source']      ?? 'skip';
        $mlProb      = $signal['prob']        ?? null;
        $mlAccept    = $signal['ml_accept']   ?? false;
        $skipReason  = $signal['reason']      ?? null;

        // Для blade: если RSI не считался — объясняем почему
        if ($indicators === null || (!$checkRsi && !isset($indicators['error']))) {
            if (!$verdict || !$verdict['enter']) {
                $indicators = [
                    'skipped' => true,
                    'reason'  => 'Нет сигнала ботов',
                    'ml_prob' => null,
                    'ml_dec'  => null,
                ];
            } else {
                $indicators = [
                    'skipped' => true,
                    'reason'  => $mlAccept ? 'ML TAKE' : 'ML вне серой зоны',
                    'ml_prob' => $mlProb,
                    'ml_dec'  => $mlAccept ? 'TAKE' : 'SKIP',
                ];
            }
        }

        // ============================================
        // 2. FEAR & GREED
        // ============================================
        $fearGreed  = $this->fearGreed->get();
        $fgDescribe = null;
        if (!isset($fearGreed['error'])) {
            $fgDescribe = $this->fearGreed->describe($fearGreed['value']);
        }
        $log('FearGreed');

        // ============================================
        // 3. НОВОСТИ
        // ============================================
        $cryptoNews = $this->news->getSentiment('BTC');
        $log('News');

        // ============================================
        // 4. ИСТОРИЯ KF-СИГНАЛОВ (Бот №1)
        // ============================================
        $hist1d  = Oth1d::orderBy('data', 'desc')->limit(30)->get();
        $hist1h  = Oth1h::orderBy('data', 'desc')->limit(30)->get();
        $hist15m = Oth15m::orderBy('data', 'desc')->limit(30)->get();

        // ============================================
        // 5. ИСТОРИЯ ПО ТРЁМ (Бот №2)
        // ============================================
        $histOld1d  = OldBotSignal1d::orderBy('created_at', 'desc')->limit(30)->get();
        $histOld1h  = OldBotSignal1h::orderBy('created_at', 'desc')->limit(30)->get();
        $histOld15m = OldBotSignal15m::orderBy('created_at', 'desc')->limit(30)->get();
        $log('History SQL');

        // ============================================
        // 6. AI-АНАЛИЗ
        // ============================================
        $latestAnalysis = $this->getLatestAnalysis();
        $log('Analysis file');
        $log('TOTAL');

        return view('dashboard', [
            'fearGreed'      => $fearGreed,
            'fgDescribe'     => $fgDescribe,
            'cryptoNews'     => $cryptoNews,

            // Сигнал
            'verdict'        => $verdict,
            'modelResult'    => $modelResult,
            'indicators'     => $indicators,
            'checkRsi'       => $checkRsi,
            'approved'       => $approved,
            'source'         => $source,
            'mlProb'         => $mlProb,
            'mlAccept'       => $mlAccept,
            'skipReason'     => $skipReason,

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

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $latestFile = $files[0];

        $basename = basename($latestFile);

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