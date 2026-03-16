"""
Async LLM generation with OpenAI streaming.

Streams chunks into generation_job_chunks and stores final output in
generation_job_outputs. Domain projections are handled in PHP.
"""

import os
import time
from datetime import datetime, timezone

from openai import AsyncOpenAI

from database import (
    append_chunk,
    mark_done,
    mark_error,
    mark_running,
    open_db,
    upsert_output,
)
from logger import log_llm_call


def _build_client() -> AsyncOpenAI:
    return AsyncOpenAI(
        api_key=os.environ.get("OPENAI_AUTH_BEARER", ""),
        base_url=os.environ.get("OPENAI_BASE_URL", "https://api.openai.com/v1"),
    )


async def stream_and_store(
    job_id: int,
    user_id: int,
    week_id: int,
    system_prompt: str,
    user_prompt: str,
    model: str,
    temperature: float,
    max_completion_tokens: int,
) -> None:
    """
    Background async task: stream from OpenAI, write chunks + final output to DB.
    Logs the full call to the per-day LLM log SQLite file.
    """
    _ = week_id
    conn = await open_db()

    request_at = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    start_ts = time.monotonic()
    log_status = "ok"
    log_error: str | None = None
    accumulated = ""
    tokens_in: int | None = None
    tokens_out: int | None = None

    try:
        await mark_running(conn, job_id)

        client = _build_client()
        finish_reason: str | None = None

        stream = await client.chat.completions.create(
            model=model,
            messages=[
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ],
            temperature=temperature,
            max_completion_tokens=max_completion_tokens,
            stream=True,
            stream_options={"include_usage": True},
        )
        async for chunk in stream:
            # Capture usage from the final (usage-only) chunk
            if hasattr(chunk, "usage") and chunk.usage is not None:
                tokens_in = getattr(chunk.usage, "prompt_tokens", None)
                tokens_out = getattr(chunk.usage, "completion_tokens", None)

            if not chunk.choices:
                continue
            choice = chunk.choices[0]
            if choice.delta.content:
                accumulated += choice.delta.content
                await append_chunk(conn, job_id, choice.delta.content)
            if choice.finish_reason is not None:
                finish_reason = choice.finish_reason

        if finish_reason == "length":
            raise RuntimeError(
                f"Generování přerušeno limitem tokenů (finish_reason='length', "
                f"max_completion_tokens={max_completion_tokens}). "
                f"Zvyšte hodnotu env proměnné LLM_MAX_COMPLETION_TOKENS."
            )

        await upsert_output(
            conn=conn,
            job_id=job_id,
            provider="openai",
            model=model,
            raw_text=accumulated,
            tokens_in=tokens_in,
            tokens_out=tokens_out,
            duration_ms=int((time.monotonic() - start_ts) * 1000),
        )
        await mark_done(conn, job_id)

    except Exception as exc:  # noqa: BLE001
        log_status = "error"
        log_error = str(exc)
        await mark_error(conn, job_id, str(exc))
    finally:
        await conn.close()
        duration_ms = int((time.monotonic() - start_ts) * 1000)
        log_llm_call(
            provider="openai",
            model=model,
            prompt_system=system_prompt,
            prompt_user=user_prompt,
            response_text=accumulated if log_status == "ok" else None,
            tokens_in=tokens_in,
            tokens_out=tokens_out,
            request_at=request_at,
            duration_ms=duration_ms,
            status=log_status,
            error_message=log_error,
            user_id=user_id,
        )


async def complete_sync(
    system_prompt: str,
    user_prompt: str,
    model: str,
    temperature: float,
    max_completion_tokens: int,
    user_id: int | None = None,
) -> dict:
    """
    Non-streaming LLM completion for the /complete endpoint.
    Returns response text, model info and token counts.
    Logs the call to the per-day LLM log SQLite file.
    """
    client = _build_client()

    request_at = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    start_ts = time.monotonic()
    log_status = "ok"
    log_error: str | None = None
    response_text: str | None = None
    tokens_in: int | None = None
    tokens_out: int | None = None

    try:
        resp = await client.chat.completions.create(
            model=model,
            messages=[
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ],
            temperature=temperature,
            max_completion_tokens=max_completion_tokens,
            stream=False,
        )
        response_text = resp.choices[0].message.content or ""
        tokens_in = getattr(resp.usage, "prompt_tokens", None)
        tokens_out = getattr(resp.usage, "completion_tokens", None)

    except Exception as exc:  # noqa: BLE001
        log_status = "error"
        log_error = str(exc)
        raise

    finally:
        duration_ms = int((time.monotonic() - start_ts) * 1000)
        log_llm_call(
            provider="openai",
            model=model,
            prompt_system=system_prompt,
            prompt_user=user_prompt,
            response_text=response_text,
            tokens_in=tokens_in,
            tokens_out=tokens_out,
            request_at=request_at,
            duration_ms=duration_ms,
            status=log_status,
            error_message=log_error,
            user_id=user_id,
        )

    return {
        "response": response_text,
        "model": model,
        "provider": "openai",
        "tokens_in": tokens_in,
        "tokens_out": tokens_out,
    }
