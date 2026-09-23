use std::sync::Arc;
use anyhow::Result;
use crate::api::{build_router, routes::AppState};
use crate::binance::BinanceClient;
use crate::config::Config;
use crate::trading::IdempotencyStore;

pub async fn run(cfg: Config, binance: BinanceClient) -> Result<()> {
    let addr = format!("0.0.0.0:{}", cfg.port);

    let state = AppState {
        binance: Arc::new(binance),
        store: IdempotencyStore::new(),
        dry_run: cfg.dry_run,
    };

    let app = build_router(state);

    let listener = tokio::net::TcpListener::bind(&addr).await?;
    tracing::info!("executor listening on {} (dry_run={})", addr, cfg.dry_run);

    axum::serve(listener, app).await?;
    Ok(())
}