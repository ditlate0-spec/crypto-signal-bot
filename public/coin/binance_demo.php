<?php
// ============================================
// coin/binance_demo.php
// Обёртка над Binance Futures API
// ============================================

class BinanceDemo {

    private $apiKey;
    private $secretKey;
    private $baseUrl = 'https://testnet.binancefuture.com';

    public function __construct($apiKey, $secretKey) {
        $this->apiKey    = $apiKey;
        $this->secretKey = $secretKey;
    }

    // ============================================
    // БАЗОВЫЙ ЗАПРОС С ПОДПИСЬЮ HMAC SHA256
    // ============================================
    private function request($method, $endpoint, $params = [], $signed = true) {
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

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . ($method === 'GET' ? '?' . $queryString : ''));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-MBX-APIKEY: ' . $this->apiKey
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $queryString);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        // Разрешаем "успешные" коды Binance
        $code = $result['code'] ?? 0;
        $allowedCodes = [0, 200, -4046];   // -4046 = "No need to change margin type"

        if ($httpCode !== 200 || ($code !== 0 && !in_array($code, $allowedCodes))) {
            return [
                'success' => false,
                'error'   => $result['msg'] ?? $result['message'] ?? 'Unknown error',
                'code'    => $code ?: $httpCode,
                'raw'     => $response
            ];
        }

        return ['success' => true, 'data' => $result];
    }

    // ============================================
    // УСТАНОВИТЬ ПЛЕЧО
    // ============================================
    public function setLeverage($symbol, $leverage) {
        return $this->request('POST', '/fapi/v1/leverage', [
            'symbol'   => $symbol,
            'leverage' => $leverage
        ]);
    }

    // ============================================
    // УСТАНОВИТЬ ИЗОЛИРОВАННУЮ МАРЖУ
    // ============================================
    public function setMarginType($symbol, $marginType = 'ISOLATED') {
        return $this->request('POST', '/fapi/v1/marginType', [
            'symbol'     => $symbol,
            'marginType' => $marginType
        ]);
    }

    // ============================================
    // ИНФО О СИМВОЛЕ (stepSize, tickSize, minNotional)
    // ============================================
    public function getExchangeInfo($symbol) {
        $result = $this->request('GET', '/fapi/v1/exchangeInfo', [], false);
        if (!$result['success']) return $result;

        foreach ($result['data']['symbols'] as $s) {
            if ($s['symbol'] === $symbol) {
                $stepSize    = 0.001;
                $tickSize    = 0.1;
                $minNotional = 5;

                foreach ($s['filters'] as $f) {
                    if ($f['filterType'] === 'LOT_SIZE') {
                        $stepSize = (float)$f['stepSize'];
                    }
                    if ($f['filterType'] === 'PRICE_FILTER') {
                        $tickSize = (float)$f['tickSize'];
                    }
                    if ($f['filterType'] === 'MIN_NOTIONAL') {
                        $minNotional = (float)$f['notional'];
                    }
                }

                return [
                    'success'     => true,
                    'stepSize'    => $stepSize,
                    'tickSize'    => $tickSize,
                    'minNotional' => $minNotional
                ];
            }
        }

        return ['success' => false, 'error' => 'Symbol not found'];
    }

    // ============================================
    // ПРОВЕРИТЬ ПОЗИЦИЮ
    // ============================================
    public function getPosition($symbol) {
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
                    'unrealized'   => (float)$pos['unRealizedProfit']
                ];
            }
        }

        return ['success' => true, 'has_position' => false];
    }

    // ============================================
    // ПОЛУЧИТЬ ОРДЕР ПО ID
    // ============================================
    public function getOrder($symbol, $orderId) {
        return $this->request('GET', '/fapi/v1/order', [
            'symbol'  => $symbol,
            'orderId' => $orderId
        ]);
    }

    // ============================================
    // ОТКРЫТЬ ШОРТ (MARKET) + ДОЖДАТЬСЯ ИСПОЛНЕНИЯ
    // ============================================
    public function openShort($symbol, $quantity) {
        $order = $this->request('POST', '/fapi/v1/order', [
            'symbol'   => $symbol,
            'side'     => 'SELL',
            'type'     => 'MARKET',
            'quantity' => $quantity
        ]);

        if (!$order['success']) return $order;

        // Ждём 1 сек, чтобы MARKET исполнился
        usleep(200000);

        // Запрашиваем свежий статус ордера — там уже будет avgPrice и FILLED
        $orderId = $order['data']['orderId'] ?? null;
        if ($orderId) {
            $fresh = $this->getOrder($symbol, $orderId);
            if ($fresh['success']) return $fresh;
        }

        return $order;
    }

    // ============================================
    // ЗАКРЫТЬ ПОЗИЦИЮ (MARKET BUY)
    // ============================================
    public function closePosition($symbol, $quantity) {
        return $this->request('POST', '/fapi/v1/order', [
            'symbol'   => $symbol,
            'side'     => 'BUY',
            'type'     => 'MARKET',
            'quantity' => $quantity
        ]);
    }

    // ============================================
    // ВЫСТАВИТЬ STOP LOSS (ALGO ORDER)
    // ============================================
    public function setStopLoss($symbol, $triggerPrice) {
        return $this->request('POST', '/fapi/v1/algoOrder', [
            'algoType'      => 'CONDITIONAL',
            'symbol'        => $symbol,
            'side'          => 'BUY',
            'type'          => 'STOP_MARKET',
            'triggerPrice'  => $triggerPrice,
            'closePosition' => 'true',
            'workingType'   => 'MARK_PRICE'
        ]);
    }
    // ============================================
    // ВЫСТАВИТЬ TRAILING STOP (ALGO ORDER)
    // ============================================
    public function setTrailingStop($symbol, $activationPrice, $callbackRate) {
        return $this->request('POST', '/fapi/v1/algoOrder', [
            'algoType'      => 'CONDITIONAL',
            'symbol'        => $symbol,
            'side'          => 'BUY',
            'type'          => 'TRAILING_STOP_MARKET',
            'activatePrice' => $activationPrice,
            'callbackRate'  => $callbackRate,
            'closePosition' => 'true',
            'workingType'   => 'MARK_PRICE'
        ]);
    }
    // ============================================
    // ВЫСТАВИТЬ TAKE PROFIT (ALGO ORDER)
    // ============================================
    public function setTakeProfit($symbol, $triggerPrice) {
        return $this->request('POST', '/fapi/v1/algoOrder', [
            'algoType'      => 'CONDITIONAL',
            'symbol'        => $symbol,
            'side'          => 'BUY',
            'type'          => 'TAKE_PROFIT_MARKET',
            'triggerPrice'  => $triggerPrice,
            'closePosition' => 'true',
            'workingType'   => 'MARK_PRICE'
        ]);
    }

    // ============================================
    // ТЕКУЩАЯ ЦЕНА
    // ============================================
    public function getPrice($symbol) {
        $result = $this->request('GET', '/fapi/v1/ticker/price', ['symbol' => $symbol], false);
        if (!$result['success']) return $result;
        return ['success' => true, 'price' => (float)$result['data']['price']];
    }

    // ============================================
    // БАЛАНС
    // ============================================
    public function getBalance() {
        $result = $this->request('GET', '/fapi/v2/balance');
        if (!$result['success']) return $result;

        foreach ($result['data'] as $b) {
            if ($b['asset'] === 'USDT') {
                return ['success' => true, 'balance' => (float)$b['balance']];
            }
        }

        return ['success' => false, 'error' => 'USDT balance not found'];
    }
}