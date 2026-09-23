use serde::Deserialize;

#[derive(Debug, Deserialize)]
pub struct PriceResponse {
    pub symbol: String,
    pub price: String,
}

#[derive(Debug, Deserialize)]
pub struct BalanceEntry {
    pub asset: String,
    pub balance: String,
    #[serde(rename = "availableBalance")]
    pub available_balance: String,
}

#[derive(Debug, Deserialize)]
pub struct ExchangeInfo {
    pub symbols: Vec<SymbolInfo>,
}

#[derive(Debug, Deserialize)]
pub struct SymbolInfo {
    pub symbol: String,
    pub filters: Vec<Filter>,
}

#[derive(Debug, Deserialize)]
#[serde(tag = "filterType")]
pub enum Filter {
    #[serde(rename = "LOT_SIZE")]
    LotSize { #[serde(rename = "stepSize")] step_size: String },
    #[serde(rename = "PRICE_FILTER")]
    PriceFilter { #[serde(rename = "tickSize")] tick_size: String },
    #[serde(rename = "MIN_NOTIONAL")]
    MinNotional { notional: String },
    #[serde(other)]
    Other,
}

#[derive(Debug, Clone)]
pub struct SymbolRules {
    pub step_size: f64,
    pub tick_size: f64,
    pub min_notional: f64,
}

// ---- MARKET/LIMIT ордера (старый /fapi/v1/order) ----

#[derive(Debug, Deserialize)]
pub struct OrderResponse {
    #[serde(rename = "orderId")]
    pub order_id: i64,
    pub symbol: String,
    pub status: String,           // NEW, PARTIALLY_FILLED, FILLED
    #[serde(rename = "avgPrice", default)]
    pub avg_price: String,        // может ОТСУТСТВОВАТЬ для MARKET до fill
    #[serde(rename = "executedQty", default)]
    pub executed_qty: String,
    #[serde(rename = "origQty", default)]
    pub orig_qty: String,
    pub side: String,
    #[serde(rename = "type")]
    pub order_type: String,
}

// ---- Условные ордера (новый /fapi/v1/algoOrder) ----
// Структура ответа отличается от OrderResponse:
// - algoId вместо orderId
// - algoStatus вместо status
// - orderType вместо type
// - avgPrice/executedQty отсутствуют (условный ордер ещё не исполнен)

#[derive(Debug, Deserialize)]
pub struct AlgoOrderResponse {
    #[serde(rename = "algoId", default)]
    pub algo_id: i64,
    #[serde(rename = "clientAlgoId", default)]
    pub client_algo_id: String,
    #[serde(rename = "algoType", default)]
    pub algo_type: String,
    #[serde(rename = "orderType", default)]
    pub order_type: String,
    #[serde(default)]
    pub symbol: String,
    #[serde(default)]
    pub side: String,
    #[serde(rename = "algoStatus", default)]
    pub algo_status: String,
    #[serde(rename = "triggerPrice", default)]
    pub trigger_price: String,
    #[serde(rename = "closePosition", default)]
    pub close_position: bool,
    #[serde(rename = "workingType", default)]
    pub working_type: String,
}

#[derive(Debug, Deserialize)]
pub struct PositionRisk {
    pub symbol: String,
    #[serde(rename = "positionAmt")]
    pub position_amt: String,
    #[serde(rename = "entryPrice")]
    pub entry_price: String,
    #[serde(rename = "unRealizedProfit")]
    pub unrealized_profit: String,
}

#[derive(Debug, Clone)]
pub struct Position {
    pub symbol: String,
    pub amount: f64,          // отрицательное для шорта
    pub entry_price: f64,
    pub unrealized: f64,
}

impl Position {
    pub fn is_open(&self) -> bool { self.amount.abs() > f64::EPSILON }
}

// ---- Ответы на POST-операции (leverage, marginType) ----

#[derive(Debug, Deserialize)]
pub struct LeverageResponse {
    pub symbol: String,
    pub leverage: i64,
}

#[derive(Debug, Deserialize)]
pub struct MarginTypeResponse {
    pub code: i64,
    pub msg: String,
}