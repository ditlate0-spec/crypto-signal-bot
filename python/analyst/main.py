"""
AI-агент для анализа торговых сделок.
Запуск: uvicorn analyst.main:app --host 0.0.0.0 --port 8001
"""

import os
from typing import List, Optional
from datetime import datetime

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from langchain_openai import ChatOpenAI
from langchain.prompts import ChatPromptTemplate

from .formatter import format_trades, calculate_stats
from .prompts import SYSTEM_PROMPT, ANALYSIS_PROMPT

app = FastAPI(title="Trade Analyst Agent")


# ============================================
# МОДЕЛЬ ДАННЫХ
# ============================================

class TradeData(BaseModel):
    id: int
    symbol: str
    side: str
    entry_price: float
    exit_price: Optional[float] = None
    quantity: float
    leverage: int
    ml_probability: Optional[float] = None
    news_positive: int = 0
    news_negative: int = 0
    news_neutral: int = 0
    news_sentiment: Optional[str] = None
    fear_greed_index: Optional[int] = None
    fear_greed_label: Optional[str] = None
    pnl: Optional[float] = None
    pnl_pct: Optional[float] = None
    fees: Optional[float] = None
    exit_reason: Optional[str] = None
    duration_seconds: Optional[int] = None
    is_win: Optional[bool] = None
    entry_time: str
    exit_time: Optional[str] = None


class AnalyzeRequest(BaseModel):
    trades: List[TradeData]
    period: str = "24 часа"


# ============================================
# ЭНДПОИНТЫ
# ============================================

@app.get("/health")
async def health():
    return {"status": "ok"}


@app.post("/analyze")
async def analyze(req: AnalyzeRequest):
    if not req.trades:
        raise HTTPException(status_code=400, detail="Нет сделок для анализа")

    # ✅ Читаем ПРАВИЛЬНУЮ переменную
    api_key = os.getenv("OLLAMA_API_KEY")
    if not api_key:
        # ✅ Сообщение об ошибке, а не ключ
        raise HTTPException(
            status_code=500,
            detail="OLLAMA_API_KEY не задан в переменных окружения"
        )

    model = os.getenv("OLLAMA_MODEL", "gpt-oss:120b")

    llm = ChatOpenAI(
        model=model,
        base_url="https://ollama.com/v1",
        api_key=api_key,
        temperature=0,
    )

    trades_dicts = [t.dict() for t in req.trades]
    trades_text = format_trades(trades_dicts)
    stats = calculate_stats(trades_dicts)

    prompt = ChatPromptTemplate.from_messages([
        ("system", SYSTEM_PROMPT),
        ("human", ANALYSIS_PROMPT),
    ])

    chain = prompt | llm

    try:
        result = chain.invoke({
            "period": req.period,
            "count": len(req.trades),
            "trades_text": trades_text,
            "stats": stats,
        })
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"LLM error: {e}")

    return {
        "status": "ok",
        "analysis": result.content,
        "trades_count": len(req.trades),
        "generated_at": datetime.utcnow().isoformat(),
    }