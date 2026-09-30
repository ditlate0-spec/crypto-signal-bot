import csv
import joblib
import numpy as np
import pandas as pd
import sys
sys.path.insert(0, '/var/www')
from predict_one import build_row

m = joblib.load('/var/www/tp_filter_model.pkl')
s = joblib.load('/var/www/tp_filter_scaler.pkl')
feats = joblib.load('/var/www/tp_filter_features.pkl')

with open('/var/www/ml_signals_log.csv') as f:
    reader = list(csv.DictReader(f))

tp_rows = [r for r in reader if r['result'] == 'TP'][:5]
sl_rows = [r for r in reader if r['result'] == 'SL'][:5]


def test_row(r):
    payload = {
        'signal_time': r['signal_time'],
        'entry_price': float(r['entry_price']),
        'candles': [
            {'open': float(r['c1_open']), 'high': float(r['c1_high']), 'low': float(r['c1_low']), 'close': float(r['c1_close']), 'vol': float(r['c1_vol'])},
            {'open': float(r['c2_open']), 'high': float(r['c2_high']), 'low': float(r['c2_low']), 'close': float(r['c2_close']), 'vol': float(r['c2_vol'])},
            {'open': float(r['c3_open']), 'high': float(r['c3_high']), 'low': float(r['c3_low']), 'close': float(r['c3_close']), 'vol': float(r['c3_vol'])},
        ],
        'kf_data': {},
    }
    row = build_row(payload)
    X = pd.DataFrame([row])
    for col in feats:
        if col not in X.columns:
            X[col] = np.nan
    X = X[feats].fillna(0)
    Xs = s.transform(X)
    p = m.predict_proba(Xs)[0]
    return p[0], p[1]


print('=== TP ===')
for r in tp_rows:
    p0, p1 = test_row(r)
    print(f"{r['signal_time']}: class0={p0:.3f} class1={p1:.3f}  <- {r['result']}")

print('=== SL ===')
for r in sl_rows:
    p0, p1 = test_row(r)
    print(f"{r['signal_time']}: class0={p0:.3f} class1={p1:.3f}  <- {r['result']}")