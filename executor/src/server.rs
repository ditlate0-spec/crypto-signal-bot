use std::sync::Arc;
use anyhow::Result;
use crate::api::{build_router, routes::AppState};
use crate::binance::BinanceClient;
use crate::config::Config;
use crate::trading::IdempotencyStore;
use crate::user_stream::{run_user_stream, UserStreamConfig};

pub async fn run(cfg: Config, binance: BinanceClient) -> Result<()> {
 let addr = format!("0.0.0.0:{}", cfg.port);
    let binance = Arc::new(binance);

    let binance_for_stream = Arc::clone(&binance);
    tokio::spawn(async move {
        if let Err(e) = run_user_stream(binance_for_stream, UserStreamConfig::default()).await {
            tracing::error!(error = %e, "user stream terminated");
        }
    });

    let state = AppState {
        binance,
        store: IdempotencyStore::new(),
        dry_run: cfg.dry_run,
    };

    let app = build_router(state);

    let listener = tokio::net::TcpListener::bind(&addr).await?;
    tracing::info!("executor listening on {} (dry_run={})", addr, cfg.dry_run);

    axum::serve(listener, app).await?;
    Ok(())
}