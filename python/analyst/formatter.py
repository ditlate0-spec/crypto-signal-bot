"""
Форматирование сделок для промпта и подсчёт статистики.
"""

from typing import List, Dict, Any


def format_trades(trades: List[Dict[str, Any]]) -> str:
    """Форматирует список сделок в текстовый вид для LLM."""
    lines = []

    for t in trades:
        pnl = t.get("pnl") or 0
        pnl_str = f"{pnl:+.4f}"

        ml = t.get("ml_probability")
        ml_str = f"{ml:.4f}" if ml is not None else "n/a"

        fng = t.get("fear_greed_index")
        fng_str = str(fng) if fng is not None else "n/a"

        news = t.get("news_sentiment") or "n/a"

        reason = t.get("exit_reason") or "n/a"

        duration = t.get("duration_seconds")
        duration_str = f"{duration // 60}мин" if duration else "n/a"

        entry = t.get("entry_price", 0)
        exit_p = t.get("exit_price", 0)

        lines.append(
            f"#{t['id']} {t['symbol']} {t['side']} | "
            f"вход: {entry:.2f} → выход: {exit_p:.2f} | "
            f"PnL: {pnl_str} | "
            f"ML: {ml_str} | "
            f"F&G: {fng_str} | "
            f"новости: {news} | "
            f"выход: {reason} | "
            f"{duration_str}"
        )

    return "\n".join(lines)


def calculate_stats(trades: List[Dict[str, Any]]) -> str:
    """Считает статистику по сделкам."""
    total = len(trades)
    if total == 0:
        return "Нет сделок"

    wins = [t for t in trades if t.get("is_win")]
    losses = [t for t in trades if t.get("is_win") is False]
    wins_count = len(wins)
    losses_count = len(losses)

    total_pnl = sum(t.get("pnl") or 0 for t in trades)
    total_fees = sum(t.get("fees") or 0 for t in trades)

    # Средняя длительность
    durations = [t["duration_seconds"] for t in trades if t.get("duration_seconds")]
    avg_duration = sum(durations) / len(durations) / 60 if durations else 0

    # Winrate по сентименту новостей
    by_sentiment: Dict[str, Dict[str, int]] = {}
    for t in trades:
        s = t.get("news_sentiment") or "unknown"
        if s not in by_sentiment:
            by_sentiment[s] = {"total": 0, "wins": 0}
        by_sentiment[s]["total"] += 1
        if t.get("is_win"):
            by_sentiment[s]["wins"] += 1

    sentiment_lines = []
    for s, d in by_sentiment.items():
        wr = d["wins"] / d["total"] * 100 if d["total"] else 0
        sentiment_lines.append(f"  {s}: {d['wins']}/{d['total']} ({wr:.0f}%)")

    # Winrate по Fear & Greed (группировка по 25)
    fng_groups: Dict[int, Dict[str, int]] = {}
    for t in trades:
        fng = t.get("fear_greed_index")
        if fng is None:
            continue
        bucket = (fng // 25) * 25
        if bucket not in fng_groups:
            fng_groups[bucket] = {"total": 0, "wins": 0}
        fng_groups[bucket]["total"] += 1
        if t.get("is_win"):
            fng_groups[bucket]["wins"] += 1

    fng_lines = []
    for bucket in sorted(fng_groups.keys()):
        d = fng_groups[bucket]
        wr = d["wins"] / d["total"] * 100 if d["total"] else 0
        fng_lines.append(f"  F&G {bucket}-{bucket + 24}: {d['wins']}/{d['total']} ({wr:.0f}%)")

    # Winrate по ML-score (группировка по 0.05)
    ml_groups: Dict[str, Dict[str, int]] = {}
    for t in trades:
        ml = t.get("ml_probability")
        if ml is None:
            continue
        bucket = round(ml * 20) / 20  # 0.85, 0.90, 0.95...
        key = f"{bucket:.2f}"
        if key not in ml_groups:
            ml_groups[key] = {"total": 0, "wins": 0}
        ml_groups[key]["total"] += 1
        if t.get("is_win"):
            ml_groups[key]["wins"] += 1

    ml_lines = []
    for key in sorted(ml_groups.keys()):
        d = ml_groups[key]
        wr = d["wins"] / d["total"] * 100 if d["total"] else 0
        ml_lines.append(f"  ML {key}+: {d['wins']}/{d['total']} ({wr:.0f}%)")

    return f"""
Всего сделок: {total}
Прибыльных: {wins_count} ({wins_count / total * 100:.1f}%)
Убыточных: {losses_count} ({losses_count / total * 100:.1f}%)
Общий PnL: {total_pnl:+.4f} USDT
Общие комиссии: {total_fees:.4f} USDT
Средняя длительность: {avg_duration:.0f} мин

Winrate по сентименту новостей:
{chr(10).join(sentiment_lines) if sentiment_lines else '  нет данных'}

Winrate по Fear & Greed:
{chr(10).join(fng_lines) if fng_lines else '  нет данных'}

Winrate по ML-score:
{chr(10).join(ml_lines) if ml_lines else '  нет данных'}
"""