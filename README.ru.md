# 🤖 Crypto Signal Bot — BTC/ETH

Торговый бот для криптовалют, объединяющий три независимых источника сигналов + нейросеть прогноза цен + рыночный сентимент. Пишет сигналы в MySQL, отправляет сводные уведомления в Telegram, отображает дашборд в браузере.

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1?logo=mysql&logoColor=white)
![Python](https://img.shields.io/badge/Python-3.11-3776AB?logo=python&logoColor=white)
![Telegram](https://img.shields.io/badge/Telegram-Bot-26A5E4?logo=telegram&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-2496ED?logo=docker&logoColor=white)

## 📌 О проекте

Бот анализирует рынок по **трём независимым стратегиям** + **нейросетевой прогноз** + **рыночные индикаторы** (индекс страха и жадности, тональность новостей). Все данные агрегируются в одну сводку и отправляются в Telegram. Дашборд показывает состояние сигналов в реальном времени.

### 🎯 Точность стратегий

Все стратегии **обучены и оптимизированы на исторических данных** и показывают точность выше случайной:

| Стратегия | Точность | Метод оптимизации |
|---|---|---|
| **KF-сигналы** | **> 66%** | Полный перебор параметров на истории |
| **Слив по тренду** | **> 66%** | Разделение на обучающую/тестовую выборку (70/30) |
| **Kronos Neural Network** | **> 60%** | Предобученная модель + токенизатор |

*Метрика: точность = доля правильных сигналов (падение после сигнала) / общее количество сигналов.*

### Источники сигналов

| Источник | Таймфреймы |
|---|---|
| KF-сигналы | 1D, 1H, 15M |
| Слив по тренду | 1D, 1H, 15M |
| Kronos Neural Network | 1D, 1H, 15M |
| Fear & Greed Index | — |
| Crypto News Sentiment | — |

## 🚀 Возможности

- ✅ Три независимые стратегии сигналов (**точность > 66%**)
- ✅ Нейросетевой прогноз цены (Kronos, **точность > 60%**)
- ✅ Анализ тональности новостей (NLP по словарям)
- ✅ Индекс страха и жадности
- ✅ Сводные Telegram-уведомления с дедупликацией
- ✅ Веб-дашборд с историей сигналов
- ✅ Кэширование (нейросеть, RSS, Fear & Greed)
- ✅ Поддержка BTC + ETH
- ✅ Запуск через Docker (одной командой)

## 🛠 Технологии

- **Backend:** PHP 8.x (без фреймворков)
- **БД:** MySQL 8.x
- **Python:** 3.11 + PyTorch + Kronos
- **API данных:** Binance Public API, Alternative.me, RSS-ленты
- **Уведомления:** Telegram Bot API
- **Деплой:** Docker, docker-compose

## 🏗 Архитектура
Binance API ──┐
RSS-ленты ──┼──► PHP-боты (анализ свечей) ──► MySQL
Fear&Greed ──┘ │
▼
Kronos (Python) ──► neural_predictions ──► online.php ──► Telegram
│
▼
Web Dashboard

text

### Схема БД

| Таблица | Назначение |
|---|---|
| `oth_1d`, `oth_1h`, `oth_15m` | KF-сигналы |
| `old_bot_signals_1d`, `_1h`, `_15m` | Сигналы «слив по тренду» |
| `neural_predictions` | Прогнозы нейросети Kronos |
| `telegram_sent` | Дедупликация Telegram-сообщений |

**SQL-схема:** см. `schema.sql`

## 📂 Структура проекта
.
├── online.php
├── schema.sql
├── Dockerfile
├── docker-compose.yml
├── LICENSE
├── README.md
├── README.ru.md
├── coin/
│ ├── BTCUSDT1D.php
│ ├── BTCUSDT1H.php
│ ├── BTCUSDT15M.php
│ ├── old_bot_1D.php
│ ├── old_bot_1H.php
│ ├── old_bot_15m.php
│ ├── conn.php
│ ├── news.php
│ ├── telegram_sender.example.php
│ └── telegram_sender.php (gitignored)
├── Kronos-master/
│ ├── kronos_analyzer.py
│ ├── requirements.txt
│ └── model/
└── screenshots/
└── dashboard.png

text

## 🐳 Docker (рекомендуется)

Самый быстрый способ запустить проект.

### Требования
- Docker Desktop

### Шаги

```bash
git clone https://github.com/ditlate0-spec/crypto-signal-bot.git
cd crypto-signal-bot
cp coin/telegram_sender.example.php coin/telegram_sender.php
# Открой coin/telegram_sender.php и вставь свой TG-токен и chat_id
docker compose up -d --build
Открой http://localhost:8080/online.php

Что внутри:

crypto_web — PHP 8.2 + Apache + Python 3.11 + Kronos

crypto_mysql — MySQL 8.0 (схема автоматически загружается из schema.sql)

Остановить:

bash
docker compose down
🔧 Ручная установка
Требования
PHP 8.x

MySQL 8.x

Python 3.11

PyTorch + transformers

Шаги
Клонировать репозиторий

bash
git clone https://github.com/ditlate0-spec/crypto-signal-bot.git
cd crypto-signal-bot
Создать БД

bash
mysql -u root -p < schema.sql
Установить Python-зависимости

bash
pip install -r Kronos-master/requirements.txt
Скачать модель Kronos

С HuggingFace: NeoQuasar/Kronos-Tokenizer-base, NeoQuasar/Kronos-small

Настроить Telegram

bash
cp coin/telegram_sender.example.php coin/telegram_sender.php
Вписать токен и chat_id.

Настроить пути в online.php при необходимости.

Запустить

text
http://localhost/botcoin/online.php
📊 Как работает
Каждые 15 минут online.php:

Запускает 6 PHP-ботов (собирают свечи с Binance)

Запускает Python-анализатор (Kronos)

Забирает Fear&Greed и новости

Проверяет, изменились ли сигналы → если да, отправляет в Telegram

Показывает дашборд

Дедупликация в БД:

1D — один сигнал в день

1H — один сигнал в час

15M — один сигнал за 15 минут

Защита от спама в Telegram:

Хэш всех ref_id (KF + старый бот + нейросеть + FG + новости)

Если хэш уже отправлялся — не шлём

📈 Пример сигнала
text
🤖 СИГНАЛЫ — 11.09.2026 17:30 МСК

📌 ИНДЕКС СТРАХА И ЖАДНОСТИ
ВОЗМОЖНО ПАДАЕТ — 38/100 (Страх на рынке)

📰 НОВОСТИ
🟢 Позитивных: 1
🔴 Негативных: 3
⚪ Нейтральных: 1

📊 KF-СИГНАЛЫ (BTCUSDT)
1D: 62.5%
1H: 58.0%
15M: 55.5%

🧠 НЕЙРОСЕТЬ (BTCUSDT)
1D: -0.45% ($61200.00, уверенность 68%)
1H: +0.20% ($61850.00, уверенность 55%)
15M: -0.10% ($61700.00, уверенность 62%)

🐢 СЛИВ ПО ТРЁМ
━━━ BTCUSDT ━━━
1D: 60.0% — вероятно слив сегодня.
1H: 55.0% — слив в этом часу или следующем.
15M: 52.0% — слив в эти 15м или через 15м.

━━━ ETHUSDT ━━━
1D: 58.0% — средний сигнал.
1H: 52.0% — слив в этом часу или следующем.
15M: 50.0% — средний сигнал.
🔮 Что можно улучшить
1. 🧠 Единый ИИ-анализатор и итоговый вердикт
Сейчас: данные приходят из 5+ источников, но итоговый вердикт не формируется. Пользователь смотрит на цифры и решает сам.

План: создать единый анализатор, который:

Собирает все сигналы в один вектор признаков

Взвешивает их по исторической точности

Выдаёт один вердикт: СИЛЬНОЕ ПАДЕНИЕ / ПАДЕНИЕ / НЕОПРЕДЕЛЁННОСТЬ / РОСТ / СИЛЬНЫЙ РОСТ

Показывает уверенность в % и рекомендацию: ВХОД В SHORT / ЖДАТЬ / ВХОД В LONG

Пример вердикта:

text
🎯 ВЕРДИКТ: ПАДЕНИЕ (уверенность 72%)
├─ Согласие источников: 4 из 5
├─ KF 15M: 62% ▼
├─ Нейросеть 15M: -0.10% ▼
├─ Fear&Greed: 38 (страх) ▲
└─ Новости: 3 негативных / 1 позитивная ▼

Рекомендация: вход в SHORT
Целевой уровень: $60,800
Стоп-лосс: $62,500
2. 🤖 Автоторговля
Автоматическое открытие позиций через Binance Futures API

Управление рисками (размер позиции, стоп-лосс, тейк-профит)

Paper trading для тестирования без реальных денег

Логирование сделок и P&L

3. 📊 Backtesting framework
Прогон стратегий на исторических данных (2+ года)

Метрики: точность, прибыль, Sharpe ratio, максимальная просадка

Оптимизация параметров (grid search)

4. 📈 Расширение
Больше монет (SOL, BNB, XRP, ADA)

Больше таймфреймов (5M, 4H, 1W)

Технические индикаторы (RSI, MACD, Bollinger Bands)

On-chain данные

5. 🎨 UI/UX
Авторизация в дашборде

Графики TradingView

Оповещения через WebSocket

PWA / мобильное приложение

6. 🚀 Инфраструктура
Docker-контейнер (✅ готово)

Cron на Linux VPS

Redis для кэша

Мониторинг через Grafana + Prometheus

🎯 Roadmap
☑ Три стратегии сигналов (KF, Слив, Kronos)
☑ Анализ новостей и Fear&Greed
☑ Telegram-уведомления
☑ Веб-дашборд
☑ Оптимизация параметров стратегий
☑ Docker-развёртывание
□ Единый ИИ-анализатор + итоговый вердикт
□ Автоторговля через Binance Futures
□ Backtesting framework
□ Расширение на другие монеты
📄 Лицензия
MIT © Maxim Ostapkevich

🌍 Языки: English | Русский