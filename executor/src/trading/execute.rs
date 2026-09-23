use std::sync::Arc;
use serde::{Deserialize, Serialize};

use crate::binance::BinanceClient;
use crate::error::{ExecutorError, Result};

#[derive(Debug, Clone, Deserialize, Serialize)]
pub struct TradeRequest {
    pub symbol: String,
    pub margin: f64,
    pub leverage: u32,
    pub sl_percent: f64,
    pub trail_activate_percent: f64,
    pub trail_callback_rate: f64,
    pub idempotency_key: String,
}

#[derive(Debug, Clone, Serialize)]
pub struct TradeResult {
    pub status: String,            // "opened" | "dry_run"
    pub symbol: String,
    pub side: String,              // "SHORT"
    pub entry_price: f64,
    pub quantity: f64,
    pub leverage: u32,
    pub sl_price: f64,
    pub trail_activation_price: f64,
    pub trail_callback_rate: f64,
    pub order_id: i64,
    pub sl_order_id: i64,
    pub idempotency_key: String,
}

pub async fn execute_trade(
    client: Arc<BinanceClient>,
    store: Arc<crate::trading::IdempotencyStore>,
    req: TradeRequest,
    dry_run: bool,
) -> Result<TradeResult> {
    tracing::info!(
        symbol = %req.symbol,
        margin = req.margin,
        leverage = req.leverage,
        dry_run,
        key = %req.idempotency_key,
        "execute_trade start"
    );

    // ============================================
    // 0. ИДЕМПОТЕНТНОСТЬ — вернуть прошлый результат
    // ============================================
    if let Some(prev) = store.get(&req.idempotency_key).await {
        tracing::warn!(
            key = %req.idempotency_key,
            "idempotency hit — returning cached result, no new order"
        );
        return Ok(prev);
    }

    // ============================================
    // 1. ЗАЩИТА ОТ НАЛОЖЕНИЯ
    // ============================================
    if let Some(pos) = client.get_position(&req.symbol).await? {
        return Err(ExecutorError::Binance {
            code: 0,
            msg: format!(
                "position already open on {}: amount={} entry={}",
                req.symbol, pos.amount, pos.entry_price
            ),
        });
    }

    // ============================================
    // 2. ЦЕНА + ПРАВИЛА
    // ============================================
    let price = client.get_price(&req.symbol).await?;
    let rules = client.get_symbol_rules(&req.symbol).await?;

    // ============================================
    // 3. РАСЧЁТ QUANTITY
    // ============================================
    let notional = req.margin * req.leverage as f64;
    let raw_qty = notional / price;
    let quantity = (raw_qty / rules.step_size).floor() * rules.step_size;
    let quantity = round_to_step(quantity, rules.step_size);

    if quantity <= 0.0 {
        return Err(ExecutorError::Parse(format!(
            "computed quantity is zero (notional={}, price={}, step={})",
            notional, price, rules.step_size
        )));
    }

    let actual_notional = quantity * price;
    if actual_notional < rules.min_notional {
        return Err(ExecutorError::Parse(format!(
            "notional {} below minNotional {} (qty={}, price={})",
            actual_notional, rules.min_notional, quantity, price
        )));
    }

    tracing::info!(
        price,
        quantity,
        notional = actual_notional,
        "computed order size"
    );

    // ============================================
    // 4. DRY RUN — выходим до отправки ордеров
    // ============================================
    if dry_run {
        let sl_price = price * (1.0 + req.sl_percent / 100.0);
        let trail_act = price * (1.0 - req.trail_activate_percent / 100.0);

        let result = TradeResult {
            status: "dry_run".into(),
            symbol: req.symbol.clone(),
            side: "SHORT".into(),
            entry_price: price,
            quantity,
            leverage: req.leverage,
            sl_price: round_to_tick(sl_price, rules.tick_size),
            trail_activation_price: round_to_tick(trail_act, rules.tick_size),
            trail_callback_rate: req.trail_callback_rate,
            order_id: 0,
            sl_order_id: 0,
            idempotency_key: req.idempotency_key.clone(),
        };

        store.set(req.idempotency_key.clone(), result.clone()).await;
        tracing::info!(?result, "DRY RUN result");
        return Ok(result);
    }

    // ============================================
    // 5. ПЛЕЧО + МАРЖА
    // ============================================
    client.set_leverage(&req.symbol, req.leverage).await?;
    client.set_margin_type(&req.symbol, "ISOLATED").await?;

    // ============================================
    // 6. ОТКРЫТИЕ SHORT
    // ============================================
    let order = client.open_short(&req.symbol, quantity).await?;
    tracing::info!(order_id = order.order_id, "short order submitted");

    // ============================================
    // 7. ОЖИДАНИЕ ИСПОЛНЕНИЯ
    // ============================================
    let filled = match client.wait_for_fill(&req.symbol, order.order_id, 5000).await {
        Ok(f) => f,
        Err(e) => {
            // Ордер не исполнился за 5 сек. Отменяем всё, что висит.
            tracing::error!(error = %e, "order not filled, cleaning up");
            let _ = client.cancel_all_orders(&req.symbol).await;
            return Err(e);
        }
    };

        // avgPrice может быть пустым в первом ответе — перезапрашиваем
    let mut entry_price: f64 = filled.avg_price.parse().unwrap_or(0.0);

    if entry_price <= 0.0 {
        tracing::warn!(
            "avgPrice is empty in first response, re-fetching order {}",
            order.order_id
        );
        tokio::time::sleep(std::time::Duration::from_millis(300)).await;

        let refetched = client.get_order(&req.symbol, order.order_id).await?;
        entry_price = refetched.avg_price.parse().unwrap_or(0.0);
    }

    let filled_qty: f64 = {
        let q = filled.executed_qty.parse().unwrap_or(0.0);
        if q > 0.0 { q } else { quantity }
    };

    if entry_price <= 0.0 {
        return Err(ExecutorError::Parse(format!(
            "entry price is zero after fill and re-fetch (order_id={}, status={})",
            order.order_id, filled.status
        )));
    }

    tracing::info!(entry_price, filled_qty, "short filled");

    // ============================================
    // 8. STOP LOSS — критическая секция
    // ============================================
    let sl_price = round_to_tick(
        entry_price * (1.0 + req.sl_percent / 100.0),
        rules.tick_size,
    );

    let sl_order = match client.set_stop_loss(&req.symbol, sl_price).await {
        Ok(sl) => sl,
        Err(e) => {
            tracing::error!(
                error = %e,
                "SL failed after open — ROLLBACK: closing position"
            );
            // ROLLBACK: закрываем позицию немедленно
            match client.close_position(&req.symbol, filled_qty).await {
                Ok(close) => {
                    tracing::warn!(
                        order_id = close.order_id,
                        "rollback succeeded, position closed"
                    );
                    return Err(ExecutorError::Binance {
                        code: 0,
                        msg: format!(
                            "SL failed ({}), position rolled back via order {}",
                            e, close.order_id
                        ),
                    });
                }
                Err(close_err) => {
                    // Худший сценарий: SL не выставился И позицию не удалось закрыть.
                    // Возвращаем максимально громкую ошибку.
                    return Err(ExecutorError::Binance {
                        code: 0,
                        msg: format!(
                            "CRITICAL: SL failed ({}) AND rollback failed ({}). \
                             Position may be naked. Manual intervention required.",
                            e, close_err
                        ),
                    });
                }
            }
        }
    };

        tracing::info!(sl_order_id = sl_order.algo_id, sl_price, "SL set");

    // ============================================
    // 9. TRAILING STOP — не критично, если упадёт
    // ============================================
    let trail_activation = round_to_tick(
        entry_price * (1.0 - req.trail_activate_percent / 100.0),
        rules.tick_size,
    );

    let _trail_order = match client
        .set_trailing_stop(&req.symbol, filled_qty, trail_activation, req.trail_callback_rate)
        .await
    {
        Ok(t) => {
            tracing::info!(trail_order_id = t.algo_id, "trailing stop set");
            Some(t)
        }
        Err(e) => {
            // Позиция уже со SL — не паникуем, но предупреждаем
            tracing::warn!(
                error = %e,
                "trailing stop failed, position protected by SL only"
            );
            None
        }
    };

    // ============================================
    // 10. РЕЗУЛЬТАТ
    // ============================================
    let result = TradeResult {
        status: "opened".into(),
        symbol: req.symbol.clone(),
        side: "SHORT".into(),
        entry_price,
        quantity: filled_qty,
        leverage: req.leverage,
        sl_price,
        trail_activation_price: trail_activation,
        trail_callback_rate: req.trail_callback_rate,
        order_id: order.order_id,
        sl_order_id: sl_order.algo_id,
        idempotency_key: req.idempotency_key.clone(),
    };

    store.set(req.idempotency_key.clone(), result.clone()).await;
    Ok(result)
}

// ============================================
// ХЕЛПЕРЫ
// ============================================

fn round_to_step(value: f64, step: f64) -> f64 {
    if step <= 0.0 { return value; }
    let precision = decimals_from_step(step);
    let rounded = (value / step).round() * step;
    // Убираем артефакты float вроде 0.30000000000000004
    let factor = 10f64.powi(precision as i32);
    (rounded * factor).round() / factor
}

fn round_to_tick(value: f64, tick: f64) -> f64 {
    if tick <= 0.0 { return value; }
    let precision = decimals_from_step(tick);
    let rounded = (value / tick).round() * tick;
    let factor = 10f64.powi(precision as i32);
    (rounded * factor).round() / factor
}

fn decimals_from_step(step: f64) -> u32 {
    // 0.0001 → 4, 0.1 → 1, 1.0 → 0
    let s = format!("{}", step);
    if let Some(dot) = s.find('.') {
        (s.len() - dot - 1) as u32
    } else {
        0
    }
}