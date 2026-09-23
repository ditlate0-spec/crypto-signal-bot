use axum::{
    extract::State,
    http::StatusCode,
    response::IntoResponse,
    routing::{get, post},
    Json, Router,
};
use serde::{Deserialize, Serialize};
use std::sync::Arc;
use tower_http::trace::TraceLayer;

use crate::binance::BinanceClient;
use crate::trading::{execute_trade, IdempotencyStore, TradeRequest, TradeResult};

#[derive(Clone)]
pub struct AppState {
    pub binance: Arc<BinanceClient>,
    pub store: Arc<IdempotencyStore>,
    pub dry_run: bool,
}

pub fn build_router(state: AppState) -> Router {
    Router::new()
        .route("/health", get(health))
        .route("/execute", post(execute))
        .with_state(state)
        .layer(TraceLayer::new_for_http())
}

// ============================================
// GET /health
// ============================================
async fn health() -> impl IntoResponse {
    Json(serde_json::json!({ "status": "ok" }))
}

// ============================================
// POST /execute — РЕАЛЬНАЯ ТОРГОВЛЯ
// ============================================
#[derive(Debug, Deserialize)]
pub struct ExecuteRequest {
    pub symbol: String,
    pub margin: f64,
    pub leverage: u32,
    pub sl_percent: f64,
    #[serde(default = "default_trail_activate")]
    pub trail_activate_percent: f64,
    #[serde(default = "default_trail_callback")]
    pub trail_callback_rate: f64,
    pub idempotency_key: String,
}

fn default_trail_activate() -> f64 { 0.2 }
fn default_trail_callback() -> f64 { 0.1 }

#[derive(Debug, Serialize)]
pub struct ExecuteResponse {
    pub status: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub result: Option<TradeResult>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub error: Option<String>,
}

async fn execute(
    State(state): State<AppState>,
    Json(req): Json<ExecuteRequest>,
) -> impl IntoResponse {
    tracing::info!(
        symbol = %req.symbol,
        margin = req.margin,
        leverage = req.leverage,
        key = %req.idempotency_key,
        dry_run = state.dry_run,
        "received /execute"
    );

    let trade_req = TradeRequest {
        symbol: req.symbol.clone(),
        margin: req.margin,
        leverage: req.leverage,
        sl_percent: req.sl_percent,
        trail_activate_percent: req.trail_activate_percent,
        trail_callback_rate: req.trail_callback_rate,
        idempotency_key: req.idempotency_key.clone(),
    };

    match execute_trade(state.binance.clone(), state.store.clone(), trade_req, state.dry_run).await {
        Ok(result) => {
            tracing::info!(status = %result.status, "trade executed");
            (StatusCode::OK, Json(ExecuteResponse {
                status: "ok".into(),
                result: Some(result),
                error: None,
            }))
        }
        Err(e) => {
            tracing::error!(error = %e, "trade failed");
            (StatusCode::OK, Json(ExecuteResponse {
                status: "error".into(),
                result: None,
                error: Some(e.to_string()),
            }))
        }
    }
}