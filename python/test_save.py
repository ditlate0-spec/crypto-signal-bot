import requests
import json

url = "http://analyst:8001/analyze"

payload = {
    "trades": [
        {
            "id": 1,
            "symbol": "BTCUSDT",
            "side": "SHORT",
            "entry_price": 86500,
            "exit_price": 86300,
            "quantity": 0.001,
            "leverage": 50,
            "ml_probability": 0.87,
            "news_sentiment": "positive",
            "fear_greed_index": 62,
            "pnl": 0.2,
            "fees": 0.07,
            "exit_reason": "TP",
            "duration_seconds": 1200,
            "is_win": True,
            "entry_time": "2026-09-27T10:00:00Z",
            "exit_time": "2026-09-27T10:20:00Z"
        }
    ],
    "period": "тест"
}

response = requests.post(url, json=payload)
data = response.json()

# Сохраняем с явной UTF-8
with open("/app/response_clean.txt", "w", encoding="utf-8") as f:
    f.write(data["analysis"])

print("✅ Сохранено в /app/response_clean.txt")
print("Длина:", len(data["analysis"]), "символов")