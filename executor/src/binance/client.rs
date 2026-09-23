use std::time::{SystemTime, UNIX_EPOCH};
use reqwest::Client;

use crate::binance::models::{
    PriceResponse, BalanceEntry, ExchangeInfo, Filter, SymbolRules,
    OrderResponse, AlgoOrderResponse, MarginTypeResponse, LeverageResponse, PositionRisk, Position,
};
use crate::binance::sign::sign;
use crate::error::{ExecutorError, Result};

pub struct BinanceClient {
    http: Client,
    api_key: String,
    secret: String,
    base_url: String,
}

impl BinanceClient {
    pub fn new(api_key: String, secret: String, base_url: String) -> Self {
        Self {
            http: Client::builder()
                .timeout(std::time::Duration::from_secs(10))
                .build()
                .expect("reqwest client"),
            api_key,
            secret,
            base_url,
        }
    }

    fn timestamp_ms() -> u128 {
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .expect("time")
            .as_millis()
    }

    // ============================================
    // ПОДПИСАННЫЙ GET
    // ============================================
    async fn signed_get<T: serde::de::DeserializeOwned>(
        &self,
        endpoint: &str,
        mut params: Vec<(String, String)>,
    ) -> Result<T> {
        params.push(("timestamp".into(), Self::timestamp_ms().to_string()));
        params.push(("recvWindow".into(), "5000".into()));

        let query = params
            .iter()
            .map(|(k, v)| format!("{}={}", k, v))
            .collect::<Vec<_>>()
            .join("&");

        let signature = sign(&query, &self.secret);
        let url = format!("{}{}?{}&signature={}", self.base_url, endpoint, query, signature);

        let resp = self
            .http
            .get(&url)
            .header("X-MBX-APIKEY", &self.api_key)
            .send()
            .await?;

        let status = resp.status();
        let text = resp.text().await?;

        if !status.is_success() {
            return Err(Self::parse_binance_error(&text, status.as_u16()));
        }

        serde_json::from_str(&text).map_err(|e| ExecutorError::Parse(e.to_string()))
    }

    // ============================================
    // ПОДПИСАННЫЙ POST (form-urlencoded)
    // ============================================
    async fn signed_post<T: serde::de::DeserializeOwned>(
        &self,
        endpoint: &str,
        mut params: Vec<(String, String)>,
    ) -> Result<T> {
        params.push(("timestamp".into(), Self::timestamp_ms().to_string()));
        params.push(("recvWindow".into(), "5000".into()));

        let query = params
            .iter()
            .map(|(k, v)| format!("{}={}", k, v))
            .collect::<Vec<_>>()
            .join("&");

        let signature = sign(&query, &self.secret);
        let body = format!("{}&signature={}", query, signature);
        let url = format!("{}{}", self.base_url, endpoint);

        let resp = self
            .http
            .post(&url)
            .header("X-MBX-APIKEY", &self.api_key)
            .header("Content-Type", "application/x-www-form-urlencoded")
            .body(body)
            .send()
            .await?;

        let status = resp.status();
        let text = resp.text().await?;

        if !status.is_success() {
            return Err(Self::parse_binance_error(&text, status.as_u16()));
        }

        serde_json::from_str(&text).map_err(|e| ExecutorError::Parse(e.to_string()))
    }

    // ============================================
    // ПУБЛИЧНЫЙ GET БЕЗ ПОДПИСИ
    // ============================================
    async fn public_get<T: serde::de::DeserializeOwned>(
        &self,
        endpoint: &str,
        params: &[(&str, &str)],
    ) -> Result<T> {
        let url = format!("{}{}", self.base_url, endpoint);
        let resp = self
            .http
            .get(&url)
            .query(params)
            .send()
            .await?;

        let status = resp.status();
        let text = resp.text().await?;

        if !status.is_success() {
            return Err(Self::parse_binance_error(&text, status.as_u16()));
        }

        serde_json::from_str(&text).map_err(|e| ExecutorError::Parse(e.to_string()))
    }

    fn parse_binance_error(body: &str, http_status: u16) -> ExecutorError {
        #[derive(serde::Deserialize)]
        struct ErrBody { code: Option<i64>, msg: Option<String> }
        match serde_json::from_str::<ErrBody>(body) {
            Ok(e) => ExecutorError::Binance {
                code: e.code.unwrap_or(http_status as i64),
                msg: e.msg.unwrap_or_else(|| body.to_string()),
            },
            Err(_) => ExecutorError::Binance {
                code: http_status as i64,
                msg: body.to_string(),
            },
        }
    }

    // ============================================
    // ПУБЛИЧНЫЕ МЕТОДЫ
    // ============================================

    pub async fn get_price(&self, symbol: &str) -> Result<f64> {
        let r: PriceResponse = self
            .public_get("/fapi/v1/ticker/price", &[("symbol", symbol)])
            .await?;
        r.price.parse().map_err(|e: std::num::ParseFloatError| {
            ExecutorError::Parse(e.to_string())
        })
    }

    pub async fn get_balance_usdt(&self) -> Result<f64> {
        let entries: Vec<BalanceEntry> = self
            .signed_get("/fapi/v2/balance", vec![])
            .await?;
        entries
            .into_iter()
            .find(|b| b.asset == "USDT")
            .and_then(|b| b.balance.parse().ok())
            .ok_or_else(|| ExecutorError::Parse("USDT balance not found".into()))
    }

    pub async fn get_symbol_rules(&self, symbol: &str) -> Result<SymbolRules> {
        let info: ExchangeInfo = self
            .public_get("/fapi/v1/exchangeInfo", &[])
            .await?;

        let s = info
            .symbols
            .into_iter()
            .find(|s| s.symbol == symbol)
            .ok_or_else(|| ExecutorError::Parse(format!("symbol {symbol} not found")))?;

        let mut rules = SymbolRules { step_size: 0.001, tick_size: 0.1, min_notional: 5.0 };
        for f in s.filters {
            match f {
                Filter::LotSize { step_size } => {
                    rules.step_size = step_size.parse().unwrap_or(rules.step_size);
                }
                Filter::PriceFilter { tick_size } => {
                    rules.tick_size = tick_size.parse().unwrap_or(rules.tick_size);
                }
                Filter::MinNotional { notional } => {
                    rules.min_notional = notional.parse().unwrap_or(rules.min_notional);
                }
                Filter::Other => {}
            }
        }
        Ok(rules)
    }

    // ============================================
    // ТОРГОВЫЕ МЕТОДЫ
    // ============================================

    pub async fn set_leverage(&self, symbol: &str, leverage: u32) -> Result<LeverageResponse> {
        self.signed_post(
            "/fapi/v1/leverage",
            vec![
                ("symbol".into(), symbol.into()),
                ("leverage".into(), leverage.to_string()),
            ],
        ).await
    }

    pub async fn set_margin_type(&self, symbol: &str, margin_type: &str) -> Result<()> {
        let result: std::result::Result<MarginTypeResponse, ExecutorError> = self.signed_post(
            "/fapi/v1/marginType",
            vec![
                ("symbol".into(), symbol.into()),
                ("marginType".into(), margin_type.into()),
            ],
        ).await;

        match result {
            Ok(_) => Ok(()),
            Err(ExecutorError::Binance { code, .. }) if code == -4046 => Ok(()),
            Err(e) => Err(e),
        }
    }

    pub async fn open_short(&self, symbol: &str, quantity: f64) -> Result<OrderResponse> {
        self.signed_post(
            "/fapi/v1/order",
            vec![
                ("symbol".into(), symbol.into()),
                ("side".into(), "SELL".into()),
                ("type".into(), "MARKET".into()),
                ("quantity".into(), format!("{:.8}", quantity)),
            ],
        ).await
    }

    pub async fn close_position(&self, symbol: &str, quantity: f64) -> Result<OrderResponse> {
        self.signed_post(
            "/fapi/v1/order",
            vec![
                ("symbol".into(), symbol.into()),
                ("side".into(), "BUY".into()),
                ("type".into(), "MARKET".into()),
                ("quantity".into(), format!("{:.8}", quantity)),
                ("reduceOnly".into(), "true".into()),
            ],
        ).await
    }

    pub async fn get_order(&self, symbol: &str, order_id: i64) -> Result<OrderResponse> {
        self.signed_get(
            "/fapi/v1/order",
            vec![
                ("symbol".into(), symbol.into()),
                ("orderId".into(), order_id.to_string()),
            ],
        ).await
    }

    pub async fn wait_for_fill(
        &self,
        symbol: &str,
        order_id: i64,
        timeout_ms: u64,
    ) -> Result<OrderResponse> {
        let start = std::time::Instant::now();
        let timeout = std::time::Duration::from_millis(timeout_ms);

        loop {
            let order = self.get_order(symbol, order_id).await?;

            match order.status.as_str() {
                "FILLED" => return Ok(order),
                "CANCELED" | "REJECTED" | "EXPIRED" => {
                    return Err(ExecutorError::Binance {
                        code: 0,
                        msg: format!("order {} ended with status {}", order_id, order.status),
                    });
                }
                _ => {}
            }

            if start.elapsed() >= timeout {
                return Err(ExecutorError::Binance {
                    code: 0,
                    msg: format!(
                        "order {} not filled within {}ms (status: {})",
                        order_id, timeout_ms, order.status
                    ),
                });
            }

            tokio::time::sleep(std::time::Duration::from_millis(150)).await;
        }
    }

    // --- STOP LOSS через /fapi/v1/algoOrder ---
    // Возвращает AlgoOrderResponse (algoId вместо orderId).
    // Fallback на /fapi/v1/order при -4120.
    pub async fn set_stop_loss(
        &self,
        symbol: &str,
        trigger_price: f64,
    ) -> Result<AlgoOrderResponse> {
        let result: std::result::Result<AlgoOrderResponse, ExecutorError> = self.signed_post(
            "/fapi/v1/algoOrder",
            vec![
                ("algoType".into(), "CONDITIONAL".into()),
                ("symbol".into(), symbol.into()),
                ("side".into(), "BUY".into()),
                ("type".into(), "STOP_MARKET".into()),
                ("triggerPrice".into(), format!("{:.2}", trigger_price)),
                ("closePosition".into(), "true".into()),
                ("workingType".into(), "MARK_PRICE".into()),
            ],
        ).await;

        match result {
            Ok(resp) => Ok(resp),
            Err(ExecutorError::Binance { code: -4120, .. }) => {
                tracing::warn!("algoOrder not supported, falling back to /fapi/v1/order");
                let _: OrderResponse = self.signed_post(
                    "/fapi/v1/order",
                    vec![
                        ("symbol".into(), symbol.into()),
                        ("side".into(), "BUY".into()),
                        ("type".into(), "STOP_MARKET".into()),
                        ("stopPrice".into(), format!("{:.2}", trigger_price)),
                        ("closePosition".into(), "true".into()),
                        ("workingType".into(), "MARK_PRICE".into()),
                    ],
                ).await?;
                Ok(AlgoOrderResponse {
                    algo_id: 0,
                    client_algo_id: String::new(),
                    algo_type: "CONDITIONAL".into(),
                    order_type: "STOP_MARKET".into(),
                    symbol: symbol.into(),
                    side: "BUY".into(),
                    algo_status: "NEW".into(),
                    trigger_price: format!("{:.2}", trigger_price),
                    close_position: true,
                    working_type: "MARK_PRICE".into(),
                })
            }
            Err(e) => Err(e),
        }
    }

    // --- TRAILING STOP через /fapi/v1/algoOrder ---
    pub async fn set_trailing_stop(
        &self,
        symbol: &str,
        quantity: f64,
        activation_price: f64,
        callback_rate: f64,
    ) -> Result<AlgoOrderResponse> {
        let result: std::result::Result<AlgoOrderResponse, ExecutorError> = self.signed_post(
            "/fapi/v1/algoOrder",
            vec![
                ("algoType".into(), "CONDITIONAL".into()),
                ("symbol".into(), symbol.into()),
                ("side".into(), "BUY".into()),
                ("type".into(), "TRAILING_STOP_MARKET".into()),
                ("quantity".into(), format!("{:.8}", quantity)),
                ("reduceOnly".into(), "true".into()),
                ("activatePrice".into(), format!("{:.2}", activation_price)),
                ("callbackRate".into(), format!("{:.2}", callback_rate)),
                ("workingType".into(), "MARK_PRICE".into()),
            ],
        ).await;

        match result {
            Ok(resp) => Ok(resp),
            Err(ExecutorError::Binance { code: -4120, .. }) => {
                tracing::warn!("algoOrder not supported, falling back to /fapi/v1/order");
                let _: OrderResponse = self.signed_post(
                    "/fapi/v1/order",
                    vec![
                        ("symbol".into(), symbol.into()),
                        ("side".into(), "BUY".into()),
                        ("type".into(), "TRAILING_STOP_MARKET".into()),
                        ("quantity".into(), format!("{:.8}", quantity)),
                        ("reduceOnly".into(), "true".into()),
                        ("activationPrice".into(), format!("{:.2}", activation_price)),
                        ("callbackRate".into(), format!("{:.2}", callback_rate)),
                        ("workingType".into(), "MARK_PRICE".into()),
                    ],
                ).await?;
                Ok(AlgoOrderResponse {
                    algo_id: 0,
                    client_algo_id: String::new(),
                    algo_type: "CONDITIONAL".into(),
                    order_type: "TRAILING_STOP_MARKET".into(),
                    symbol: symbol.into(),
                    side: "BUY".into(),
                    algo_status: "NEW".into(),
                    trigger_price: format!("{:.2}", activation_price),
                    close_position: false,
                    working_type: "MARK_PRICE".into(),
                })
            }
            Err(e) => Err(e),
        }
    }

    pub async fn get_position(&self, symbol: &str) -> Result<Option<Position>> {
        let positions: Vec<PositionRisk> = self.signed_get(
            "/fapi/v2/positionRisk",
            vec![("symbol".into(), symbol.into())],
        ).await?;

        for p in positions {
            if p.symbol == symbol {
                let amount: f64 = p.position_amt.parse().unwrap_or(0.0);
                if amount.abs() < f64::EPSILON {
                    return Ok(None);
                }
                return Ok(Some(Position {
                    symbol: p.symbol,
                    amount,
                    entry_price: p.entry_price.parse().unwrap_or(0.0),
                    unrealized: p.unrealized_profit.parse().unwrap_or(0.0),
                }));
            }
        }
        Ok(None)
    }

    pub async fn cancel_all_orders(&self, symbol: &str) -> Result<()> {
        let _: serde_json::Value = self.signed_post(
            "/fapi/v1/allOpenOrders",
            vec![("symbol".into(), symbol.into())],
        ).await?;
        Ok(())
    }
}