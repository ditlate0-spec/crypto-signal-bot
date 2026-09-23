use clap::{Parser, Subcommand};
use crate::binance::BinanceClient;
use crate::config::Config;
use anyhow::Result;

#[derive(Parser)]
#[command(name = "executor")]
pub struct Cli {
    #[command(subcommand)]
    pub command: Command,
}

#[derive(Subcommand)]
pub enum Command {
    /// Проверка: баланс USDT
    Balance,
    /// Проверка: цена символа
    Price { symbol: String },
    /// Проверка: правила символа
    Info { symbol: String },
    /// Healthcheck для docker
    Health,
    /// Запустить HTTP-сервер
    Serve,
    /// Ручной тестовый прогон торговли (без HTTP)
    Trade {
        #[arg(long, default_value = "BTCUSDT")]
        symbol: String,
        #[arg(long, default_value_t = 100.0)]
        margin: f64,
        #[arg(long, default_value_t = 1)]
        leverage: u32,
        #[arg(long, default_value_t = 0.5)]
        sl_percent: f64,
    },
}

pub async fn run(cmd: Command) -> Result<()> {
    // healthcheck не требует Binance — только env
    if let Command::Health = cmd {
        let _ = Config::from_env()?;
        println!("ok");
        return Ok(());
    }

    let cfg = Config::from_env()?;
    let client = BinanceClient::new(
        cfg.api_key.clone(),
        cfg.secret_key.clone(),
        cfg.base_url.clone(),
    );

    match cmd {
        Command::Balance => {
            let b = client.get_balance_usdt().await?;
            println!("USDT balance: {:.2}", b);
        }
        Command::Price { symbol } => {
            let p = client.get_price(&symbol).await?;
            println!("{}: {:.2}", symbol, p);
        }
        Command::Info { symbol } => {
            let r = client.get_symbol_rules(&symbol).await?;
            println!(
                "{}: stepSize={} tickSize={} minNotional={}",
                symbol, r.step_size, r.tick_size, r.min_notional
            );
        }
        Command::Health => unreachable!(),
        Command::Serve => {
            crate::server::run(cfg, client).await?;
        }
         Command::Trade { symbol, margin, leverage, sl_percent } => {
            use crate::trading::{execute_trade, IdempotencyStore, TradeRequest};
            use std::sync::Arc;

            let store = IdempotencyStore::new();

            let key = format!(
                "cli_{}",
                std::time::SystemTime::now()
                    .duration_since(std::time::UNIX_EPOCH)
                    .unwrap()
                    .as_secs()
            );
            let req = TradeRequest {
                symbol,
                margin,
                leverage,
                sl_percent,
                trail_activate_percent: 0.2,
                trail_callback_rate: 0.1,
                idempotency_key: key,
            };

             let result = execute_trade(Arc::new(client), store, req, cfg.dry_run).await?;
            println!("{:#?}", result);
        }
    }
    Ok(())
}