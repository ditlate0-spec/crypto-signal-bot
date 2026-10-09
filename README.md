# Botcoin — Trading System с ML-фильтром

Торговая система для Binance Futures. Два KF-бота ищут признаки слива, ML-модель (LightGBM) отсеивает неуверенные сигналы, RSI-фильтр спасает серую зону, Rust-исполнитель открывает шорт с защитой.

## Как работает

1. Два KF-бота анализируют BTCUSDT и ETHUSDT на 15m / 1h / 1d. Каждый смотрит 3 последние свечи (тело, тени, объём, проливы) и выдаёт коэффициент KF.

2. Каскадная логика решает, входить или нет. Сигнал проходит, если совпадают фильтры по старшим ТФ (1D, 1H) и есть подтверждение на 15m.

3. ML-модель (LightGBM) отсеивает неуверенные сигналы. Оценивает вероятность TP. Если < 0.9 — вход не открывается.

4. RSI-фильтр для серой зоны ML. Если probability в диапазоне [0.83, 0.9), сигнал проверяется индикаторами RSI(7), cum5, bb %B. RSI может спасти сигнал.

5. Фильтр Fear & Greed. Если > 75 (экстремальная жадность) — торговля блокируется.

6. Rust-исполнитель открывает позицию. Идемпотентность, rollback при сбое SL, защита от наложения.

7. AI-агент раз в день анализирует закрытые сделки через LLM и выдаёт отчёт.

## Итоговое решение по входу

- ML TAKE (prob >= 0.85) — входим сразу
- ML SKIP, prob в [0.83, 0.9) — спрашиваем RSI
- ML SKIP, prob < 0.83 — пропуск
- ML SKIP, prob >= 0.9 — пропуск

## Результаты бэктеста

ВСЕГО СИГНАЛОВ БОТОВ: 594

ML:
- TAKE: 85
- TRAIL: 82, SL: 3

RSI (на SKIP от ML):
- TAKE: 98
- SKIP: 182
- TRAIL: 93, SL: 5

ФИНАЛ:
- Всего TAKE: 175 TRAIL + 8 SL + 0 TIMEOUT
- Winrate: 95.63%
- PnL (по цене): 66.16%
- PnL (с плечом): 3045.35%
- Expectancy: 16.6413% (с плечом)

ПО РЕЖИМАМ (PnL с плечом):
- [1h]  lev=45x  сделок=124  SL=5   TRAIL=119  WR=95.97%  PnL=2362.34%
- [15m] lev=50x  сделок=59   SL=3   TRAIL=56   WR=94.92%  PnL=683.01%

Модель отсеивает сигналы, в которых не уверена. RSI увеличивает число TAKE.

## Управление рисками

Stop Loss — фиксированный +0.9% от входа (для шорта выше входа). STOP_MARKET с workingType=MARK_PRICE. Если SL не выставился — Rust закрывает позицию немедленно. Голая позиция без стопа невозможна.

Trailing Stop по режиму:
- 15m: активация −0.2%, callback 0.1%
- 1h: активация −0.3%, callback 0.2%
- 1d: активация −0.8%, callback 0.5%

Плечо по режиму:
- 15m — 50x
- 1h — 45x
- 1d — 45x

Защита от наложения — Rust проверяет positionRisk. Если позиция открыта — новая не открывается.

Идемпотентность — idempotency_key хранится 24 часа. Защита от двойного cron и retry.

## Стек

- Backend: PHP 8.2, Laravel 12
- Executor: Rust 1.90, axum, tokio, reqwest
- ML: Python 3.13, LightGBM, scikit-learn, pandas
- AI-агент: Python 3.13, FastAPI, LangChain, Ollama Cloud
- База: MariaDB 10.4
- Инфра: Docker, Docker Compose, Nginx

## Запуск

git clone https://github.com/ditlate0-spec/botcoin.git
cd botcoin
cp .env.example .env
# указать ключи Binance Testnet и Telegram

docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate

Открыть: http://localhost:8000

## API Rust executor

Rust слушает 0.0.0.0:8080 внутри сети botcoin. PHP обращается по http://executor:8080.

GET /health — healthcheck для Docker и мониторинга.

POST /execute — открытие SHORT с автоматической защитой.

Request:
symbol — BTCUSDT
margin — 100
leverage — 45
sl_percent — 0.9
trail_activate_percent — 0.3
trail_callback_rate — 0.2
idempotency_key — уникальный ключ

Response (успех):
status: ok
result.status: opened
entry_price, quantity, leverage, sl_price, trail_activation_price, order_id, sl_order_id

HTTP-код всегда 200. Реальный статус в поле status.

## AI-агент

Раз в сутки в 09:00 МСК команда trades:analyze берёт закрытые сделки за 24 часа, отправляет в Python-сервис analyst, получает анализ от LLM.

Ручной запуск:
docker compose exec app php artisan trades:analyze --hours=24 --limit=200

## Структура проекта

- coin/ — PHP-скрипты (telegram_sender, model_check)
- executor/ — Rust microservice
- app/ — Laravel (Controllers, Services, Models)
- python/ — ML и AI-агент
- resources/views/ — dashboard.blade.php
- database/migrations/ — схема БД
- docker/nginx/ — конфиг nginx
- docker-compose.yml

## Known issues

Binance Testnet: /fapi/v1/algoOrder не поддерживается. Решение: fallback на /fapi/v1/order.

Trailing stop: -4136 closePosition not allowed. Fix: заменён на quantity + reduceOnly=true.

Testnet: stale positionRisk. Решение: подождать 1–2 минуты или Reset Testnet.

## Ограничения

- R:R стратегии 0.24 (TP +0.21%, SL −0.9%)
- Модель обучена на 2023–2024, проверена на 2025–2026
- Проект работает с Binance Testnet (DEMO)
- AI-агенту нужно 20–30 сделок для значимых выводов

## Changelog

2.1.0
- ML вызывается только при сигнале ботов
- RSI-фильтр синхронизирован с бэктестом [0.83, 0.9)
- TradingBotService::checkSignal() — единая логика для дашборда и бота
- Дашборд больше не открывает сделки
- Блок RSI на дашборде показывает причину пропуска

2.0.0
- AI-агент анализа сделок
- Rust-исполнитель с rollback и идемпотентностью
- Каскадная логика вердикта
- ML-модель LightGBM

## Лицензия

PolyForm Noncommercial 1.0.0 — коммерческое использование без письменного разрешения запрещено.

## Автор

Instagram: https://www.instagram.com/prod_23b/
GitHub: https://github.com/ditlate0-spec
