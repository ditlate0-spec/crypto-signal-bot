import sys
import json
import pandas as pd
import numpy as np
from datetime import datetime, timedelta
from model.kronos import Kronos, KronosTokenizer, KronosPredictor

# ============================================
# UTF-8
# ============================================
if sys.stdout.encoding != 'utf-8':
    sys.stdout.reconfigure(encoding='utf-8')
if sys.stderr.encoding != 'utf-8':
    sys.stderr.reconfigure(encoding='utf-8')

print("Загрузка модели Kronos...", file=sys.stderr)
tokenizer = KronosTokenizer.from_pretrained("NeoQuasar/Kronos-Tokenizer-base")
model = Kronos.from_pretrained("NeoQuasar/Kronos-small")
predictor = KronosPredictor(model, tokenizer, device="cpu", max_context=512)
print("Модель загружена!", file=sys.stderr)


def analyze(candles_data):
    """Прогноз на 1 свечу вперёд по одному набору свечей."""
    if len(candles_data) < 10:
        return {'error': f'Нужно минимум 10 свечей, получено {len(candles_data)}'}

    df = pd.DataFrame(candles_data)

    if 'amount' not in df.columns:
        df['amount'] = 0

    if 'timestamps' in df.columns:
        df['timestamps'] = pd.to_datetime(df['timestamps'])
    else:
        df['timestamps'] = [datetime.now() - timedelta(hours=i) for i in range(len(df), 0, -1)]

    df = df[['timestamps', 'open', 'high', 'low', 'close', 'volume', 'amount']]

    lookback = min(len(df), 500)
    pred_len = 1

    x_df = df.iloc[-lookback:][['open', 'high', 'low', 'close', 'volume', 'amount']].copy()
    x_timestamp = df.iloc[-lookback:]['timestamps']

    last_time = df.iloc[-1]['timestamps']
    if len(df) >= 2:
        time_diff = df.iloc[-1]['timestamps'] - df.iloc[-2]['timestamps']
    else:
        time_diff = timedelta(hours=1)

    y_timestamp = pd.Series([last_time + time_diff * (i + 1) for i in range(pred_len)])

    try:
        all_predictions = []
        samples = 3

        for i in range(samples):
            pred_df = predictor.predict(
                df=x_df,
                x_timestamp=x_timestamp,
                y_timestamp=y_timestamp,
                pred_len=pred_len,
                top_p=0.9,
                sample_count=1
            )
            all_predictions.append(float(pred_df['close'].iloc[0]))

        current_price = float(df['close'].iloc[-1])
        future_price = float(np.mean(all_predictions))
        std_price = float(np.std(all_predictions))
        min_price = float(np.min(all_predictions))
        max_price = float(np.max(all_predictions))
        change_percent = ((future_price - current_price) / current_price) * 100

        cv = std_price / future_price if future_price > 0 else 1.0
        expected_change = abs(change_percent) / 100
        if cv > 0:
            ratio = expected_change / cv
            confidence = max(0.05, min(0.95, ratio / (ratio + 1)))
        else:
            confidence = 0.5

        return {
            'confidence': float(round(confidence, 2)),
            'change_percent': float(round(change_percent, 2)),
            'current_price': float(round(current_price, 2)),
            'future_price': float(round(future_price, 2)),
            'min_price': float(round(min_price, 2)),
            'max_price': float(round(max_price, 2)),
            'std_price': float(round(std_price, 2)),
            'samples': samples,
            'candles_analyzed': int(lookback)
        }
    except Exception as e:
        return {'error': f'Ошибка в predict: {str(e)}'}


if __name__ == "__main__":
    try:
        # Читаем JSON: {"15m": [...], "1h": [...], "1d": [...]}
        if len(sys.argv) >= 2:
            data = json.loads(sys.argv[1])
        else:
            with open('test_data.json', 'r', encoding='utf-8') as f:
                data = json.load(f)

        result = {}
        for tf in ['15m', '1h', '1d']:
            # Пропускаем таймфреймы, которых нет в запросе (закэшированы)
            if tf not in data:
                continue

            candles = data.get(tf, [])
            if len(candles) < 10:
                result[tf] = {'error': f'Мало свечей для {tf}: {len(candles)}'}
                continue

            result[tf] = analyze(candles)

        print(json.dumps(result, ensure_ascii=False))

    except Exception as e:
        print(json.dumps({'error': str(e)}, ensure_ascii=False))