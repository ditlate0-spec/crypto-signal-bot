"""
predict_one.py — принимает JSON-файл с данными одного сигнала,
возвращает JSON с вероятностью TP от обученной модели.

Использование:
    python predict_one.py input.json output.json
"""

import sys
import os
import json
import joblib
import numpy as np
import pandas as pd

# ============================================
# НАСТРОЙКИ
# ============================================
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MODEL_PATH    = os.path.join(BASE_DIR, "tp_filter_model.pkl")
FEATURES_PATH = os.path.join(BASE_DIR, "tp_filter_features.pkl")
META_PATH     = os.path.join(BASE_DIR, "tp_filter_meta.pkl")

# Дефолтный порог — используется, если meta.pkl недоступен
DEFAULT_THRESHOLD = 0.84
MAYBE_MARGIN = 0.05

# ============================================
# ЗАГРУЗКА МОДЕЛИ, ПРИЗНАКОВ И ПОРОГА
# ============================================
if not os.path.exists(MODEL_PATH):
    print(f"ERROR: не найден файл модели: {MODEL_PATH}", file=sys.stderr)
    sys.exit(2)

if not os.path.exists(FEATURES_PATH):
    print(f"ERROR: не найден файл признаков: {FEATURES_PATH}", file=sys.stderr)
    sys.exit(2)

_model = joblib.load(MODEL_PATH)
_feature_names = joblib.load(FEATURES_PATH)

# Порог читаем из meta.pkl — чтобы синхронизироваться с train_final.py
if os.path.exists(META_PATH):
    try:
        _meta = joblib.load(META_PATH)
        THRESHOLD = float(_meta.get('threshold', DEFAULT_THRESHOLD))
        print(f"INFO: threshold loaded from meta: {THRESHOLD}", file=sys.stderr)
    except Exception as e:
        print(f"WARN: cannot load meta ({e}), using default {DEFAULT_THRESHOLD}", file=sys.stderr)
        THRESHOLD = DEFAULT_THRESHOLD
else:
    print(f"WARN: meta not found, using default threshold {DEFAULT_THRESHOLD}", file=sys.stderr)
    THRESHOLD = DEFAULT_THRESHOLD


# ============================================
# ПОСТРОЕНИЕ ПРИЗНАКОВ
# ============================================
def candle_feats(c, idx):
    o, h, l, cl = float(c['open']), float(c['high']), float(c['low']), float(c['close'])
    rng = (h - l)
    if rng == 0:
        rng = np.nan

    def safe(num, den):
        try:
            return num / den
        except (ZeroDivisionError, TypeError):
            return np.nan

    return {
        f'c{idx}_ret':        safe(cl - o, o),
        f'c{idx}_range':      safe(rng, o),
        f'c{idx}_close_pos':  safe(cl - l, rng),
        f'c{idx}_dir':        float(np.sign(cl - o)),
        f'c{idx}_upper_wick': safe(h - max(o, cl), rng),
        f'c{idx}_lower_wick': safe(min(o, cl) - l, rng),
        f'c{idx}_body_ratio': safe(abs(cl - o), rng),
    }


def build_row(data):
    c1, c2, c3 = data['candles']
    entry_price = float(data['entry_price'])
    signal_time = pd.to_datetime(data['signal_time'])
    kf_data = data.get('kf_data', {}) or {}

    row = {}
    row.update(candle_feats(c1, 1))
    row.update(candle_feats(c2, 2))
    row.update(candle_feats(c3, 3))

    v1 = float(c1['vol']) if c1['vol'] else np.nan
    row['vol_c2_rel'] = (float(c2['vol']) / v1) if v1 else np.nan
    row['vol_c3_rel'] = (float(c3['vol']) / v1) if v1 else np.nan

    row['body_sum']        = row['c1_ret'] + row['c2_ret'] + row['c3_ret']
    row['dir_consistency'] = abs(row['c1_dir'] + row['c2_dir'] + row['c3_dir'])
    row['range_mean']      = np.nanmean([row['c1_range'], row['c2_range'], row['c3_range']])

    if row['c1_range'] and not np.isnan(row['c1_range']) and row['c1_range'] != 0:
        row['range_expansion'] = row['c3_range'] / row['c1_range']
    else:
        row['range_expansion'] = np.nan

    c1_o, c1_c = float(c1['open']), float(c1['close'])
    c2_o, c2_c = float(c2['open']), float(c2['close'])
    c3_o, c3_c = float(c3['open']), float(c3['close'])

    row['c3_engulfs_c2'] = int(
        (c3_o <= c2_o and c3_c >= c2_c) or (c3_o >= c2_o and c3_c <= c2_c)
    )
    row['c3_engulfs_c1'] = int(
        (c3_o <= c1_o and c3_c >= c1_c) or (c3_o >= c1_o and c3_c <= c1_c)
    )

    c3_high, c3_low = float(c3['high']), float(c3['low'])
    row['entry_vs_c3_close'] = (entry_price - c3_c) / c3_c if c3_c else np.nan
    rng3 = c3_high - c3_low
    row['entry_in_c3_range'] = (entry_price - c3_low) / rng3 if rng3 else np.nan

    for col in ['kf', 'kf_btc_15m_oth', 'kf_eth_15m_oth', 'kf_eth_15m_old',
                'kf_btc_1h_oth', 'kf_btc_1h_old', 'kf_btc_1d_oth', 'kf_btc_1d_old']:
        val = kf_data.get(col, np.nan)
        try:
            row[col] = float(val) if val is not None else np.nan
        except (TypeError, ValueError):
            row[col] = np.nan

    row['hour']      = int(signal_time.hour)
    row['dayofweek'] = int(signal_time.weekday())
    row['minute']    = int(signal_time.minute)

    row['bot_type']  = 0
    row['timeframe'] = 0
    row['symbol']    = 0

    return row


# ============================================
# ПРЕДСКАЗАНИЕ
# ============================================
def predict(data):
    row = build_row(data)
    X = pd.DataFrame([row])

    for col in _feature_names:
        if col not in X.columns:
            X[col] = np.nan

    X = X[_feature_names].fillna(0)

    proba = float(_model.predict_proba(X)[0, 1])

    if proba >= THRESHOLD:
        decision = "TAKE"
    elif proba >= THRESHOLD - MAYBE_MARGIN:
        decision = "MAYBE"
    else:
        decision = "SKIP"

    return {
        "probability_tp": round(proba, 4),
        "threshold": THRESHOLD,
        "decision": decision,
    }


# ============================================
# MAIN
# ============================================
def main():
    if len(sys.argv) != 3:
        print("Usage: python predict_one.py input.json output.json", file=sys.stderr)
        sys.exit(1)

    input_path  = sys.argv[1]
    output_path = sys.argv[2]

    try:
        with open(input_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
    except Exception as e:
        print(f"ERROR: не удалось прочитать {input_path}: {e}", file=sys.stderr)
        sys.exit(3)

    try:
        result = predict(data)
    except Exception as e:
        print(f"ERROR: prediction failed: {e}", file=sys.stderr)
        result = {
            "probability_tp": None,
            "threshold": THRESHOLD,
            "decision": "ERROR",
            "error": str(e),
        }

    try:
        with open(output_path, 'w', encoding='utf-8') as f:
            json.dump(result, f, ensure_ascii=False)
    except Exception as e:
        print(f"ERROR: не удалось записать {output_path}: {e}", file=sys.stderr)
        sys.exit(4)

    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()