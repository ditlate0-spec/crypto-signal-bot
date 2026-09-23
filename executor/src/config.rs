use anyhow::{Context, Result};

#[derive(Debug, Clone)]
pub struct Config {
    pub api_key: String,
    pub secret_key: String,
    pub base_url: String,
    pub port: u16,
    pub dry_run: bool,
}

impl Config {
    pub fn from_env() -> Result<Self> {
        Ok(Self {
            api_key: std::env::var("BINANCE_KEY")
                .context("BINANCE_KEY not set")?,
            secret_key: std::env::var("BINANCE_SECRET")
                .context("BINANCE_SECRET not set")?,
            base_url: std::env::var("BINANCE_BASE_URL")
                .unwrap_or_else(|_| "https://testnet.binancefuture.com".into()),
            port: std::env::var("EXECUTOR_PORT")
                .ok()
                .and_then(|s| s.parse().ok())
                .unwrap_or(8080),
            dry_run: std::env::var("EXECUTOR_DRY_RUN")
                .map(|v| v != "false" && v != "0")
                .unwrap_or(true),
        })
    }
}