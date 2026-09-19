<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BinanceService
{
    private string $apiKey;
    private string $secretKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey    = config('services.binance.key');
        $this->secretKey = config('services.binance.secret');
        $this->baseUrl   = config('services.binance.base_url', 'https://testnet.binancefuture.com');
    }

    // ============================================
    // БАЗОВЫЙ ЗАПРОС С ПОДПИСЬЮ HMAC SHA256
    // ============================================
    private function request(string $method, string $endpoint, array $params = [], bool $signed = true): array
    {
        if ($signed) {
            $params['timestamp']  = round(microtime(true) * 1000);
            $params['recvWindow'] = 5000;

            $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $signature   = hash_hmac('SHA256', $queryString, $this->secretKey);
            $queryString .= '&signature=' . $signature;
        } else {
            $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        $url = $this->baseUrl . $endpoint;

        $request = Http::withHeaders(['X-MBX-APIKEY' => $this->apiKey])
            ->timeout(15);

        try {
            $response = $method === 'GET'
                ? $request->get($url . '?' . $queryString)
                : $request->asForm()->post($url, $this->parseQuery($queryString));
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $result   = $response->json();
        $httpCode = $response->status();

        $code = $result['code'] ?? 0;
        $allowedCodes = [0, 200, -4046]; // -4046 = "No need to change margin type"

        if ($httpCode !== 200 || ($code !== 0 && !in_array($code, $allowedCodes))) {
            return [
                'success' => false,
                'error'   => $result['msg'] ?? $result['message'] ?? 'Unknown error',
                'code'    => $code ?: $httpCode,
                'raw'     => $result,
            ];
        }

        return ['success' => true, 'data' => $result];
    }

    private function parseQuery(string $queryString): array
    {
        $out = [];
        parse_str($queryString, $out);
        return $out;
    }

    // ============================================
    // ПУБЛИЧНЫЕ МЕТОДЫ
    // ============================================

    // Текущая цена
    public function getPrice(string $symbol): array
    {
        $result = $this->request('GET', '/fapi/v1/ticker/price', ['symbol' => $symbol], false);
        if (!$result['success']) return $result;
        return ['success' => true, 'price' => (float)$result['data']['price']];
    }

    // Инфо о символе (stepSize, tickSize, minNotional)
    public function getExchangeInfo(string $symbol): array
    {
        $result = $this->request('GET', '/fapi/v1/exchangeInfo', [], false);
        if (!$result['success']) return $result;

        foreach ($result['data']['symbols'] as $s) {
            if ($s['symbol'] === $symbol) {
                $stepSize = 0.001;
                $tickSize = 0.1;
                $minNotional = 5;

                foreach ($s['filters'] as $f) {
                    if ($f['filterType'] === 'LOT_SIZE')      $stepSize    = (float)$f['stepSize'];
                    if ($f['filterType'] === 'PRICE_FILTER')  $tickSize    = (float)$f['tickSize'];
                    if ($f['filterType'] === 'MIN_NOTIONAL')  $minNotional = (float)$f['notional'];
                }

                return [
                    'success'     => true,
                    'stepSize'    => $stepSize,
                    'tickSize'    => $tickSize,
                    'minNotional' => $minNotional,
                ];
            }
        }

        return ['success' => false, 'error' => 'Symbol not found'];
    }

    // Проверка позиции
    public function getPosition(string $symbol): array
    {
        $result = $this->request('GET', '/fapi/v2/positionRisk', ['symbol' => $symbol]);
        if (!$result['success']) return $result;

        foreach ($result['data'] as $pos) {
            if ($pos['symbol'] === $symbol) {
                $amt = (float)$pos['positionAmt'];
                return [
                    'success'      => true,
                    'has_position' => ($amt != 0),
                    'amount'       => $amt,
                    'entry_price'  => (float)$pos['entryPrice'],
                    'unrealized'   => (float)$pos['unRealizedProfit'],
                ];
            }
        }

        return ['success' => true, 'has_position' => false];
    }

    // Плечо
    public function setLeverage(string $symbol, int $leverage): array
    {
        return $this->request('POST', '/fapi/v1/leverage', [
            'symbol'   => $symbol,
            'leverage' => $leverage,
        ]);
    }

    // Тип маржи
    public function setMarginType(string $symbol, string $marginType = 'ISOLATED'): array
    {
        return $this->request('POST', '/fapi/v1/marginType', [
            'symbol'     => $symbol,
            'marginType' => $marginType,
        ]);
    }

    // Открыть SHORT
    public function openShort(string $symbol, float $quantity): array
    {
        $order = $this->request('POST', '/fapi/v1/order', [
            'symbol'   => $symbol,
            'side'     => 'SELL',
            'type'     => 'MARKET',
            'quantity' => $quantity,
        ]);

        if (!$order['success']) return $order;

        usleep(200000);

        $orderId = $order['data']['orderId'] ?? null;
        if ($orderId) {
            $fresh = $this->getOrder($symbol, $orderId);
            if ($fresh['success']) return $fresh;
        }

        return $order;
    }

    // Получить ордер по ID
    public function getOrder(string $symbol, int $orderId): array
    {
        return $this->request('GET', '/fapi/v1/order', [
            'symbol'  => $symbol,
            'orderId' => $orderId,
        ]);
    }

    // Закрыть позицию
    public function closePosition(string $symbol, float $quantity): array
    {
        return $this->request('POST', '/fapi/v1/order', [
            'symbol'   => $symbol,
            'side'     => 'BUY',
            'type'     => 'MARKET',
            'quantity' => $quantity,
        ]);
    }

    // SL
    public function setStopLoss(string $symbol, string $triggerPrice): array
    {
        return $this->request('POST', '/fapi/v1/algoOrder', [
            'algoType'      => 'CONDITIONAL',
            'symbol'        => $symbol,
            'side'          => 'BUY',
            'type'          => 'STOP_MARKET',
            'triggerPrice'  => $triggerPrice,
            'closePosition' => 'true',
            'workingType'   => 'MARK_PRICE',
        ]);
    }

    // TP
    public function setTakeProfit(string $symbol, string $triggerPrice): array
    {
        return $this->request('POST', '/fapi/v1/algoOrder', [
            'algoType'      => 'CONDITIONAL',
            'symbol'        => $symbol,
            'side'          => 'BUY',
            'type'          => 'TAKE_PROFIT_MARKET',
            'triggerPrice'  => $triggerPrice,
            'closePosition' => 'true',
            'workingType'   => 'MARK_PRICE',
        ]);
    }
    // Trailing Stop
  public function setTrailingStop(string $symbol, float $quantity, string $activationPrice, float $callbackRate): array
{
    return $this->request('POST', '/fapi/v1/algoOrder', [
        'algoType'      => 'CONDITIONAL',
        'symbol'        => $symbol,
        'side'          => 'BUY',
        'type'          => 'TRAILING_STOP_MARKET',
        'quantity'      => $quantity,
        'activatePrice' => $activationPrice,
        'callbackRate'  => $callbackRate,
        'workingType'   => 'MARK_PRICE',
    ]);
}

    public function cancelAllAlgoOrders(string $symbol): array
    {
        return $this->request('DELETE', '/fapi/v1/algoOpenOrders', [
            'symbol' => $symbol,
        ]);
    }

    // Отменить ВСЕ открытые ордера по символу (обычные + условные + Algo)
    public function cancelAllOpenOrders(string $symbol): array
    {
        return $this->request('DELETE', '/fapi/v1/allOpenOrders', [
            'symbol' => $symbol,
        ]);
    }

    // Баланс
    public function getBalance(): array
    {
        $result = $this->request('GET', '/fapi/v2/balance');
        if (!$result['success']) return $result;

        foreach ($result['data'] as $b) {
            if ($b['asset'] === 'USDT') {
                return ['success' => true, 'balance' => (float)$b['balance']];
            }
        }

        return ['success' => false, 'error' => 'USDT balance not found'];
    }

    // Свечи с реального Binance (не testnet)
    public function getCandles(string $symbol = 'BTCUSDT', string $interval = '15m', int $limit = 4): array
    {
        $url = "https://api.binance.com/api/v3/klines?symbol={$symbol}&interval={$interval}&limit={$limit}";
        try {
            $response = Http::timeout(15)->get($url);
            if (!$response->successful()) {
                return ['success' => false, 'error' => 'HTTP ' . $response->status()];
            }
            return ['success' => true, 'data' => $response->json()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}