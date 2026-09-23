# Botcoin — Trading System с ML-фильтром сигналов

Торговая система для Binance Futures, которая снижает количество убыточных сделок за счёт фильтрации сигналов через ML-модель, индекса страха и жадности и вынесения latency-critical операций в Rust-исполнитель.

## Как это работает

Система состоит из пяти слоёв, каждый решает свою задачу.

### Слой 1. Два KF-бота ищут признаки слива (обвал цены)

- **Бот №1** — BTCUSDT/ETHUSDT на 15m / 1h / 1d
- **Бот №2** — «слив по трём» (с текстовой интерпретацией)

Оба анализируют 3 последние свечи: тело, тени, объём, проливы — и выдают коэффициент KF.

### Слой 2. Каскадная логика разрешает вход

Сигнал проходит, если совпадают сильные фильтры по старшим ТФ (1D, 1H) и подтверждение на 15m.

### Слой 3. ML-модель отсеивает неуверенные сигналы

LightGBM, обучена на 1245 исторических сигналах. Для каждого сигнала оценивает вероятность TP. Если < 0.85 — сделка не открывается.

### Слой 4. Фильтр по индексу страха и жадности

Если индекс > 75 («Экстремальная жадность») — торговля блокируется. На пике эйфории рынок чаще разворачивается вверх, что опасно для шорта.

### Слой 5. Rust-исполнитель открывает позицию

Latency-critical операции вынесены в отдельный сервис:

- идемпотентность через `idempotency_key`
- автоматический rollback при сбое SL
- защита от наложения позиций
- гарантия отсутствия «голой» позиции без стопа

## Архитектура

```text
┌───────────────┐   HTTP POST /execute   ┌───────────────────┐
│  PHP/Laravel  │ ─────────────────────► │   Rust executor   │
│   (signals,   │                        │   (axum + tokio)  │
│    verdict,   │ ◄───────────────────── │                   │
│   ML filter)  │  {entry, sl, order_id} │   BinanceClient   │
└───────┬───────┘                        └─────────┬─────────┘
        │                                          │
        │ INSERT INTO trades                       │ MARKET SELL
        │                                          │ STOP_MARKET
        ▼                                          ▼
┌───────────────┐                        ┌───────────────────┐
│    MariaDB    │                        │ Binance Futures   │
│   (signals,   │                        │      Testnet      │
│    trades)    │                        │                   │
└───────────────┘                        └───────────────────┘
```

**Почему Rust:** торговые операции требуют предсказуемой latency, отсутствия гонок данных и гарантий на уровне компилятора. Rust даёт compile-time защиту от data races (ownership + borrow checker), отсутствие GC-пауз и явную типизацию состояний позиции.

## Результаты

Сравнение на исторических данных (walk-forward, без утечки):

| Метрика | Без фильтра | С ML-фильтром |
|---|---|---|
| Сигналов | 1142 | 757 |
| Winrate | 78.7% | **83.5%** |
| Отсеяно SL | — | **35.9%** |
| Потеряно TP | — | 20.7% |
| Expectancy на сделку | +0.091% | **+0.093%** |

Модель не «делает деньги из воздуха» — она отсеивает сигналы бота, в которых не уверена, повышая winrate за счёт сокращения количества сделок.

## Управление рисками

### Stop Loss

Фиксированный стоп-лосс **+0.5%** от цены входа (для шорта — выше входа).
Выставляется как `STOP_MARKET` с `workingType=MARK_PRICE` (защита от
манипуляций ценой последней сделки).

**Если SL не выставился после открытия позиции — Rust немедленно закрывает
позицию.** Голая позиция без стопа невозможна.

### Trailing Stop

Плавающий стоп-лосс с двумя параметрами:

- **Activation price** = −0.2% от входа. Для шорта: цена должна **упасть**
  на 0.2%, чтобы trailing активировался
- **Callback rate** = 0.1%. После активации trailing «следует» за ценой
  в сторону прибыли. Если цена откатится на 0.1% от максимума — позиция
  закроется

**Пример для шорта:**

| Событие | Цена |
|---|---|
| Вход | $86 295.80 |
| SL (+0.5%) | $86 727.30 |
| Trailing активация (−0.2%) | $86 123.20 |
| Цена упала до | $86 000.00 |
| Trailing следует за ценой | −0.1% от минимума |
| Если цена откатится до | $86 086.00 → позиция закроется |

**Если trailing не выставился** — позиция всё равно защищена фиксированным SL.
Это graceful degradation: trailing — улучшение, а не обязательное условие.

### Защита от наложения

Перед открытием Rust проверяет `positionRisk`. Если позиция уже открыта —
новая не открывается.

### Идемпотентность

См. раздел [API Rust executor → Идемпотентность](#идемпотентность).


## Стек

| Слой | Технологии |
|---|---|
| Backend | PHP 8.2, Laravel 12 |
| Executor | Rust 1.90, axum, tokio, reqwest, serde |
| ML | Python 3.13, LightGBM, scikit-learn, pandas |
| База | MariaDB 10.4 |
| Инфраструктура | Docker, Docker Compose, Nginx |
| Внешние API | Binance Futures Testnet, Telegram Bot API, alternative.me, RSS (5 источников) |

## Что реализовано

### Основная логика

- **Сбор и обработка рыночных данных** — свечи с Binance по 15m / 1h / 1d, парсинг, вычисление 45+ производных признаков
- **Два независимых KF-алгоритма** — правила на основе анализа 3 свечей (цена, тени, объём, проливы), с кэшированием результатов в БД по периодам (15 мин / час / сутки)
- **Каскадный вердикт** — многоуровневая проверка с порогами по ТФ
- **Обучение ML-модели** — 1245 исторических сигналов, walk-forward валидация, финальная модель LightGBM на 200 деревьях
- **Пайплайн вывода** — PHP → Python через `predict_one.py`, вероятность TP возвращается в торговый бот
- **Фильтр Fear & Greed** — блокировка торговли при индексе > 75
- **Уведомления в Telegram** — со всей контекстной информацией
- **Дашборд** — KF-сигналы, история, новости с sentiment-анализом

### Rust-исполнитель

- **Идемпотентность** через `idempotency_key` — повторный сигнал возвращает кэшированный результат, не открывает вторую позицию
- **Автоматический rollback** — если SL не выставился после открытия, позиция немедленно закрывается. Голая позиция невозможна.
- **Защита от наложения** — проверка `positionRisk` перед открытием
- **HMAC-SHA256 подпись** запросов к Binance
- **STOP LOSS** (`STOP_MARKET`, +0.5% от входа) — фиксированная защита
- **TRAILING STOP** (`TRAILING_STOP_MARKET`) — активация при −0.2% от
  входа, callback rate 0.1%. После активации стоп следует за ценой
  в сторону прибыли
- **Fallback** `/fapi/v1/algoOrder` → `/fapi/v1/order` для Testnet
- **DRY_RUN режим** (`EXECUTOR_DRY_RUN=true`) — безопасный расчёт
  без реальной торговли
- **Graceful degradation** — если trailing stop не выставился,
  позиция всё равно защищена SL

## Запуск

```bash
git clone https://github.com/ditlate0-spec/botcoin.git
cd botcoin
cp .env.example .env
# указать свои ключи Binance Testnet и Telegram в .env

docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
```

Открыть: http://localhost:8000

**Важно:** `composer install` и `key:generate` — обязательные шаги. Без первого Laravel упадёт с `vendor/autoload.php not found`, без второго — с пустым `APP_KEY` и 504 Gateway Timeout.

## Структура проекта

```text
coin/
  RustExecutorClient.php          — HTTP-клиент для Rust executor
  telegram_sender.php             — оркестрация: KF → вердикт → ML → Rust
  model_check.php                 — вызов Python ML-модели
  binance_demo.php                — старый Binance-клиент (deprecated)

executor/                         — Rust microservice
  src/
    main.rs                       — точка входа
    config.rs                     — чтение env
    cli.rs                        — CLI: balance / price / info / serve / trade
    error.rs                      — типы ошибок
    server.rs                     — axum-сервер
    binance/
      client.rs                   — HTTP-клиент с HMAC-подписью
      models.rs                   — serde-структуры ответов Binance
      sign.rs                     — HMAC-SHA256
    api/
      routes.rs                   — /health, /execute
    trading/
      execute.rs                  — execute_trade с rollback
      idempotency.rs              — in-memory store ключей (TTL 24h)

app/                              — Laravel-приложение
  Http/Controllers/DashboardController.php
  Models/                         — Eloquent-модели
  Services/
    BinanceService.php
    TelegramService.php
    FearGreedService.php
    NewsService.php
    ModelService.php
    KfBotService.php
    KfCalculatorService.php
    TradingBotService.php

python/
  predict_one.py                  — загрузка модели, расчёт 45 признаков
  tp_filter_model.pkl             — обученная LightGBM
  tp_filter_features.pkl          — порядок признаков

docker/nginx/default.conf
Dockerfile
docker-compose.yml
```

## API Rust executor

Rust-исполнитель слушает `0.0.0.0:8080` внутри Docker-сети `botcoin`. PHP обращается к нему по `http://executor:8080` (имя сервиса в `docker-compose.yml`).

### GET /health

Healthcheck для Docker и мониторинга. Используется в `healthcheck:` секции `docker-compose.yml`, чтобы `app` стартовал только после готовности executor'а.

**Response:**

```json
{ "status": "ok" }
```

### POST /execute

Открытие SHORT-позиции с автоматической защитой.

**Что делает Rust:**

1. Проверяет `idempotency_key` — если сигнал уже обрабатывался, возвращает кэш
2. Проверяет `positionRisk` — если позиция уже открыта, возвращает ошибку
3. Получает цену и правила символа (`stepSize`, `tickSize`, `minNotional`)
4. Считает `quantity = margin * leverage / price`, округляет по `stepSize`
5. Отправляет MARKET SELL на Binance
6. Ждёт `FILLED` через polling (до 5 секунд)
7. Выставляет `STOP_MARKET` SL через `/fapi/v1/algoOrder` (fallback на `/fapi/v1/order`)
8. Если SL не выставился — закрывает позицию немедленно (rollback)
9. Выставляет `TRAILING_STOP_MARKET` (не критично, если упадёт)
10. Возвращает результат

**Request:**

```json
{
  "symbol": "BTCUSDT",
  "margin": 100,
  "leverage": 1,
  "sl_percent": 0.5,
  "trail_activate_percent": 0.2,
  "trail_callback_rate": 0.1,
  "idempotency_key": "trade_2026-09-23_14:45"
}
```

**Поля:**

| Поле | Тип | Описание |
|---|---|---|
| `symbol` | string | Торговая пара, например `BTCUSDT` |
| `margin` | float | Маржа в USDT (например, 100) |
| `leverage` | int | Плечо (1–125) |
| `sl_percent` | float | Стоп-лосс в % от входа (например, 0.5) |
| `trail_activate_percent` | float | Активация trailing в % (default 0.2) |
| `trail_callback_rate` | float | Шаг отката trailing в % (default 0.1) |
| `idempotency_key` | string | Уникальный ключ сигнала (например, `md5('trade_' . $candle_time)`) |

**Response (успех):**

```json
{
  "status": "ok",
  "result": {
    "status": "opened",
    "symbol": "BTCUSDT",
    "side": "SHORT",
    "entry_price": 86504.8,
    "quantity": 0.0011,
    "leverage": 1,
    "sl_price": 86937.3,
    "trail_activation_price": 86301.4,
    "trail_callback_rate": 0.1,
    "order_id": 28598973686,
    "sl_order_id": 1000000215053860,
    "idempotency_key": "trade_2026-09-23_14:45"
  }
}
```

**Response (dry-run):**

Если в `.env` установлен `EXECUTOR_DRY_RUN=true`, ордера не отправляются на биржу, но всё остальное считается. `status` = `"dry_run"`, `order_id` и `sl_order_id` = `0`. Полезно для тестирования.

```json
{
  "status": "ok",
  "result": {
    "status": "dry_run",
    "symbol": "BTCUSDT",
    "side": "SHORT",
    "entry_price": 86556.3,
    "quantity": 0.0011,
    "leverage": 1,
    "sl_price": 86989.1,
    "trail_activation_price": 86383.2,
    "trail_callback_rate": 0.1,
    "order_id": 0,
    "sl_order_id": 0,
    "idempotency_key": "manual_test_1790125825"
  }
}
```

**Response (ошибка):**

```json
{
  "status": "error",
  "error": "position already open on BTCUSDT: amount=-0.0011 entry=86504.8"
}
```

Возможные ошибки:

- `position already open on ...` — защита от наложения
- `notional 45.2 below minNotional 50` — позиция слишком маленькая
- `SL failed (...), position rolled back via order ...` — SL не выставился, позиция закрыта
- `CRITICAL: SL failed AND rollback failed` — требуется ручное вмешательство (на Testnet не случалось)

HTTP-код всегда 200. Реальный статус — в поле `status` (`"ok"` / `"error"`). Это сделано, чтобы PHP не ловил 500-е и не падал.

### Идемпотентность

Каждый запрос содержит `idempotency_key`. Rust хранит in-memory HashMap с результатами (TTL 24 часа). Если приходит запрос с уже известным ключом — возвращается кэшированный результат, а не открывается новая позиция.

Это защищает от:

- Двойного cron-запуска на одной 15m-свече
- Retry при сетевом сбое PHP → Rust
- Случайного двойного вызова

Пример из логов:

```text
01:12:41 INFO received /execute key=trade_2026-09-23_14:45
01:12:42 INFO short filled entry_price=86504.8
01:12:43 INFO SL set sl_order_id=1000000215053860
01:12:43 INFO trade executed status=opened

01:13:15 INFO received /execute key=trade_2026-09-23_14:45
01:13:15 WARN idempotency hit — returning cached result, no new order
01:13:15 INFO trade executed status=opened
```

### Rollback при сбое SL

Критическая секция: после открытия позиции Rust обязан выставить SL. Если `set_stop_loss` падает — Rust немедленно закрывает позицию через MARKET BUY (`reduceOnly=true`).

Пример из логов (реальный случай с `-4120`):

```text
SL failed after open — ROLLBACK: closing position
  error=binance error -4120: Order type not supported
rollback succeeded, position closed order_id=28598937402
Error: SL failed (...), position rolled back
```

Голая позиция без стопа невозможна. Если и rollback упадёт — Rust вернёт `CRITICAL` ошибку, и PHP отправит её в Telegram для ручного вмешательства.

## Скриншоты

### Дашборд

![Дашборд](screen/screenshot-dashboard.png)

### Открытие и закрытие сделки

![Открытие сделки](screen/opening%20and%20closing%20a%20trade.png)
![Закрытие сделки](screen/opening%20and%20closing%20a%20trade%202.png)

### Уведомления в Telegram

![Telegram](screen/screenshot-telegram.png)

### Тестирование

![Тест](screen/screenshot-test.png)

## Known issues

### Binance Testnet: /fapi/v1/algoOrder не поддерживается

С декабря 2025 Binance мигрировал условные ордера (`STOP_MARKET`, `TRAILING_STOP_MARKET`) на новый эндпоинт `/fapi/v1/algoOrder`. На Testnet этот эндпоинт отстаёт — возвращает `-4120`.

**Решение:** в `client.rs` реализован fallback — если `/fapi/v1/algoOrder` возвращает `-4120`, запрос повторяется на старый `/fapi/v1/order`.

### Trailing stop: -4136 closePosition not allowed (RESOLVED)

Binance не разрешает `closePosition=true` для `TRAILING_STOP_MARKET`
в новом Algo API. Возвращает `-4136`.

**Fix:** заменён `closePosition=true` на `quantity + reduceOnly=true`.
Trailing stop успешно выставляется:

SL set sl_order_id=1000000215123699 sl_price=86727.3
trailing stop set trail_order_id=1000000215123704

### Testnet: stale positionRisk

Иногда `/fapi/v2/positionRisk` возвращает устаревшие данные после закрытия позиции. Позиция на бирже уже закрыта (видно в UI), но API говорит, что открыта. Rust отказывается открывать новую позицию (защита от наложения).

**Решение:** подождать 1–2 минуты, либо сделать Reset Testnet.

## Ограничения

- **R:R стратегии 0.42** (TP +0.21%, SL −0.5%) — фундаментальное ограничение прибыльности. Фильтр повышает winrate, но не исправляет соотношение риск/прибыль.
- Модель обучена на 2023–2024 и проверена на 2025–2026.
- Проект работает с **Binance Testnet** (DEMO, виртуальные деньги).

## Лицензия

PolyForm Noncommercial 1.0.0 — запрещено коммерческое использование без отдельного письменного разрешения.

Для получения коммерческой лицензии свяжитесь: https://github.com/ditlate0-spec

## Автор

- Instagram: https://www.instagram.com/prod_23b/
- GitHub: https://github.com/ditlate0-spec