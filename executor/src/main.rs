mod api;
mod binance;
mod cli;
mod config;
mod error;
mod server;
mod trading; 

use clap::Parser;
use cli::{Cli, run};
use tracing_subscriber::{fmt, EnvFilter};

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    dotenvy::dotenv().ok();

    fmt()
        .with_env_filter(EnvFilter::try_from_default_env()
            .unwrap_or_else(|_| EnvFilter::new("info")))
        .init();

    let cli = Cli::parse();
    run(cli.command).await
}