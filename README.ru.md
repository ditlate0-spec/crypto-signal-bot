# 🤖 Crypto Signal Bot — BTC/ETH

Торговый бот для криптовалют, объединяющий три независимых источника сигналов + нейросеть прогноза цен + рыночный сентимент. Пишет сигналы в MySQL, отправляет сводные уведомления в Telegram, отображает дашборд в браузере.

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1?logo=mysql&logoColor=white)
![Python](https://img.shields.io/badge/Python-3.11-3776AB?logo=python&logoColor=white)
![Telegram](https://img.shields.io/badge/Telegram-Bot-26A5E4?logo=telegram&logoColor=white)

## 📌 О проекте

Бот анализирует рынок по трём независимым стратегиям + нейросетевой прогноз + рыночные индикаторы (индекс страха и жадности, тональность новостей). Все данные агрегируются в одну сводку и отправляются в Telegram. Дашборд показывает состояние сигналов в реальном времени.

### 🎯 Точность стратегий

| Стратегия | Точность | Метод оптимизации |
|---|---|---|
| **KF-сигналы** | **> 66%** | Полный перебор параметров на истории |
| **Слив по тренду** | **> 66%** | Разделение на обучающую/тестовую выборку |
| **Kronos Neural Network** | **> 60%** | Предобученная модель с токенизатором |

### Источники сигналов

| Источник | Таймфреймы |
|---|---|
| KF-сигналы | 1D, 1H, 15M |
| Слив по тренду | 1D, 1H, 15M |
| Kronos Neural Network | 1D, 1H, 15M |
| Fear & Greed Index | — |
| Crypto News Sentiment | — |

## 🚀 Возможности

- ✅ Три независимые стратегии сигналов
- ✅ Нейросетевой прогноз цены (Kronos)
- ✅ Анализ тональности новостей
- ✅ Индекс страха и жадности
- ✅ Сводные Telegram-уведомления с дедупликацией
- ✅ Веб-дашборд с историей сигналов
- ✅ Кэширование
- ✅ Обработка BTC + ETH

## 🛠 Технологии

- Backend: PHP 8.x
- БД: MySQL 8.x
- Python: 3.11 + PyTorch + Kronos
- API: Binance, Alternative.me, RSS
- Уведомления: Telegram Bot API

## 📂 Структура
├── online.php
├── conn.php
├── news.php
├── schema.sql
├── LICENSE
├── README.md
├── coin/
│ ├── BTCUSDT1D.php
│ ├── BTCUSDT1H.php
│ ├── BTCUSDT15M.php
│ ├── old_bot_1D.php
│ ├── old_bot_1H.php
│ ├── old_bot_15m.php
│ └── telegram_sender.example.php
├── Kronos-master/
│ ├── kronos_analyzer.py
│ └── model/
└── screenshots/
└── dashboard.png


## 🔧 Установка

1. Клонировать репозиторий
2. Создать БД: `mysql -u root -p < schema.sql`
3. Установить Python-зависимости: `pip install torch transformers pandas numpy`
4. Скачать модель Kronos (NeoQuasar/Kronos-Tokenizer-base, NeoQuasar/Kronos-small)
5. Скопировать `coin/telegram_sender.example.php` в `coin/telegram_sender.php` и вписать токены
6. Настроить пути в `online.php`
7. Открыть `http://localhost/botcoin/online.php`
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
## 🔮 Что можно улучшить

- 🧠 Единый ИИ-анализатор с итоговым вердиктом (сильный рост / рост / неопределённость / падение / сильное падение)
- 🤖 Автоторговля через Binance Futures API
- 📊 Backtesting framework
- 📈 Расширение на другие монеты
- 🎨 UI/UX: авторизация, графики, WebSocket
- 🚀 Docker + cron на VPS
🌍 **Языки:** [English](README.md) | [Русский](README.ru.md)
## 📄 Лицензия

MIT © [Maxim Ostapkevich](https://github.com/ditlate0-spec)