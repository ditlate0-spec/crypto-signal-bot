# 🤖 Crypto Signal Bot — BTC/ETH

A cryptocurrency trading bot that combines three independent signal sources + a neural network price forecast + market sentiment. It writes signals to MySQL, sends summary notifications to Telegram, and displays a dashboard in the browser.

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1?logo=mysql&logoColor=white)
![Python](https://img.shields.io/badge/Python-3.11-3776AB?logo=python&logoColor=white)
![Telegram](https://img.shields.io/badge/Telegram-Bot-26A5E4?logo=telegram&logoColor=white)

## 📌 About

The bot analyzes the market using **three independent strategies** + a **neural network forecast** + **market indicators** (Fear & Greed Index, news sentiment). All data is aggregated into a single summary and sent to Telegram. The dashboard shows the current state of signals in real time.

### 🎯 Strategy Accuracy

All strategies are **trained and optimized on historical data** and show accuracy above random:

| Strategy | Accuracy | Optimization method |
|---|---|---|
| **KF Signals** | **> 66%** | Full grid search on historical data |
| **Trend Dump** | **> 66%** | Train/test split (70/30) |
| **Kronos Neural Network** | **> 60%** | Pretrained model + tokenizer |

*Metric: accuracy = share of correct signals (price drop after signal) / total number of signals.*

### Signal Sources

| Source | Timeframes |
|---|---|
| KF Signals | 1D, 1H, 15M |
| Trend Dump | 1D, 1H, 15M |
| Kronos Neural Network | 1D, 1H, 15M |
| Fear & Greed Index | — |
| Crypto News Sentiment | — |

## 🚀 Features

- ✅ Three independent signal strategies (**accuracy > 66%**)
- ✅ Neural network price forecast (Kronos, **accuracy > 60%**)
- ✅ News sentiment analysis (dictionary-based NLP)
- ✅ Fear & Greed Index
- ✅ Summary Telegram notifications with deduplication
- ✅ Web dashboard with signal history
- ✅ Caching (neural network, RSS, Fear & Greed)
- ✅ BTC + ETH support

## 🛠 Tech Stack

- **Backend:** PHP 8.x (no frameworks)
- **Database:** MySQL 8.x
- **Python:** 3.11 + PyTorch + Kronos
- **Data APIs:** Binance Public API, Alternative.me, RSS feeds
- **Notifications:** Telegram Bot API

## 🏗 Architecture
Binance API ──┐
RSS feeds ──┼──► PHP bots (candle analysis) ──► MySQL
Fear&Greed ──┘ │
▼
Kronos (Python) ──► neural_predictions ──► online.php ──► Telegram
│
▼
Web Dashboard

text

### Database Schema

| Table | Fields | Purpose |
|---|---|---|
| `oth_1d` | id, Nazvanie, kf, data | KF signals 1D |
| `oth_1h` | id, Nazvanie, kf, data | KF signals 1H |
| `oth_15m` | id, Nazvanie, kf, data | KF signals 15M |
| `old_bot_signals_1d` | id, symbol, kf, text, created_at | Trend Dump 1D |
| `old_bot_signals_1h` | id, symbol, kf, text, created_at | Trend Dump 1H |
| `old_bot_signals_15m` | id, symbol, kf, text, created_at | Trend Dump 15M |
| `neural_predictions` | id, symbol, timeframe, signal, confidence, change_percent, current_price, future_price, candles_analyzed, json_data, created_at | Kronos predictions |
| `telegram_sent` | id, hash, sent_at | Telegram deduplication |

**SQL schema:** see `schema.sql`

## 📂 Project Structure
.
├── online.php # Main dashboard + neural network + TG
├── schema.sql # Database schema
├── LICENSE
├── README.md
├── coin/
│ ├── BTCUSDT1D.php # KF signal 1D
│ ├── BTCUSDT1H.php # KF signal 1H
│ ├── BTCUSDT15M.php # KF signal 15M
│ ├── old_bot_1D.php # Trend Dump 1D
│ ├── old_bot_1H.php # Trend Dump 1H
│ ├── old_bot_15m.php # Trend Dump 15M
│ ├── conn.php # Binance API wrapper
│ ├── news.php # News sentiment analysis
│ ├── telegram_sender.example.php # Example (template)
│ └── telegram_sender.php # Real (in .gitignore)
├── Kronos-master/
│ ├── kronos_analyzer.py # Neural network analysis
│ └── model/ # Kronos model
└── screenshots/
└── dashboard.png # Dashboard screenshot

text

## 🔧 Installation

### Requirements
- PHP 8.x
- MySQL 8.x
- Python 3.11
- PyTorch + transformers
- XAMPP / LAMP / WAMP

### Steps

1. **Clone the repository**
```bash
git clone https://github.com/ditlate0-spec/crypto-signal-bot.git
cd crypto-signal-bot
Create the database

bash
mysql -u root -p < schema.sql
Install Python dependencies

bash
pip install torch transformers pandas numpy
Download the Kronos model

From HuggingFace: NeoQuasar/Kronos-Tokenizer-base, NeoQuasar/Kronos-small

Configure Telegram

bash
cp coin/telegram_sender.example.php coin/telegram_sender.php
Insert your bot token and chat ID.

Set paths in online.php

Path to Python

Database credentials

Run

text
http://localhost/botcoin/online.php
📊 How It Works
Every 15 minutes online.php:

Runs 6 PHP bots (fetch candles from Binance)

Runs the Python analyzer (Kronos)

Fetches Fear & Greed and news

Checks if signals changed → if yes, sends to Telegram

Renders the dashboard

Database deduplication:

1D — one signal per day

1H — one signal per hour

15M — one signal per 15 minutes

Telegram anti-spam:

Hash of all ref_id (KF + old bot + neural + FG + news)

If the hash was already sent — don't send

📈 Signal Example
text
🤖 SIGNALS — 11.09.2026 17:30 MSK

📌 FEAR & GREED INDEX
POSSIBLY FALLING — 38/100 (Fear on the market)

📰 NEWS
🟢 Positive: 1
🔴 Negative: 3
⚪ Neutral: 1

📊 KF SIGNALS (BTCUSDT)
1D: 62.5%
1H: 58.0%
15M: 55.5%

🧠 NEURAL NETWORK (BTCUSDT)
1D: -0.45% ($61200.00, confidence 68%)
1H: +0.20% ($61850.00, confidence 55%)
15M: -0.10% ($61700.00, confidence 62%)

🐢 TREND DUMP
━━━ BTCUSDT ━━━
1D: 60.0% — likely dump today.
1H: 55.0% — dump this hour or next.
15M: 52.0% — dump in these 15m or next.

━━━ ETHUSDT ━━━
1D: 58.0% — medium signal.
1H: 52.0% — dump this hour or next.
15M: 50.0% — medium signal.
🔮 Future Improvements
1. 🧠 Unified AI Analyzer + Final Verdict
Now: data comes from 5+ sources, but there's no final verdict. The user looks at the numbers and decides.

Plan: create a unified analyzer that:

Aggregates all signals into one feature vector

Weighs them by historical accuracy

Outputs a single verdict: STRONG DUMP / DUMP / UNCERTAIN / PUMP / STRONG PUMP

Shows confidence % and a recommendation: ENTER SHORT / WAIT / ENTER LONG

Example verdict:

text
🎯 VERDICT: DUMP (confidence 72%)
├─ Source agreement: 4 of 5
├─ KF 15M: 62% ▼
├─ Neural 15M: -0.10% ▼
├─ Fear&Greed: 38 (fear) ▲
└─ News: 3 negative / 1 positive ▼

Recommendation: enter SHORT
Target: $60,800
Stop-loss: $62,500
2. 🤖 Auto-Trading
Auto-open positions via Binance Futures API

Risk management (position size, stop-loss, take-profit)

Paper trading for testing without real money

Trade logging and P&L stats

3. 📊 Backtesting Framework
Run strategies on historical data (2+ years)

Metrics: accuracy, profit, Sharpe ratio, max drawdown

Parameter optimization (grid search)

4. 📈 Expansion
More coins (SOL, BNB, XRP, ADA)

More timeframes (5M, 4H, 1W)

Technical indicators (RSI, MACD, Bollinger Bands)

On-chain data

5. 🎨 UI/UX
Dashboard authentication

TradingView charts

WebSocket notifications

PWA / mobile app

6. 🚀 Infrastructure
Docker container

Cron on Linux VPS

Redis for caching

Monitoring via Grafana + Prometheus

🎯 Roadmap
☑ Three signal strategies (KF, Trend Dump, Kronos)
☑ News sentiment + Fear&Greed
☑ Telegram notifications
☑ Web dashboard
☑ Strategy parameter optimization
□ Unified AI analyzer + final verdict
□ Auto-trading via Binance Futures
□ Backtesting framework
□ Docker + cron on VPS
□ Expansion to more coins
🌍 **Languages:** [English](README.md) | [Русский](README.ru.md)
📄 License
MIT © Maxim Ostapkevich