use std::collections::HashMap;
use std::sync::Arc;
use std::time::{Duration, Instant};
use tokio::sync::Mutex;

use super::TradeResult;

const TTL: Duration = Duration::from_secs(24 * 60 * 60); // 24 часа

struct Entry {
    result: TradeResult,
    created_at: Instant,
}

pub struct IdempotencyStore {
    inner: Mutex<HashMap<String, Entry>>,
}

impl IdempotencyStore {
    pub fn new() -> Arc<Self> {
        Arc::new(Self {
            inner: Mutex::new(HashMap::new()),
        })
    }

    /// Вернуть сохранённый результат, если ключ уже обрабатывался
    pub async fn get(&self, key: &str) -> Option<TradeResult> {
        let mut map = self.inner.lock().await;

        // Чистим протухшие записи заодно
        let now = Instant::now();
        map.retain(|_, e| now.duration_since(e.created_at) < TTL);

        map.get(key).map(|e| e.result.clone())
    }

    /// Сохранить результат под ключом
    pub async fn set(&self, key: String, result: TradeResult) {
        let mut map = self.inner.lock().await;
        map.insert(
            key,
            Entry {
                result,
                created_at: Instant::now(),
            },
        );
    }

    /// Для отладки: сколько ключей в памяти
    pub async fn len(&self) -> usize {
        self.inner.lock().await.len()
    }
}