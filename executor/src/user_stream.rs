use std::sync::Arc;
use std::time::Duration;
use futures_util::StreamExt;
use tokio_tungstenite::{connect_async, tungstenite::Message};
use serde_json::Value;

use crate::binance::BinanceClient;

const PHP_NOTIFY_URL: &str = "http://nginx/notify/position-closed";

pub struct UserStreamConfig {
    pub symbol: String,
    pub notify_url: String,
}

impl Default for UserStreamConfig {
    fn default() -> Self {
        Self {
            symbol: "BTCUSDT".into(),
            notify_url: PHP_NOTIFY_URL.into(),
        }
    }
}

pub async fn run_user_stream(
    client: Arc<BinanceClient>,
    config: UserStreamConfig,
) -> anyhow::Result<()> {
    tracing::info!("starting user data stream for {}", config.symbol);
    loop {
        match start_stream(&client, &config).await {
            Ok(_) => tracing::warn!("user stream ended, reconnecting in 5s..."),
            Err(e) => tracing::error!(error = %e, "user stream error, reconnecting in 5s..."),
        }
        tokio::time::sleep(Duration::from_secs(5)).await;
    }
}

async fn start_stream(client: &Arc<BinanceClient>, config: &UserStreamConfig) -> anyhow::Result<()> {
    let listen_key = client.create_listen_key().await?;
    tracing::info!("listenKey created: {}...", &listen_key[..8]);

    let ws_url = format!(
    "wss://demo-fstream.binance.com/private/ws/{}",
    listen_key
);

    let (ws_stream, _) = connect_async(&ws_url).await?;
    tracing::info!("connected to user data stream");

    let (_, mut read) = ws_stream.split();

    let client_ka = Arc::clone(client);
    let key_ka = listen_key.clone();
    let keepalive_handle = tokio::spawn(async move {
        loop {
            tokio::time::sleep(Duration::from_secs(30 * 60)).await;
            if let Err(e) = client_ka.keepalive_listen_key(&key_ka).await {
                tracing::error!(error = %e, "keepalive failed");
                break;
            }
            tracing::debug!("listenKey keepalived");
        }
    });

    while let Some(msg) = read.next().await {
        let msg = match msg { Ok(m) => m, Err(e) => { tracing::error!(error = %e, "ws read error"); break; } };
        let text = match msg { Message::Text(t) => t.to_string(), _ => continue };

        if let Ok(event) = serde_json::from_str::<Value>(&text) {
            match event["e"].as_str().unwrap_or("") {
                "ORDER_TRADE_UPDATE" => handle_order_update(&event, config).await,
                "listenKeyExpired" => { tracing::warn!("listenKey expired"); break; }
                _ => {}
            }
        }
    }

    keepalive_handle.abort();
    let _ = client.close_listen_key(&listen_key).await;
    Ok(())
}

async fn handle_order_update(event: &Value, config: &UserStreamConfig) {
    let order = &event["o"];
    let symbol = order["s"].as_str().unwrap_or("");
    let order_type = order["ot"].as_str().unwrap_or("");
    let order_status = order["X"].as_str().unwrap_or("");
    let side = order["S"].as_str().unwrap_or("");
    let avg_price = order["ap"].as_str().unwrap_or("0");
    let realized_pnl = order["rp"].as_str().unwrap_or("0");

    if symbol != config.symbol { return; }

    let is_closing = order_type == "STOP_MARKET"
        || order_type == "TRAILING_STOP_MARKET"
        || order_type == "TAKE_PROFIT_MARKET";

    if is_closing && order_status == "FILLED" && side == "BUY" {
        tracing::info!(symbol, order_type, avg_price, realized_pnl, "position closed");
        notify_php(config, symbol, avg_price, realized_pnl, order_type).await;
    }
}

async fn notify_php(config: &UserStreamConfig, symbol: &str, exit_price: &str, pnl: &str, reason: &str) {
    let payload = serde_json::json!({
        "symbol": symbol, "exit_price": exit_price, "pnl": pnl, "reason": reason,
    });
    match reqwest::Client::new().post(&config.notify_url).json(&payload).timeout(Duration::from_secs(5)).send().await {
        Ok(r) if r.status().is_success() => tracing::info!("PHP notified"),
        Ok(r) => tracing::error!(status = %r.status(), "PHP notify failed"),
        Err(e) => tracing::error!(error = %e, "failed to notify PHP"),
    }
}