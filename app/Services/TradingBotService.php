<?php

namespace App\Services;

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
    private const THRESH_DAY_OTH     = 20;
    private const THRESH_DAY_OLD     = 50;
    private const THRESH_HOUR_OTH    = 10;
    private const THRESH_HOUR_OLD    = 40;
    private const THRESH_BTC_15M_OTH = 30;
    private const THRESH_BTC_15M_OLD = 55;
    private const THRESH_ETH_15M_OLD = 70;

    private const MARGIN        = 100;
    private const LEVERAGE      = 50;
    private const SL_PERCENT    = 0.5;

    private const TRAIL_ACTIVATE_PERCENT = 0.2;
    private const TRAIL_CALLBACK_RATE    = 0.1;
    private const MODEL_THRESHOLD = 0.85;

    private const TRADE_SYMBOL = 'BTCUSDT';

    public function __construct(
        private BinanceService     $binance,
        private TelegramService    $telegram,
        private ModelService       $model,
    ) {}

    // ============================================
    // ГЛАВНЫЙ МЕТОД — вызывается каждые 15 минут
    // ============================================
    public function run(): array
    {
        // 1. Читаем KF-данные из БД
        $kf = $this->loadKfData();

        // 2. Считаем вердикт
        $verdict = $this->buildVerdict($kf);

        Log::info('[TradingBot] verdict: ' . strip_tags($verdict['text']));

        // 3. Если вердикт НЕ "ВХОД РАЗРЕШЕН" — выходим
        if (!$verdict['enter']) {
            return ['verdict' => $verdict, 'traded' => false];
        }

        // 4. Проверяем хэш свечи (одна сделка на 15 минут)
        $candleTs   = floor(time() / 900) * 900;
        $candleTime = gmdate('Y-m-d H:i', $candleTs);
        $signalHash = md5('trade_' . $candleTime);

        if (TelegramSent::alreadySent($signalHash)) {
            Log::info('[TradingBot] already traded this candle: ' . $candleTime);
            return ['verdict' => $verdict, 'traded' => false, 'reason' => 'already_traded'];
        }

        // 5. Скачиваем свечи с Binance
        $candles = $this->fetchCandles();
        if (!$candles) {
            return ['verdict' => $verdict, 'traded' => false, 'reason' => 'no_candles'];
        }

        // 6. Спрашиваем модель
        $modelResult = $this->askModel($candles, $kf);
        if (!$modelResult) {
            $this->telegram->send("⚠️ Модель недоступна. Пропускаю сигнал.");
            return ['verdict' => $verdict, 'traded' => false, 'reason' => 'no_model'];
        }

        $prob     = (float)$modelResult['probability_tp'];
        $probPct  = round($prob * 100, 1);
        $approved = $prob >= self::MODEL_THRESHOLD;

        // 7. Отправляем в Telegram результат проверки
        $this->sendModelCheck($probPct, $approved, $candles['entry_price'], $candles['signal_time']);

        if (!$approved) {
            TelegramSent::markSent($signalHash);
            Log::info("[TradingBot] model SKIP: prob={$prob}");
            return ['verdict' => $verdict, 'traded' => false, 'reason' => 'model_rejected', 'prob' => $prob];
        }

        // 8. Открываем сделку
        $trade = $this->openTrade($candles, $prob);

        if (!$trade['success']) {
            $this->telegram->send("❌ Ошибка открытия сделки: " . ($trade['error'] ?? 'unknown'));
            return ['verdict' => $verdict, 'traded' => false, 'reason' => 'trade_failed', 'error' => $trade['error'] ?? null];
        }

        TelegramSent::markSent($signalHash);

        return ['verdict' => $verdict, 'traded' => true, 'prob' => $prob, 'trade' => $trade];
    }

    // ============================================
    // ЗАГРУЗКА KF-ДАННЫХ ИЗ БД
    // ============================================
    private function loadKfData(): array
    {
        $symbols = ['BTCUSDT', 'ETHUSDT'];
        $tfs     = ['1d', '1h', '15m'];

        $result = [
            'kf'  => [],   // из oth_*
            'old' => [],   // из old_bot_signals_*
        ];

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

        $row = $model::where('Nazvanie', $symbol)
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

        $row = $model::where('symbol', $symbol)
            ->orderBy('created_at', 'desc')
            ->first();

        return $row ? (float)$row->kf : 0.0;
    }

    // ============================================
    // ВЕРДИКТ
    // ============================================
    private function buildVerdict(array $data): array
    {
        $kf  = $data['kf'];
        $old = $data['old'];

        $btcDayStrong  = ($kf['BTCUSDT']['1d'] > self::THRESH_DAY_OTH && $old['BTCUSDT']['1d'] > self::THRESH_DAY_OLD);
        $btcHourStrong = ($kf['BTCUSDT']['1h'] > self::THRESH_HOUR_OTH && $old['BTCUSDT']['1h'] > self::THRESH_HOUR_OLD);

        $btc15Ready = ($kf['BTCUSDT']['15m'] > self::THRESH_BTC_15M_OTH && $old['BTCUSDT']['15m'] > self::THRESH_BTC_15M_OLD);
        $eth15Ready = ($kf['ETHUSDT']['15m'] > self::THRESH_BTC_15M_OTH && $old['ETHUSDT']['15m'] > self::THRESH_ETH_15M_OLD);
        $trigger15Ready = ($btc15Ready && $eth15Ready);

        $enter = false;
        $text  = '';

        if ($btcDayStrong) {
            if ($btcHourStrong) {
                $text = "🚀 <b>ВХОД РАЗРЕШЕН: ТОРГУЕМ НА 1H!</b>\n(1D одобрен + 1H одобрен)";
                $enter = true;
            } elseif ($trigger15Ready) {
                $text = "⚡️ <b>ВХОД РАЗРЕШЕН: ТОРГУЕМ НА 15M!</b>\n(1D одобрен + Боты BTC/ETH дали синхронный импульс)";
                $enter = true;
            } else {
                $text = "⏸ <b>ЗАБОР (ЖДЕМ СИНХРОНИЗАЦИИ)</b>\n(День сильный, но час слабый и нет парного сигнала)";
            }
        } elseif ($btcHourStrong) {
            if ($trigger15Ready) {
                $text = "⚡️ <b>ВХОД РАЗРЕШЕН: СКАЛЬПИНГ НА 15M!</b>\n(1H одобрен + Боты BTC/ETH одновременно)";
                $enter = true;
            } else {
                $text = "⏸ ЗАБОР (ЖДЕМ СИНХРОНИЗАЦИИ)\n(Час сильный, но 15m импульса нет)";
            }
        } else {
            $text = "❌ НЕ ТОРГУЕМ\n(Старшие фильтры BTC 1D и 1H слабые)";
        }

        return ['enter' => $enter, 'text' => $text];
    }

    // ============================================
    // СКАЧИВАЕМ 3 СВЕЧИ С BINANCE (реальный, не testnet)
    // ============================================
    private function fetchCandles(): ?array
    {
        $res = $this->binance->getCandles('BTCUSDT', '15m', 4);
        if (!$res['success'] || count($res['data']) < 4) {
            return null;
        }

        $k = $res['data'];
        // [0] = текущая, [1..3] = последние 3 закрытые
        $c1 = $k[1];
        $c2 = $k[2];
        $c3 = $k[3];

        return [
            'candles'     => [$c1, $c2, $c3],
            'entry_price' => (float)$c3[4],
            'signal_time' => gmdate('Y-m-d H:i:s', (int)($c3[0] / 1000)),
        ];
    }

    // ============================================
    // СПРАШИВАЕМ МОДЕЛЬ
    // ============================================
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

    // ============================================
    // ОТКРЫТИЕ СДЕЛКИ
    // ============================================
    private function openTrade(array $candles, float $prob): array
    {
        $symbol = self::TRADE_SYMBOL;

        // Текущая цена с testnet
        $priceRes = $this->binance->getPrice($symbol);
        if (!$priceRes['success']) {
            return ['success' => false, 'error' => 'price: ' . $priceRes['error']];
        }

        $currentPrice = $priceRes['price'];
        $notional     = self::MARGIN * self::LEVERAGE;
        $quantity     = $notional / $currentPrice;

        // Инфо о символе
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

        // Устанавливаем плечо и маржу
        $this->binance->setLeverage($symbol, self::LEVERAGE);
        $this->binance->setMarginType($symbol, 'ISOLATED');

        // Открываем SHORT
        $order = $this->binance->openShort($symbol, $quantity);
        if (!$order['success']) {
            return ['success' => false, 'error' => 'openShort: ' . $order['error']];
        }
        $entryPrice = (float)$order['data']['avgPrice'];

        // Считаем SL и цену активации трейлинга
        $decimals = (int)abs(log10($tickSize));
        $slPrice = round($entryPrice * (1 + self::SL_PERCENT / 100) / $tickSize) * $tickSize;
        $slPrice = number_format($slPrice, $decimals, '.', '');

        // Активация трейлинга ниже входа (для шорта)
        $trailActivatePrice = round($entryPrice * (1 - self::TRAIL_ACTIVATE_PERCENT / 100) / $tickSize) * $tickSize;
        $trailActivatePrice = number_format($trailActivatePrice, $decimals, '.', '');

        $slResult = $this->binance->setStopLoss($symbol, $slPrice);

        // Трейлинг-стоп вместо фиксированного TP
        $tpResult = $this->binance->setTrailingStop(
            $symbol,
            $trailActivatePrice,
            self::TRAIL_CALLBACK_RATE
        );
        if (!$slResult['success']) {
            $this->telegram->send("⚠️ Шорт открыт, но SL не выставлен: " . $slResult['error']);
        }
        if (!$tpResult['success']) {
     $this->telegram->send("⚠️ Шорт открыт, но Trailing не выставлен: " . $tpResult['error']);  }

        // Записываем в БД
        Trade::create([
            'symbol'      => $symbol,
            'side'        => 'SHORT',
            'entry_price' => $entryPrice,
            'quantity'    => $quantity,
            'leverage'    => self::LEVERAGE,
              'stop_loss'   => (float)$slPrice,
            'take_profit' => (float)$trailActivatePrice,
            'status'      => 'open',
            'opened_at'   => now('UTC'),
        ]);

        // Сообщение в Telegram
        $probPct = round($prob * 100, 1);
        $this->telegram->send(
            "✅ <b>ШОРТ ОТКРЫТ</b>\n" .
            "Символ: {$symbol}\n" .
            "Модель: <b>{$probPct}%</b> вероятность TP\n" .
            "Цена входа: $" . number_format($entryPrice, 2) . "\n" .
            "Количество: {$quantity} BTC\n" .
            "Плечо: " . self::LEVERAGE . "x\n" .
             "SL: $" . number_format((float)$slPrice, 2) . " (+" . self::SL_PERCENT . "%)\n" .
            "Trailing: активация $" . number_format((float)$trailActivatePrice, 2) .
            " (-" . self::TRAIL_ACTIVATE_PERCENT . "%), откат " . self::TRAIL_CALLBACK_RATE . "%"
        );

        return [
            'success'     => true,
            'entry_price' => $entryPrice,
            'quantity'    => $quantity,
            'sl'          => $slPrice,
            'tp'          => $trailActivatePrice,
        ];
    }
}