# Botcoin — Trading System с ML-фильтром сигналов

Торговая система для Binance Futures, которая снижает количество убыточных сделок за счёт фильтрации сигналов через ML-модель, RSI-индикаторов, индекса страха и жадности и вынесения latency-critical операций в Rust-исполнитель.

## Как это работает

Система состоит из семи слоёв, каждый решает свою задачу.

### Слой 1. Два KF-бота ищут признаки слива (обвал цены)

- **Бот №1** — BTCUSDT/ETHUSDT на 15m / 1h / 1d
- **Бот №2** — «слив по трём» (с текстовой интерпретацией)

Оба анализируют 3 последние свечи: тело, тени, объём, проливы — и выдают коэффициент KF.

### Слой 2. Каскадная логика разрешает вход

Сигнал проходит, если совпадают сильные фильтры по старшим ТФ (1D, 1H) и подтверждение на 15m.

### Слой 3. ML-модель отсеивает неуверенные сигналы

LightGBM, обучена на 1245 исторических сигналах. Для каждого сигнала оценивает вероятность TP. Если < 0.85 — сделка не открывается.

### Слой 4. RSI-фильтр для «серой зоны» ML

Если ML-модель не уверена — её вероятность попадает в диапазон **`[0.83, 0.9)`** — сигнал дополнительно проверяется RSI-индикаторами. Это «серая зона», где модель не даёт однозначного «нет», и RSI может «спасти» сигнал.

**Что считается:**

| Индикатор | Описание | Порог |
|---|---|---|
| **RSI(7)** | Индекс силы за 7 свечей | `> 15` |
| **cum5, %** | Накопленная доходность за 5 свечей | `∈ (-1.1, -0.30)` |
| **bb %B** | Позиция в Bollinger Bands (20, 2.0) | `∈ (-0.15, 0.80)` |

**Логика решения:**

- RSI `TAKE` → сигнал проходит, сделка открывается.
- RSI `SKIP` → сигнал пропускается.

**Итоговое решение:**

| Ситуация | Что делаем |
|---|---|
| ML `TAKE` (prob ≥ 0.85) | Входим сразу, RSI не проверяется |
| ML `SKIP`, prob ∈ `[0.83, 0.9)` | Спрашиваем RSI — он может «спасти» сигнал |
| ML `SKIP`, prob < 0.83 | Модель уверенно против — пропуск |
| ML `SKIP`, prob ≥ 0.9 | Не серая зона — пропуск |

### Слой 5. Фильтр по индексу страха и жадности

Если индекс > 75 («Экстремальная жадность») — торговля блокируется.

### Слой 6. Rust-исполнитель открывает позицию

Latency-critical операции вынесены в отдельный сервис:

- идемпотентность через `idempotency_key`
- автоматический rollback при сбое SL
- защита от наложения позиций
- гарантия отсутствия «голой» позиции без стопа

### Слой 7. AI-агент анализирует сделки

После каждой закрытой сделки система накапливает контекст в журнале `ai_trade_journal`. Раз в сутки LLM-агент анализирует данные и выдаёт отчёт с закономерностями и рекомендациями.

## Что нового в 2.1.0

### ML вызывается только при сигнале ботов

Раньше модель запускалась на **каждом** открытии дашборда — это занимало 10–30 секунд и приводило к 504 Gateway Timeout. Теперь ML вызывается **только если боты дали сигнал** через каскадную логику.

Реализовано через новый публичный метод `TradingBotService::checkSignal()`, который:

- читает KF (из БД или из переданных live-данных),
- строит вердикт,
- **останавливается**, если сигнала нет,
- вызывает ML только при `verdict['enter'] === true`,
- вызывает RSI только если `prob ∈ [0.83, 0.9)`.

### RSI-фильтр синхронизирован с бэктестом

Диапазон `[0.83, 0.9)` и пороги индикаторов теперь **едины** для трёх мест:

- `backtest_ML.php`
- `DashboardController` (то, что ты видишь на экране)
- `TradingBotService` (то, что реально торгует)

### Единая логика через `checkSignal()`

`run()` теперь **использует** `checkSignal()` внутри:

```php
public function run(): array
{
    $check = $this->checkSignal();       // проверка без открытия сделки
    if (!$check['approved']) { ... }      // пропуск
    $trade = $this->openTrade(...);       // открытие
    ...
}
```

Дашборд вызывает **тот же** `checkSignal()`, но **не открывает сделку**. Это значит: что ты видишь на экране — то и делает бот.

### Дашборд больше не открывает сделки

Раньше `DashboardController::index()` вызывал `tradingBot->run()` — то есть **каждое открытие дашборда** могло открыть сделку. Теперь контроллер вызывает `checkSignal()` — только проверка.

### Индикаторы на дашборде

Блок «📊 RSI-фильтр» показывает:

- RSI(7), cum5, bb %B — если RSI проверялся,
- «⏭ RSI не проверялся: ML TAKE» — если ML уверена,
- «⏭ RSI не проверялся: ML вне серой зоны (prob, dec)» — если prob не попал в `[0.83, 0.9)`,
- «⏭ RSI не проверялся: Нет сигнала ботов» — если каскад не сработал.

### Убран `bulls10`

Индикатор `bulls10` больше не считается и не участвует в решении — он не использовался.

## Архитектура

```
┌───────────────┐   HTTP POST /execute   ┌───────────────────┐
│  PHP/Laravel  │ ─────────────────────► │   Rust executor   │
│   (signals,   │                        │   (axum + tokio)  │
│    verdict,   │ ◄───────────────────── │                   │
│   ML filter)  │  {entry, sl, order_id} │   BinanceClient   │
└───────┬───────┘                        └─────────┬─────────┘
        │                                          │
        │ INSERT INTO trades, ai_trade_journal     │ MARKET SELL
        │                                          │ STOP_MARKET
        ▼                                          ▼
┌───────────────┐                        ┌───────────────────┐
│    MariaDB    │                        │ Binance Futures   │
│   (signals,   │                        │      Testnet      │
│    trades,    │                        │                   │
│ ai_trade_     │                        └───────────────────┘
│   journal)    │
└───────┬───────┘
        │ раз в день (09:00 МСК)
        ▼
┌─────────────────────────────────────────┐
│ AI-агент (Python + LangChain)           │
│ - trades:analyze                        │
│ - POST /analyze → Ollama Cloud          │
│ - storage/app/private/analytics/*.txt   │
└─────────────────────────────────────────┘
```

**Почему Rust:** торговые операции требуют предсказуемой latency, отсутствия гонок данных и гарантий на уровне компилятора.

## Результаты

Сравнение на исторических данных (walk-forward, без утечки):

ВСЕГО СИГНАЛОВ БОТОВ: 594

ЭТАП 2 — ML-МОДЕЛЬ:
  TAKE:      127
  SKIP:      0
  Из них TP: 118, SL: 9

ЭТАП 3 — ИНДИКАТОРЫ (на SKIP от ML):
  Проверено: 0
  TAKE:      57
  SKIP:      410
  Из них TP: 54, SL: 3

ФИНАЛ (ML + RSI):
  Всего TAKE: 172 TP + 12 SL + 0 TO
  Winrate:    93.48%
  PnL:        28.76%

ПРОПУЩЕНО:
  Всего SKIP: 410
  Из них TP:  362, SL: 48

Модель не «делает деньги из воздуха» — она отсеивает сигналы бота, в которых не уверена, повышая winrate за счёт сокращения количества сделок. RSI увеличивает колличество TAKE.

## Управление рисками

### Stop Loss

Фиксированный стоп-лосс **+0.9%** от цены входа (для шорта — выше входа). Выставляется как `STOP_MARKET` с `workingType=MARK_PRICE`.

**Если SL не выставился после открытия позиции — Rust немедленно закрывает позицию.** Голая позиция без стопа невозможна.

### Trailing Stop

Плавающий стоп-лосс:

- **Activation price** = −0.2% от входа
- **Callback rate** = 0.1%

Если trailing не выставился — позиция всё равно защищена фиксированным SL.

### Защита от наложения

Перед открытием Rust проверяет `positionRisk`. Если позиция уже открыта — новая не открывается.

### Идемпотентность

Каждый запрос содержит `idempotency_key`. Rust хранит in-memory HashMap с результатами (TTL 24 часа). Повторный запрос возвращает кэш.

Защищает от:

- Двойного cron-запуска
- Retry при сбое PHP → Rust
- Случайного двойного вызова

## Стек

| Слой | Технологии |
|---|---|
| Backend | PHP 8.2, Laravel 12 |
| Executor | Rust 1.90, axum, tokio, reqwest, serde |
| ML | Python 3.13, LightGBM, scikit-learn, pandas |
| AI-агент | Python 3.13, FastAPI, LangChain, Ollama Cloud (`gpt-oss:120b`) |
| База | MariaDB 10.4 |
| Инфраструктура | Docker, Docker Compose, Nginx |
| Внешние API | Binance Futures Testnet, Telegram Bot API, alternative.me, RSS (5 источников), Ollama Cloud |

## Что реализовано

### Основная логика

- Сбор и обработка рыночных данных (15m / 1h / 1d)
- Два независимых KF-алгоритма
- Каскадный вердикт
- Обучение ML-модели (1245 сигналов, LightGBM)
- RSI-фильтр для серой зоны ML (`[0.83, 0.9)`) — RSI(7), cum5, bb %B
- Пайплайн вывода PHP → Python
- Фильтр Fear & Greed
- Уведомления в Telegram
- Дашборд: KF, история, RSI, новости, AI-анализ
- Единая логика проверки сигнала (`checkSignal()`) для дашборда и бота

### Rust-исполнитель

- Идемпотентность через `idempotency_key`
- Автоматический rollback при сбое SL
- Защита от наложения
- HMAC-SHA256 подпись
- STOP_MARKET SL + TRAILING_STOP_MARKET
- Fallback `/fapi/v1/algoOrder` → `/fapi/v1/order`
- DRY_RUN режим
- Graceful degradation

### AI-агент анализа сделок

После закрытия каждой сделки система накапливает полный контекст в таблице `ai_trade_journal`. Раз в сутки AI-агент анализирует данные и выдаёт отчёт.

**Как работает:**

1. При **открытии** сделки `telegram_sender.php` пишет в `ai_trade_journal`:
   - 12 KF-коэффициентов
   - ML-вероятность TP и порог
   - Fear & Greed
   - Новости (счётчики + топ-5 + сентимент)
   - Вердикт системы

2. При **закрытии** Rust ловит `user_stream` → `POST /notify/position-closed` → Laravel обновляет: `exit_price`, `exit_time`, `exit_reason`, `pnl`, `fees`, `is_win`, `duration_seconds`.

   PnL и fees считаются сами:
   - `pnl = (entry_price − exit_price) × quantity` (для SHORT)
   - `fees = (entry_price + exit_price) × quantity × 0.0004`

3. **Раз в день в 09:00 МСК** Laravel-команда `trades:analyze`:
   - Берёт закрытые сделки за 24 часа
   - Отправляет в Python-сервис `analyst`
   - Получает анализ от LLM
   - Сохраняет в `storage/app/private/analytics/analysis_*.txt`
   - Показывает на дашборде

**Что анализирует агент:**

- Winrate по новостному сентименту
- Winrate по Fear & Greed
- Winrate по ML-score
- Общее у прибыльных / убыточных
- Проблемы
- Конкретные рекомендации

**Пример рекомендации:**

> «Комиссии (0.076 USDT) составляют почти 38% от чистой прибыли (0.1986 USDT). Сократить размер комиссии: использовать биржу/пару с более низкой taker-fee.»

**Команда:**

```bash
docker compose exec app php artisan trades:analyze --hours=24 --limit=200
```

**Расписание:**

```php
Schedule::command('trades:analyze --hours=24 --limit=200')
    ->dailyAt('09:00')
    ->timezone('Europe/Moscow');
```

**Ограничение:** для статистически значимых выводов нужно 20–30 сделок.

## Запуск

```bash
git clone https://github.com/ditlate0-spec/botcoin.git
cd botcoin
cp .env.example .env
# указать ключи Binance Testnet и Telegram

# Опционально: для AI-агента
# OLLAMA_API_KEY=ваш_ключ
# OLLAMA_MODEL=gpt-oss:120b
# ANALYST_URL=http://analyst:8001

docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Открыть: http://localhost:8000

**Важно:** `composer install` и `key:generate` — обязательные шаги.

**AI-агент требует:**

- Ollama Cloud API key — [ollama.com/settings/keys](https://ollama.com/settings/keys)
- Бесплатный тариф даёт ограниченные кредиты, но при одном анализе в день хватит надолго.

## Структура проекта

```
coin/
  RustExecutorClient.php          — HTTP-клиент для Rust executor
  telegram_sender.php             — KF → вердикт → ML → RSI → Rust + INSERT в ai_trade_journal
  model_check.php                 — вызов Python ML-модели
  binance_demo.php                — старый Binance-клиент (deprecated)

executor/                         — Rust microservice
  src/
    main.rs                       — точка входа
    cli.rs                        — CLI: balance / price / info / serve / trade
    server.rs                     — axum-сервер
    binance/
      client.rs                   — HTTP-клиент с HMAC-подписью
      models.rs                   — serde-структуры
      sign.rs                     — HMAC-SHA256
    api/routes.rs                 — /health, /execute
    trading/
      execute.rs                  — execute_trade с rollback
      idempotency.rs              — in-memory store ключей
    user_stream.rs                — слушает Binance, ловит закрытие, шлёт webhook

app/                              — Laravel-приложение
  Http/Controllers/DashboardController.php
  Models/AiTradeJournal.php
  Console/Commands/AnalyzeTrades.php
  Services/
    BinanceService.php            — свечи, цена, ордера, позиции
    TelegramService.php           — уведомления
    FearGreedService.php          — индекс страха и жадности
    NewsService.php               — новости + сентимент
    ModelService.php              — вызов Python ML-модели
    KfBotService.php              — бот №1 → oth_*
    KfCalculatorService.php       — бот №2 → old_bot_signals_*
    IndicatorsService.php         — RSI(7), cum5, bb %B
    TradingBotService.php         — вердикт → checkSignal() → ML → RSI → шорт

python/
  predict_one.py                  — загрузка ML-модели
  tp_filter_model.pkl             — обученная LightGBM
  tp_filter_features.pkl          — порядок признаков
  analyst/
    __init__.py
    main.py                       — FastAPI + ChatOpenAI → Ollama Cloud
    prompts.py                    — System + Analysis prompts
    formatter.py                  — format_trades() + calculate_stats()
  Dockerfile.analyst
  requirements.txt

resources/views/
  dashboard.blade.php             — дашборд (KF, RSI, ML, новости, AI-анализ)

database/migrations/
  *_create_ai_trade_journal_table.php

docker/nginx/default.conf
Dockerfile
docker-compose.yml
```

## API Rust executor

Rust-исполнитель слушает `0.0.0.0:8080` внутри сети `botcoin`. PHP обращается по `http://executor:8080`.

### GET /health

Healthcheck для Docker и мониторинга.

**Response:**

```json
{ "status": "ok" }
```

### POST /execute

Открытие SHORT-позиции с автоматической защитой.

**Что делает Rust:**

1. Проверяет `idempotency_key`
2. Проверяет `positionRisk`
3. Получает цену и правила символа
4. Считает `quantity`
5. Отправляет MARKET SELL
6. Ждёт `FILLED`
7. Выставляет `STOP_MARKET`
8. Rollback при сбое SL
9. Выставляет `TRAILING_STOP_MARKET`
10. Возвращает результат

**Request:**

```json
{
  "symbol": "BTCUSDT",
  "margin": 100,
  "leverage": 1,
  "sl_percent": 0.9,
  "trail_activate_percent": 0.2,
  "trail_callback_rate": 0.1,
  "idempotency_key": "trade_2026-09-23_14:45"
}
```

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
    "sl_price": 87283.3,
    "trail_activation_price": 86301.4,
    "order_id": 28598973686,
    "sl_order_id": 1000000215053860,
    "idempotency_key": "trade_2026-09-23_14:45"
  }
}
```

**Response (dry-run):**

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
    "sl_price": 87335.2,
    "trail_activation_price": 86383.2,
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

- `position already open on ...`
- `notional 45.2 below minNotional 50`
- `SL failed (...), position rolled back`
- `CRITICAL: SL failed AND rollback failed`

HTTP-код всегда 200. Реальный статус — в `status`.

### Идемпотентность

Rust хранит in-memory HashMap с результатами (TTL 24 часа). Повторный запрос возвращает кэш.

Защищает от:

- Двойного cron-запуска
- Retry при сбое PHP → Rust
- Случайного двойного вызова

### Rollback при сбое SL

Если `set_stop_loss` падает — Rust немедленно закрывает позицию через MARKET BUY (`reduceOnly=true`). Голая позиция без стопа невозможна.

## Скриншоты

### Дашборд

![Дашборд](screen/screenshot-dashboard.png)

### AI-анализ на дашборде

![AI-анализ](screen/screenshot-ai-analysis.png)

### Открытие и закрытие сделки

![Открытие сделки](screen/opening%20and%20closing%20a%20trade.png)
![Закрытие сделки](screen/opening%20and%20closing%20a%20trade%202.png)

### Уведомления в Telegram

![Telegram](screen/screenshot-telegram.png)

### Тестирование

![Тест](screen/screenshot-test.png)

## Known issues

### Binance Testnet: /fapi/v1/algoOrder не поддерживается

С декабря 2025 Binance мигрировал условные ордера на новый эндпоинт. На Testnet он отстаёт — возвращает `-4120`.

**Решение:** в `client.rs` реализован fallback на `/fapi/v1/order`.

### Trailing stop: -4136 closePosition not allowed (RESOLVED)

Binance не разрешает `closePosition=true` для `TRAILING_STOP_MARKET`. Возвращает `-4136`.

**Fix:** заменён `closePosition=true` на `quantity + reduceOnly=true`.

### Testnet: stale positionRisk

Иногда `/fapi/v2/positionRisk` возвращает устаревшие данные.

**Решение:** подождать 1–2 минуты, либо Reset Testnet.

## Ограничения

- R:R стратегии 0.24 (TP +0.21%, SL −0.9%)
- Модель обучена на 2023–2024, проверена на 2025–2026
- Проект работает с Binance Testnet (DEMO)
- AI-агенту нужно 20–30 сделок для значимых выводов
- `TradingBot::run()` пока вызывается через дашборд — переедет в Scheduler в 2.2.0

## Changelog

### 2.1.0 (текущая)

- **ML вызывается только при сигнале ботов** — дашборд открывается быстро, 504 больше нет
- **RSI-фильтр синхронизирован с бэктестом** — диапазон `[0.83, 0.9)`, пороги едины для трёх мест
- **Индикаторы RSI(7), cum5, bb %B** — считаются в серой зоне ML
- **`TradingBotService::checkSignal()`** — публичный метод проверки без открытия сделки
- **`run()` использует `checkSignal()`** — единая логика для дашборда и бота
- **Дашборд больше не открывает сделки** — `DashboardController` вызывает `checkSignal()`, не `run()`
- **`bulls10` убран** из `IndicatorsService`
- **Блок RSI на дашборде** показывает причину пропуска (нет сигнала / ML TAKE / ML вне серой зоны)

### 2.0.0

- AI-агент анализа сделок
- Rust-исполнитель с rollback и идемпотентностью
- Каскадная логика вердикта
- ML-модель LightGBM

## Лицензия

PolyForm Noncommercial 1.0.0 — запрещено коммерческое использование без письменного разрешения.

Для коммерческой лицензии: https://github.com/ditlate0-spec

## Автор

- Instagram: https://www.instagram.com/prod_23b/
- GitHub: https://github.com/ditlate0-spec