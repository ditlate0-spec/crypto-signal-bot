"""
train_onetp.py — One-Class обучение на TP-сигналах.
Идея: модель учится "как выглядит TP". На новых данных — "похоже на TP" или нет.
Train: TP до 2026-03-01
Test:  все сигналы с 2026-03-01 (TP + SL)

Пробуем 3 модели:
  1. OneClassSVM (nu=0.5)
  2. IsolationForest
  3. kNN-расстояние
Сравниваем и сохраняем лучшую.
"""

import os
import sys
import joblib
import numpy as np
import pandas as pd
from sklearn.preprocessing import StandardScaler
from sklearn.svm import OneClassSVM
from sklearn.ensemble import IsolationForest
from sklearn.neighbors import NearestNeighbors

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
TRAIN_CSV = os.path.join(BASE_DIR, 'train_tp.csv')
TEST_CSV  = os.path.join(BASE_DIR, 'test_all.csv')

MODEL_OUT    = os.path.join(BASE_DIR, 'tp_filter_model.pkl')
FEATURES_OUT = os.path.join(BASE_DIR, 'tp_filter_features.pkl')
META_OUT     = os.path.join(BASE_DIR, 'tp_filter_meta.pkl')
SCALER_OUT   = os.path.join(BASE_DIR, 'tp_filter_scaler.pkl')


# ============================================
# ПОСТРОЕНИЕ ПРИЗНАКОВ (1-в-1 из predict_one.py)
# ============================================
def candle_feats(o, h, l, c, idx):
    rng = h - l
    if rng == 0:
        rng = np.nan

    def safe(num, den):
        try:
            return num / den
        except (ZeroDivisionError, TypeError):
            return np.nan

    return {
        f'c{idx}_ret':        safe(c - o, o),
        f'c{idx}_range':      safe(rng, o),
        f'c{idx}_close_pos':  safe(c - l, rng),
        f'c{idx}_dir':        float(np.sign(c - o)),
        f'c{idx}_upper_wick': safe(h - max(o, c), rng),
        f'c{idx}_lower_wick': safe(min(o, c) - l, rng),
        f'c{idx}_body_ratio': safe(abs(c - o), rng),
    }


def build_row(row):
    c1_o, c1_h, c1_l, c1_c = float(row['candle_1_open']), float(row['candle_1_high']), float(row['candle_1_low']), float(row['candle_1_close'])
    c2_o, c2_h, c2_l, c2_c = float(row['candle_2_open']), float(row['candle_2_high']), float(row['candle_2_low']), float(row['candle_2_close'])
    c3_o, c3_h, c3_l, c3_c = float(row['candle_3_open']), float(row['candle_3_high']), float(row['candle_3_low']), float(row['candle_3_close'])

    v1 = float(row['candle_1_vol']) if row['candle_1_vol'] else np.nan
    v2 = float(row['candle_2_vol']) if row['candle_2_vol'] else np.nan
    v3 = float(row['candle_3_vol']) if row['candle_3_vol'] else np.nan

    entry_price = float(row['entry_price'])
    signal_time = pd.to_datetime(row['signal_time'])

    out = {}
    out.update(candle_feats(c1_o, c1_h, c1_l, c1_c, 1))
    out.update(candle_feats(c2_o, c2_h, c2_l, c2_c, 2))
    out.update(candle_feats(c3_o, c3_h, c3_l, c3_c, 3))

    out['vol_c2_rel'] = (v2 / v1) if v1 else np.nan
    out['vol_c3_rel'] = (v3 / v1) if v1 else np.nan

    out['body_sum']        = out['c1_ret'] + out['c2_ret'] + out['c3_ret']
    out['dir_consistency'] = abs(out['c1_dir'] + out['c2_dir'] + out['c3_dir'])
    out['range_mean']      = np.nanmean([out['c1_range'], out['c2_range'], out['c3_range']])

    if out['c1_range'] and not np.isnan(out['c1_range']) and out['c1_range'] != 0:
        out['range_expansion'] = out['c3_range'] / out['c1_range']
    else:
        out['range_expansion'] = np.nan

    out['c3_engulfs_c2'] = int((c3_o <= c2_o and c3_c >= c2_c) or (c3_o >= c2_o and c3_c <= c2_c))
    out['c3_engulfs_c1'] = int((c3_o <= c1_o and c3_c >= c1_c) or (c3_o >= c1_o and c3_c <= c1_c))

    out['entry_vs_c3_close'] = (entry_price - c3_c) / c3_c if c3_c else np.nan
    rng3 = c3_h - c3_l
    out['entry_in_c3_range'] = (entry_price - c3_l) / rng3 if rng3 else np.nan

    for col in ['kf', 'kf_btc_15m_oth', 'kf_eth_15m_oth', 'kf_eth_15m_old',
                'kf_btc_1h_oth', 'kf_btc_1h_old', 'kf_btc_1d_oth', 'kf_btc_1d_old']:
        out[col] = np.nan

    out['hour']      = int(signal_time.hour)
    out['dayofweek'] = int(signal_time.weekday())
    out['minute']    = int(signal_time.minute)

    out['bot_type']  = 0
    out['timeframe'] = 0
    out['symbol']    = 0

    return out


def build_X(df):
    rows = [build_row(r) for _, r in df.iterrows()]
    return pd.DataFrame(rows)


# ============================================
# MAIN
# ============================================
def main():
    if not os.path.exists(TRAIN_CSV):
        print(f"ERROR: {TRAIN_CSV} не найден", file=sys.stderr)
        sys.exit(2)
    if not os.path.exists(TEST_CSV):
        print(f"ERROR: {TEST_CSV} не найден", file=sys.stderr)
        sys.exit(2)

    print(f"📂 Train: {TRAIN_CSV}")
    train_df = pd.read_csv(TRAIN_CSV)
    print(f"   {len(train_df)} записей (все TP)")

    print(f"📂 Test: {TEST_CSV}")
    test_df = pd.read_csv(TEST_CSV)
    n_test_tp = (test_df['result'] == 'TP').sum()
    n_test_sl = (test_df['result'] == 'SL').sum()
    print(f"   {len(test_df)} записей")
    print(f"   TP: {n_test_tp}, SL: {n_test_sl}")

    X_train = build_X(train_df)
    X_test  = build_X(test_df)

    # Выравниваем колонки
    feature_names = list(X_train.columns)
    for col in feature_names:
        if col not in X_test.columns:
            X_test[col] = np.nan
    X_test = X_test[feature_names]

    # Заполняем NaN
    X_train = X_train.fillna(0)
    X_test  = X_test.fillna(0)

    # Масштабирование
    scaler = StandardScaler()
    X_train_s = scaler.fit_transform(X_train)
    X_test_s  = scaler.transform(X_test)

    y_test = (test_df['result'] == 'TP').astype(int).values

    results = []  # (name, model, scaler_needed, train_in%, test_tp_in%, test_sl_in%, threshold_used)


    # ============================================
    # ВАРИАНТ 1: OneClassSVM (nu=0.5)
    # ============================================
    print("\n" + "=" * 60)
    print("🧠 1/3. OneClassSVM (nu=0.5)")
    print("=" * 60)

    model_svm = OneClassSVM(kernel='rbf', gamma='scale', nu=0.5)
    model_svm.fit(X_train_s)

    pred_tr = model_svm.predict(X_train_s)
    tr_in = (pred_tr == 1).sum()
    tr_pct = 100 * tr_in / len(pred_tr)

    pred_te = model_svm.predict(X_test_s)
    te_tp_in = (pred_te[y_test == 1] == 1).sum()
    te_sl_in = (pred_te[y_test == 0] == 1).sum() if (y_test == 0).sum() > 0 else 0
    te_tp_pct = 100 * te_tp_in / max(1, y_test.sum())
    te_sl_pct = 100 * te_sl_in / max(1, (y_test == 0).sum())

    print(f"   Train inliers:    {tr_in}/{len(pred_tr)} ({tr_pct:.1f}%)")
    print(f"   Test TP inliers:  {te_tp_in}/{y_test.sum()} ({te_tp_pct:.1f}%)")
    print(f"   Test SL inliers:  {te_sl_in}/{(y_test == 0).sum()} ({te_sl_pct:.1f}%)")

    results.append({
        'name': 'OneClassSVM nu=0.5',
        'model': model_svm,
        'needs_scaler': True,
        'train_pct': tr_pct,
        'test_tp_pct': te_tp_pct,
        'test_sl_pct': te_sl_pct,
        'threshold': 0.0,
    })


    # ============================================
    # ВАРИАНТ 2: IsolationForest
    # ============================================
    print("\n" + "=" * 60)
    print("🌲 2/3. IsolationForest (contamination=0.05)")
    print("=" * 60)

    model_iso = IsolationForest(contamination=0.05, random_state=42)
    model_iso.fit(X_train_s)

    pred_tr = model_iso.predict(X_train_s)
    tr_in = (pred_tr == 1).sum()
    tr_pct = 100 * tr_in / len(pred_tr)

    pred_te = model_iso.predict(X_test_s)
    te_tp_in = (pred_te[y_test == 1] == 1).sum()
    te_sl_in = (pred_te[y_test == 0] == 1).sum() if (y_test == 0).sum() > 0 else 0
    te_tp_pct = 100 * te_tp_in / max(1, y_test.sum())
    te_sl_pct = 100 * te_sl_in / max(1, (y_test == 0).sum())

    print(f"   Train inliers:    {tr_in}/{len(pred_tr)} ({tr_pct:.1f}%)")
    print(f"   Test TP inliers:  {te_tp_in}/{y_test.sum()} ({te_tp_pct:.1f}%)")
    print(f"   Test SL inliers:  {te_sl_in}/{(y_test == 0).sum()} ({te_sl_pct:.1f}%)")

    results.append({
        'name': 'IsolationForest',
        'model': model_iso,
        'needs_scaler': True,
        'train_pct': tr_pct,
        'test_tp_pct': te_tp_pct,
        'test_sl_pct': te_sl_pct,
        'threshold': 0.0,
    })


    # ============================================
    # ВАРИАНТ 3: kNN-расстояние
    # ============================================
    print("\n" + "=" * 60)
    print("📏 3/3. kNN-расстояние (5 соседей, порог = 90-й процентиль train)")
    print("=" * 60)

    nn = NearestNeighbors(n_neighbors=5)
    nn.fit(X_train_s)

    train_dists, _ = nn.kneighbors(X_train_s)
    train_avg = train_dists.mean(axis=1)
    threshold = float(np.percentile(train_avg, 90))

    test_dists, _ = nn.kneighbors(X_test_s)
    test_avg = test_dists.mean(axis=1)

    # inlier если расстояние <= threshold
    pred_tr_knn = (train_avg <= threshold).astype(int)
    pred_te_knn = (test_avg <= threshold).astype(int)

    tr_in = pred_tr_knn.sum()
    tr_pct = 100 * tr_in / len(pred_tr_knn)

    te_tp_in = pred_te_knn[y_test == 1].sum()
    te_sl_in = pred_te_knn[y_test == 0].sum() if (y_test == 0).sum() > 0 else 0
    te_tp_pct = 100 * te_tp_in / max(1, y_test.sum())
    te_sl_pct = 100 * te_sl_in / max(1, (y_test == 0).sum())

    print(f"   Порог:            {threshold:.4f}")
    print(f"   Train inliers:    {tr_in}/{len(pred_tr_knn)} ({tr_pct:.1f}%)")
    print(f"   Test TP inliers:  {te_tp_in}/{y_test.sum()} ({te_tp_pct:.1f}%)")
    print(f"   Test SL inliers:  {te_sl_in}/{(y_test == 0).sum()} ({te_sl_pct:.1f}%)")

    # Обёртка для kNN — чтобы использовать как модель
    class KnnModel:
        def __init__(self, nn, threshold):
            self.nn = nn
            self.threshold = threshold

        def predict(self, X):
            dists, _ = self.nn.kneighbors(X)
            avg = dists.mean(axis=1)
            return np.where(avg <= self.threshold, 1, -1)

        def decision_function(self, X):
            dists, _ = self.nn.kneighbors(X)
            avg = dists.mean(axis=1)
            # Чем меньше расстояние, тем выше score
            return self.threshold - avg

    knn_model = KnnModel(nn, threshold)

    results.append({
        'name': 'kNN-distance',
        'model': knn_model,
        'needs_scaler': True,
        'train_pct': tr_pct,
        'test_tp_pct': te_tp_pct,
        'test_sl_pct': te_sl_pct,
        'threshold': threshold,
    })


    # ============================================
    # ВЫБОР ЛУЧШЕГО
    # ============================================
    print("\n" + "=" * 60)
    print("🏆 СРАВНЕНИЕ МОДЕЛЕЙ")
    print("=" * 60)
    print(f"{'Модель':<22} {'Train%':>8} {'TestTP%':>9} {'TestSL%':>9}")
    print("-" * 60)
    for r in results:
        print(f"{r['name']:<22} {r['train_pct']:>7.1f}% {r['test_tp_pct']:>8.1f}% {r['test_sl_pct']:>8.1f}%")

    # Правило: лучший = максимальный test_tp_pct при условии train_pct >= 80
    candidates = [r for r in results if r['train_pct'] >= 80]
    if not candidates:
        print("\n⚠️ Нет модели с train_pct >= 80%. Берём с максимальным test_tp_pct.")
        candidates = results

    best = max(candidates, key=lambda r: r['test_tp_pct'])
    print(f"\n🎯 Лучшая модель: {best['name']}")
    print(f"   Train inliers:   {best['train_pct']:.1f}%")
    print(f"   Test TP inliers: {best['test_tp_pct']:.1f}%")
    print(f"   Test SL inliers: {best['test_sl_pct']:.1f}%")


    # ============================================
    # СОХРАНЕНИЕ
    # ============================================
    meta = {
        'model_type':   best['name'],
        'threshold':    best['threshold'],
        'trained_at':   pd.Timestamp.utcnow().isoformat(),
        'n_samples':    int(len(X_train)),
        'train_pct':    best['train_pct'],
        'test_tp_pct':  best['test_tp_pct'],
        'test_sl_pct':  best['test_sl_pct'],
        'features':     feature_names,
        'needs_scaler': best['needs_scaler'],
        'note': 'Trained only on TP. Higher score = more like TP.',
    }

    joblib.dump(best['model'],   MODEL_OUT)
    joblib.dump(feature_names,   FEATURES_OUT)
    joblib.dump(scaler,          SCALER_OUT)
    joblib.dump(meta,            META_OUT)

    print(f"\n✅ Сохранено:")
    print(f"   {MODEL_OUT}")
    print(f"   {FEATURES_OUT}")
    print(f"   {SCALER_OUT}")
    print(f"   {META_OUT}")


if __name__ == '__main__':
    main()