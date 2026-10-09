<?php

namespace App\Services;

use App\Models\AiTradeJournal;
use App\Models\Oth15m;
use App\Models\Oth1h;
use App\Models\Oth1d;
use App\Models\OldBotSignal15m;
use App\Models\OldBotSignal1h;
use App\Models\OldBotSignal1d;
use App\Models\TelegramSent;
use App\Models\Trade;
use Illuminate\Support\Facades\Log;

class TradingBotService
{
    // ============================================
    // НАСТРОЙКИ
    // ============================================
    private const THRESH_DAY_OTH        = 20;
    private const THRESH_DAY_OLD        = 45;
    private const THRESH_DAY_OLD_STRONG = 85;

    private const THRESH_HOUR_OTH        = 10;
    private const THRESH_HOUR_OLD        = 55;
    private const THRESH_HOUR_OLD_STRONG = 65;

    private const THRESH_BTC_15M_OTH = 30;
    private const THRESH_BTC_15M_OLD = 55;
    private const THRESH_ETH_15M_OTH = 30;
    private const THRESH_ETH_15M_OLD = 70;

    private const MARGIN     = 100;
    private const SL_PERCENT = 0.9;

    private const LEVERAGE_BY_MODE = [
        '15m' => 50,
        '1h'  => 45,
        '1d'  => 45,
    ];

    private const TRAIL_PARAMS = [
        '15m' => ['activate' => 0.2, 'callback' => 0.1],
        '1h'  => ['activate' => 0.3, 'callback' => 0.2],
        '1d'  => ['activate' => 0.8, 'callback' => 0.5],
    ];

    // Анти-перекрытие: минимум секунд между закрытием последней сделки того же режима и новой
    private const MODE_COOLDOWN_SEC = [
        '15m' => 75 * 60,
        '1h'  => 3 * 60 * 60,
        '1d'  => 48 * 60 * 60,
    ];

    private const MODEL_THRESHOLD = 0.85;

    private const RSI_PROB_MIN = 0.83;
    private const RSI_PROB_MAX = 0.9;

    private const TRADE_SYMBOL = 'BTCUSDT';

    private ?array $history15mCache = null;

    public function __construct(
        private BinanceService    $binance,
        private TelegramService   $telegram,
        private ModelService      $model,
        private FearGreedService  $fearGreed,
        private NewsService       $news,
        private IndicatorsService $indicators,
    ) {}

    // ============================================
    // ПРОВЕРКА СИГНАЛА (без открытия сделки)
    // ============================================
    public function checkSignal(?array $kfOverride = null): array
    {
        $kf = $kfOverride ?? $this->loadKfData();
        $verdict = $this->buildVerdict($kf);

        Log::info('[TradingBot] verdict: ' . strip_tags($verdict['text']));

        $result = [
            'verdict'     => $verdict,
            'kf'          => $kf,
            'candles'     => null,
            'modelResult' => null,
            'prob'        => null,
            'ml_accept'   => false,
            'check_rsi'   => false,
            'indicators'  => null,
            'rsi_accept'  => false,
            'approved'    => false,
            'source'      => 'skip',
            'reason'      => null,
        ];

        if (!$verdict['enter']) {
            $result['reason'] = 'no_bot_signal';
            return $result;
        }

        $candles = $this->fetchCandles();
        if (!$candles) {
            $result['reason'] = 'no_candles';
            return $result;
        }
        $result['candles'] = $candles;

        $modelResult = $this->askModel($candles, $kf);
        if (!$modelResult) {
            $result['reason'] = 'no_model';
            return $result;
        }
        $result['modelResult'] = $modelResult;

        $prob      = (float)$modelResult['probability_tp'];
        $ml_accept = $prob >= self::MODEL_THRESHOLD;

        $result['prob']      = $prob;
        $result['ml_accept'] = $ml_accept;

        $check_rsi = (!$ml_accept && $prob >= self::RSI_PROB_MIN && $prob < self::RSI_PROB_MAX);
        $result['check_rsi'] = $check_rsi;

        $rsi_accept = false;
        if ($check_rsi) {
            $h15 = $this->loadHistory15m();
            $idx = $this->getCurrentIdx();

            if (!empty($h15) && $idx >= 50) {
                $rsi_data   = $this->indicators->check($h15, $idx);
                $rsi_accept = ($rsi_data['decision'] ?? 'SKIP') === 'TAKE';

                $result['indicators'] = $rsi_data;

                Log::info('[TradingBot] RSI check', [
                    'rsi'      => $rsi_data['rsi'] ?? null,
                    'cum5'     => $rsi_data['cum5'] ?? null,
                    'bb_pct_b' => $rsi_data['bb_pct_b'] ?? null,
                    'decision' => $rsi_data['decision'] ?? 'SKIP',
                ]);
            } else {
                Log::warning('[TradingBot] RSI skipped: no history or idx too small', [
                    'h15_count' => count($h15),
                    'idx'       => $idx,
                ]);
            }
        }

        $result['rsi_accept'] = $rsi_accept;
        $result['approved']   = ($ml_accept || $rsi_accept);
        $result['source']     = $ml_accept ? 'ml' : ($rsi_accept ? 'rsi' : 'skip');

        if (!$result['approved']) {
            $result['reason'] = 'rejected';
        }

        return $result;
    }

    // ============================================
    // ГЛАВНЫЙ МЕТОД
    // ============================================
    public function run(): array
    {
        $check = $this->checkSignal();
        $verdict = $check['verdict'];

        $fearGreed = null;
        try {
            $fearGreed = $this->fearGreed->get();
        } catch (\Throwable $e) {
            Log::warning('[TradingBot] fear_greed failed: ' . $e->getMessage());
        }

        $cryptoNews = null;
        try {
            $cryptoNews = $this->news->getSentiment('BTC');
        } catch (\Throwable $e) {
            Log::warning('[TradingBot] news failed: ' . $e->getMessage());
        }

        if (!$check['approved'] && $check['reason'] !== 'rejected') {
            return [
                'verdict' => $verdict,
                'traded'  => false,
                'reason'  => $check['reason'],
            ];
        }

        $candleTs   = floor(time() / 900) * 900;
        $candleTime = gmdate('Y-m-d H:i', $candleTs);
        $signalHash = md5('trade_' . $candleTime);

        if (TelegramSent::alreadySent($signalHash)) {
            Log::info('[TradingBot] already traded this candle: ' . $candleTime);
            return ['verdict' => $verdict, 'traded' => false, 'reason' => 'already_traded'];
        }

        $prob    = $check['prob'] ?? null;
        $source  = $check['source'];
        $probPct = $prob !== null ? round($prob * 100, 1) : 0;

        if ($check['candles']) {
            $this->sendModelCheck(
                $probPct,
                $check['approved'],
                $check['candles']['entry_price'],
                $check['candles']['signal_time']
            );
        }

        if (!$check['approved']) {
            TelegramSent::markSent($signalHash);
            Log::info("[TradingBot] SKIP: ml_prob={$prob}, source={$source}");
            return [
                'verdict' => $verdict,
                'traded'  => false,
                'reason'  => 'rejected',
                'prob'    => $prob,
                'source'  => $source,
            ];
        }

        $mode  = $check['verdict']['mode'] ?? '15m';
        $trade = $this->openTrade($check['candles'], $prob, $mode);
        if (!$trade['success']) {
            $this->telegram->send("❌ Ошибка открытия сделки: " . ($trade['error'] ?? 'unknown'));
            return [
                'verdict' => $verdict,
                'traded'  => false,
                'reason'  => 'trade_failed',
                'error'   => $trade['error'] ?? null,
            ];
        }

        try {
            $tradeModel = Trade::where('symbol', self::TRADE_SYMBOL)
                ->where('status', 'open')
                ->orderByDesc('opened_at')
                ->first();

            AiTradeJournal::create([
                'trade_id'    => $tradeModel?->id,
                'symbol'      => self::TRADE_SYMBOL,
                'side'        => 'SHORT',
                'entry_price' => $trade['entry_price'],
                'entry_time'  => now('UTC'),
                'quantity'    => $trade['quantity'],
                'leverage'    => $trade['leverage'] ?? 50,
                'sl_price'    => (float)$trade['sl'],
                'tp_price'    => (float)$trade['tp'],

                'kf_btc_1d'   => $check['kf']['kf']['BTCUSDT']['1d']  ?? null,
                'kf_btc_1h'   => $check['kf']['kf']['BTCUSDT']['1h']  ?? null,
                'kf_btc_15m'  => $check['kf']['kf']['BTCUSDT']['15m'] ?? null,
                'kf_eth_1d'   => $check['kf']['kf']['ETHUSDT']['1d']  ?? null,
                'kf_eth_1h'   => $check['kf']['kf']['ETHUSDT']['1h']  ?? null,
                'kf_eth_15m'  => $check['kf']['kf']['ETHUSDT']['15m'] ?? null,

                'old_btc_1d'  => $check['kf']['old']['BTCUSDT']['1d']  ?? null,
                'old_btc_1h'  => $check['kf']['old']['BTCUSDT']['1h']  ?? null,
                'old_btc_15m' => $check['kf']['old']['BTCUSDT']['15m'] ?? null,
                'old_eth_1d'  => $check['kf']['old']['ETHUSDT']['1d']  ?? null,
                'old_eth_1h'  => $check['kf']['old']['ETHUSDT']['1h']  ?? null,
                'old_eth_15m' => $check['kf']['old']['ETHUSDT']['15m'] ?? null,

                'ml_probability' => $prob,
                'ml_threshold'   => self::MODEL_THRESHOLD,
                'ml_features'    => $check['modelResult']['features'] ?? null,

                'fear_greed_index' => $fearGreed['value']                ?? null,
                'fear_greed_label' => $fearGreed['value_classification'] ?? null,

                'news_positive'  => $cryptoNews['positive_count'] ?? 0,
                'news_negative'  => $cryptoNews['negative_count'] ?? 0,
                'news_neutral'   => $cryptoNews['neutral_count']  ?? 0,
                'news_headlines' => $cryptoNews['headlines']      ?? null,
                'news_sentiment' => $cryptoNews['sentiment']      ?? null,

                'verdict_text' => strip_tags($verdict['text']),
            ]);

            Log::info('[TradingBot] ai_trade_journal created', [
                'trade_id' => $tradeModel?->id,
                'prob'     => $prob,
                'source'   => $source,
            ]);
        } catch (\Throwable $e) {
            Log::error('[TradingBot] failed to write ai_trade_journal: ' . $e->getMessage());
        }

        TelegramSent::markSent($signalHash);

        return [
            'verdict' => $verdict,
            'traded'  => true,
            'prob'    => $prob,
            'source'  => $source,
            'trade'   => $trade,
        ];
    }

    // ============================================
    // ЗАГРУЗКА KF-ДАННЫХ ИЗ БД
    // ============================================
    private function loadKfData(): array
    {
        $symbols = ['BTCUSDT', 'ETHUSDT'];
        $tfs     = ['1d', '1h', '15m'];

        $result = ['kf' => [], 'old' => []];

        foreach ($symbols as $sym) {
            foreach ($tfs as $tf) {
                $result['kf'][$sym][$tf]  = $this->getLastKf($sym, $tf);
                $result['old'][$sym][$tf] = $this->getLastOldBotKf($sym, $tf);
            }
        }

        return $result;
    }

    private function getLastKf(string $symbol, string $tf): float
    {
        $model = match ($tf) {
            '1d'  => Oth1d::class,
            '1h'  => Oth1h::class,
            '15m' => Oth15m::class,
        };

        $maxAge = match ($tf) {
            '1d'  => now()->subDays(2),
            '1h'  => now()->subHours(3),
            '15m' => now()->subMinutes(45),
        };

        $row = $model::where('Nazvanie', $symbol)
            ->where('data', '>=', $maxAge)
            ->orderBy('data', 'desc')
            ->first();

        return $row ? (float)$row->kf : 0.0;
    }

    private function getLastOldBotKf(string $symbol, string $tf): float
    {
        $model = match ($tf) {
            '1d'  => OldBotSignal1d::class,
            '1h'  => OldBotSignal1h::class,
            '15m' => OldBotSignal15m::class,
        };

        $maxAge = match ($tf) {
            '1d'  => now()->subDays(2),
            '1h'  => now()->subHours(3),
            '15m' => now()->subMinutes(45),
        };

        $row = $model::where('symbol', $symbol)
            ->where('created_at', '>=', $maxAge)
            ->orderBy('created_at', 'desc')
            ->first();

        return $row ? (float)$row->kf : 0.0;
    }

    // ============================================
    // ЛОГИКА ВХОДА — КАСКАД (как в backtest_ML.php)
    // ============================================
    private function buildVerdict(array $data): array
    {
        $kf  = $data['kf'];
        $old = $data['old'];

        $btcDayStrong  = ($kf['BTCUSDT']['1d'] > self::THRESH_DAY_OTH && $old['BTCUSDT']['1d'] > self::THRESH_DAY_OLD);
        $btcHourStrong = ($kf['BTCUSDT']['1h'] > self::THRESH_HOUR_OTH && $old['BTCUSDT']['1h'] > self::THRESH_HOUR_OLD);

        $btc15Ready = ($kf['BTCUSDT']['15m'] > self::THRESH_BTC_15M_OTH && $old['BTCUSDT']['15m'] > self::THRESH_BTC_15M_OLD);
        $eth15Ready = ($kf['ETHUSDT']['15m'] > self::THRESH_ETH_15M_OTH && $old['ETHUSDT']['15m'] > self::THRESH_ETH_15M_OLD);
        $trigger15mReady = ($btc15Ready && $eth15Ready);

        $oldDayKf  = (float)($old['BTCUSDT']['1d'] ?? 0);
        $oldHourKf = (float)($old['BTCUSDT']['1h'] ?? 0);

        $enter = false;
        $text  = '';
        $mode  = 'skip';

        if ($btcDayStrong) {
            if ($oldDayKf > self::THRESH_DAY_OLD_STRONG) {
                // День очень сильный → 1d
                $text = "🚀 <b>ВХОД РАЗРЕШЕН: ТОРГУЕМ ПО ДНЮ!</b>\n(oldBot 1D = {$oldDayKf} > " . self::THRESH_DAY_OLD_STRONG . ")";
                $enter = true;
                $mode  = '1d';

            } elseif ($oldDayKf >= self::THRESH_DAY_OLD) {
                // День средний [45, 85] → смотрим час
                if ($btcHourStrong) {
                    if ($oldHourKf > self::THRESH_HOUR_OLD_STRONG) {
                        // Час очень сильный → 1h
                        $text = "🚀 <b>ВХОД РАЗРЕШЕН: ТОРГУЕМ НА 1H!</b>\n(oldBot 1D = {$oldDayKf}, oldBot 1H = {$oldHourKf} > " . self::THRESH_HOUR_OLD_STRONG . ")";
                        $enter = true;
                        $mode  = '1h';
                    } else {
                        // Час средний [55, 65] → 15m
                        if ($trigger15mReady) {
                            $text = "⚡️ <b>ВХОД РАЗРЕШЕН: ТОРГУЕМ НА 15M!</b>\n(oldBot 1D = {$oldDayKf}, час средний, 15m готовы)";
                            $enter = true;
                            $mode  = '15m';
                        } else {
                            $text = "⏸ <b>ЗАБОР</b>\n(oldBot 1D = {$oldDayKf}, час средний, 15m не готовы)";
                        }
                    }
                } else {
                    // Час слабый → 15m
                    if ($trigger15mReady) {
                        $text = "⚡️ <b>ВХОД РАЗРЕШЕН: ТОРГУЕМ НА 15M!</b>\n(oldBot 1D = {$oldDayKf}, час слабый, 15m готовы)";
                        $enter = true;
                        $mode  = '15m';
                    } else {
                        $text = "⏸ <b>ЗАБОР</b>\n(oldBot 1D = {$oldDayKf}, час слабый, 15m не готовы)";
                    }
                }
            } else {
                // День слабый (<45) → нет сигнала
                $text = "❌ НЕ ТОРГУЕМ\n(oldBot 1D = {$oldDayKf} < " . self::THRESH_DAY_OLD . ")";
            }

        } elseif ($btcHourStrong && $trigger15mReady) {
            // День слабый, но час + 15m совпали → 15m
            $text = "⚡️ <b>ВХОД РАЗРЕШЕН: СКАЛЬПИНГ 15M!</b>\n(час + 15m подтвердили)";
            $enter = true;
            $mode  = '15m';

        } else {
            $text = "❌ НЕ ТОРГУЕМ\n(день слабый, час или 15m не подтвердили)";
        }

        return ['enter' => $enter, 'text' => $text, 'mode' => $mode];
    }

    private function fetchCandles(): ?array
    {
        $res = $this->binance->getCandles('BTCUSDT', '15m', 4);
        if (!$res['success'] || count($res['data']) < 4) {
            return null;
        }

        $k  = $res['data'];
        $c1 = $k[1];
        $c2 = $k[2];
        $c3 = $k[3];

        return [
            'candles'     => [$c1, $c2, $c3],
            'entry_price' => (float)$c3[4],
            'signal_time' => gmdate('Y-m-d H:i:s', (int)($c3[0] / 1000)),
        ];
    }

    private function loadHistory15m(): array
    {
        if ($this->history15mCache !== null) {
            return $this->history15mCache;
        }

        $res = $this->binance->getCandles('BTCUSDT', '15m', 200);
        if (!$res['success'] || empty($res['data'])) {
            Log::warning('[TradingBot] history_15m not available');
            $this->history15mCache = [];
            return [];
        }

        $this->history15mCache = $res['data'];
        return $this->history15mCache;
    }

    private function getCurrentIdx(): int
    {
        $h15 = $this->loadHistory15m();
        if (empty($h15)) return -1;
        return count($h15) - 2;
    }

    private function askModel(array $candles, array $kfData): ?array
    {
        $kfForModel = [
            'kf'             => $kfData['old']['BTCUSDT']['15m'],
            'kf_btc_15m_oth' => $kfData['kf']['BTCUSDT']['15m'],
            'kf_eth_15m_oth' => $kfData['kf']['ETHUSDT']['15m'],
            'kf_eth_15m_old' => $kfData['old']['ETHUSDT']['15m'],
            'kf_btc_1h_oth'  => $kfData['kf']['BTCUSDT']['1h'],
            'kf_btc_1h_old'  => $kfData['old']['BTCUSDT']['1h'],
            'kf_btc_1d_oth'  => $kfData['kf']['BTCUSDT']['1d'],
            'kf_btc_1d_old'  => $kfData['old']['BTCUSDT']['1d'],
        ];

        return $this->model->predict(
            $candles['candles'],
            $candles['entry_price'],
            $candles['signal_time'],
            $kfForModel
        );
    }

    private function sendModelCheck(float $probPct, bool $approved, float $entryPrice, string $signalTime): void
    {
        $emoji  = $approved ? '✅' : '❌';
        $status = $approved ? 'ВХОД РАЗРЕШЁН' : 'ПРОПУСК (низкая вероятность)';

        $this->telegram->send(
            "$emoji <b>ПРОВЕРКА МОДЕЛИ</b>\n" .
            "Вероятность TP: <b>{$probPct}%</b>\n" .
            "Порог: " . self::MODEL_THRESHOLD . "\n" .
            "Решение: <b>{$status}</b>\n" .
            "Цена: $" . number_format($entryPrice, 2) . "\n" .
            "Свеча: {$signalTime}"
        );
    }

    private function openTrade(array $candles, float $prob, string $mode = '15m'): array
    {
        $symbol   = self::TRADE_SYMBOL;
        $leverage = self::LEVERAGE_BY_MODE[$mode] ?? 50;

        $posCheck = $this->binance->getPosition($symbol);
        if (!$posCheck['success']) {
            return ['success' => false, 'error' => 'position check failed: ' . $posCheck['error']];
        }
        if ($posCheck['has_position']) {
            Log::info('[TradingBot] Position already open, skip new trade');
            return ['success' => false, 'error' => 'Позиция уже открыта. Новая не нужна.'];
        }

        // ==== АНТИ-ПЕРЕКРЫТИЕ по режиму ====
        $cooldownSec = self::MODE_COOLDOWN_SEC[$mode] ?? 0;
        if ($cooldownSec > 0) {
            $lastClosed = Trade::where('symbol', $symbol)
                ->where('mode', $mode)
                ->whereNotNull('closed_at')
                ->orderByDesc('closed_at')
                ->first();

            if ($lastClosed) {
                $elapsed = now('UTC')->diffInSeconds($lastClosed->closed_at, true);
                if ($elapsed < $cooldownSec) {
                    $wait = $cooldownSec - $elapsed;
                    $mins = round($wait / 60);
                    Log::info('[TradingBot] anti-overlap blocked', [
                        'mode'       => $mode,
                        'last_close' => $lastClosed->closed_at,
                        'elapsed_s'  => $elapsed,
                        'cooldown'   => $cooldownSec,
                    ]);
                    return [
                        'success' => false,
                        'error'   => "Анти-перекрытие: {$mode} закрыта {$elapsed}с назад, ждать ещё {$mins} мин",
                    ];
                }
            }
        }

        $priceRes = $this->binance->getPrice($symbol);
        if (!$priceRes['success']) {
            return ['success' => false, 'error' => 'price: ' . $priceRes['error']];
        }

        $currentPrice = $priceRes['price'];
        $notional     = self::MARGIN * $leverage;
        $quantity     = $notional / $currentPrice;

        $info = $this->binance->getExchangeInfo($symbol);
        if (!$info['success']) {
            return ['success' => false, 'error' => 'exchangeInfo: ' . $info['error']];
        }

        $stepSize    = $info['stepSize'];
        $tickSize    = $info['tickSize'];
        $minNotional = $info['minNotional'];

        $quantity = floor($quantity / $stepSize) * $stepSize;
        $quantity = round($quantity, 8);

        if ($quantity * $currentPrice < $minNotional) {
            return ['success' => false, 'error' => 'Позиция меньше минимального номинала'];
        }

        $this->binance->setLeverage($symbol, $leverage);
        $this->binance->setMarginType($symbol, 'ISOLATED');

        $order = $this->binance->openShort($symbol, $quantity);
        if (!$order['success']) {
            return ['success' => false, 'error' => 'openShort: ' . $order['error']];
        }
        $entryPrice = (float)$order['data']['avgPrice'];

        $decimals = (int)abs(log10($tickSize));

        // SL — одинаковый для всех режимов
        $slPrice  = round($entryPrice * (1 + self::SL_PERCENT / 100) / $tickSize) * $tickSize;
        $slPrice  = number_format($slPrice, $decimals, '.', '');

        // Trailing по режиму
        $trailParams = self::TRAIL_PARAMS[$mode] ?? self::TRAIL_PARAMS['15m'];
        $trailActivatePrice = round(
            $entryPrice * (1 - $trailParams['activate'] / 100) / $tickSize
        ) * $tickSize;
        $trailActivatePrice = number_format($trailActivatePrice, $decimals, '.', '');
        $trailCallbackRate  = $trailParams['callback'];

        $this->binance->cancelAllAlgoOrders($symbol);

        $slResult = $this->binance->setStopLoss($symbol, $slPrice);
        $tpResult = $this->binance->setTrailingStop(
            $symbol,
            $quantity,
            $trailActivatePrice,
            $trailCallbackRate
        );

        if (!$slResult['success']) {
            $this->telegram->send("⚠️ Шорт открыт, но SL не выставлен: " . $slResult['error']);
        }
        if (!$tpResult['success']) {
            $this->telegram->send("⚠️ Шорт открыт, но Trailing не выставлен: " . $tpResult['error']);
        }

        Trade::create([
            'symbol'      => $symbol,
            'side'        => 'SHORT',
            'entry_price' => $entryPrice,
            'quantity'    => $quantity,
            'leverage'    => $leverage,
            'stop_loss'   => (float)$slPrice,
            'take_profit' => (float)$trailActivatePrice,
            'status'      => 'open',
            'opened_at'   => now('UTC'),
            'mode'        => $mode,
        ]);

        $probPct = round($prob * 100, 1);
        $modeLabel = match($mode) {
            '1d'  => 'ПО ДНЮ',
            '1h'  => 'НА 1 ЧАС',
            '15m' => 'НА 15 МИН',
            default => $mode,
        };

        $this->telegram->send(
            "✅ <b>ШОРТ ОТКРЫТ ({$modeLabel})</b>\n" .
            "Символ: {$symbol}\n" .
            "Модель: <b>{$probPct}%</b> вероятность TP\n" .
            "Цена входа: $" . number_format($entryPrice, 2) . "\n" .
            "Количество: {$quantity} BTC\n" .
            "Плечо: {$leverage}x\n" .
            "SL: $" . number_format((float)$slPrice, 2) . " (+" . self::SL_PERCENT . "%)\n" .
            "Trailing: активация $" . number_format((float)$trailActivatePrice, 2) .
            " (-" . $trailParams['activate'] . "%), откат " . $trailCallbackRate . "%"
        );

        return [
            'success'     => true,
            'entry_price' => $entryPrice,
            'quantity'    => $quantity,
            'sl'          => $slPrice,
            'tp'          => $trailActivatePrice,
            'mode'        => $mode,
            'leverage'    => $leverage,
        ];
    }
}