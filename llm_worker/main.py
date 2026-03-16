"""
LLM Worker — FastAPI sidecar for streaming OpenAI generation.

Listens on port 8001 (internal to the K8s pod).
PHP triggers generation via POST /generate and receives a job_id.
Progress is written to the shared SQLite database; PHP's SSE endpoint
reads it and forwards chunks to the browser.

POST /complete provides a synchronous (non-streaming) completion used by
the admin LLM-test sandbox and any PHP code that needs an immediate response.
Both endpoints log every call to the per-day LLM log SQLite files.
"""

import asyncio
import json
import os

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from database import create_job, open_db
from generator import complete_sync, stream_and_store

app = FastAPI(title="Aidelnicek LLM Worker", version="1.0.0")

_DEFAULT_MAX_TOKENS: int = int(os.environ.get("LLM_MAX_COMPLETION_TOKENS", "16000"))


class GenerateRequest(BaseModel):
    user_id: int
    week_id: int
    job_type: str = Field(default="mealplan_generate")
    mode: str = Field(default="async")
    system_prompt: str
    user_prompt: str
    model: str = Field(default="")
    temperature: float = Field(default=0.8, ge=0.0, le=2.0)
    max_completion_tokens: int = Field(default=_DEFAULT_MAX_TOKENS, ge=64, le=128000)
    input_payload: dict = Field(default_factory=dict)


class GenerateResponse(BaseModel):
    job_id: int


class CompleteRequest(BaseModel):
    system_prompt: str = ""
    user_prompt: str
    model: str = Field(default="")
    temperature: float = Field(default=0.7, ge=0.0, le=2.0)
    max_completion_tokens: int = Field(default=1024, ge=64, le=128000)
    user_id: int | None = None


class CompleteResponse(BaseModel):
    response: str
    model: str
    provider: str = "openai"
    tokens_in: int | None = None
    tokens_out: int | None = None


@app.post("/generate", response_model=GenerateResponse)
async def generate(req: GenerateRequest) -> GenerateResponse:
    model = req.model or os.environ.get("OPENAI_MODEL", "gpt-4o")

    conn = await open_db()
    try:
        job_id = await create_job(
            conn=conn,
            user_id=req.user_id,
            week_id=req.week_id,
            job_type=req.job_type,
            mode=req.mode,
            input_payload=json.dumps(req.input_payload, ensure_ascii=False),
        )
    finally:
        await conn.close()

    asyncio.create_task(
        stream_and_store(
            job_id=job_id,
            user_id=req.user_id,
            week_id=req.week_id,
            system_prompt=req.system_prompt,
            user_prompt=req.user_prompt,
            model=model,
            temperature=req.temperature,
            max_completion_tokens=req.max_completion_tokens,
        )
    )

    return GenerateResponse(job_id=job_id)


@app.post("/complete", response_model=CompleteResponse)
async def complete(req: CompleteRequest) -> CompleteResponse:
    """Synchronous (non-streaming) LLM completion with logging."""
    model = req.model or os.environ.get("OPENAI_MODEL", "gpt-4o")

    try:
        result = await complete_sync(
            system_prompt=req.system_prompt,
            user_prompt=req.user_prompt,
            model=model,
            temperature=req.temperature,
            max_completion_tokens=req.max_completion_tokens,
            user_id=req.user_id,
        )
    except Exception as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc

    return CompleteResponse(**result)


@app.get("/health")
async def health() -> dict:
    db_path = os.environ.get("DB_PATH", "/data/aidelnicek.sqlite")
    return {
        "status": "ok",
        "db_path": db_path,
        "model": os.environ.get("OPENAI_MODEL", "gpt-4o"),
    }
