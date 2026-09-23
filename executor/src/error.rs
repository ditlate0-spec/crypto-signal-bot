use thiserror::Error;

#[derive(Debug, Error)]
pub enum ExecutorError {
    #[error("http error: {0}")]
    Http(#[from] reqwest::Error),

    #[error("binance error {code}: {msg}")]
    Binance { code: i64, msg: String },

    #[error("config error: {0}")]
    Config(String),

    #[error("parse error: {0}")]
    Parse(String),
}

pub type Result<T> = std::result::Result<T, ExecutorError>;