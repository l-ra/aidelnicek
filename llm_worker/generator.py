"""
Async LLM generation with OpenAI streaming.

Streams response chunks into the generation_jobs table so the PHP SSE endpoint
can forward them to the browser in near real-time.
"""

import json
import os
import re
import time
from datetime import datetime, timezone

from openai import AsyncOpenAI

from database import (
    append_chunk,
    create_llm_proposal,
    mark_done,
    mark_error,
    mark_running,
    open_db,
    seed_meal_plans,
)
from logger import log_llm_call


def _build_client() -> AsyncOpenAI:
    return AsyncOpenAI(
        api_key=os.environ.get("OPENAI_AUTH_BEARER", ""),
        base_url=os.environ.get("OPENAI_BASE_URL", "https://api.openai.com/v1"),
    )


def _parse_response(text: str) -> list:
    """
    Extract and parse the JSON object from the LLM response.
    Mirrors the logic in PHP MealGenerator::parseResponse().
    """
    clean = text.strip()

    # Strip markdown code fences if present
    if clean.startswith("```"):
        clean = re.sub(r"^```[a-z]*\n?", "", clean, flags=re.IGNORECASE)
        clean = re.sub(r"```\s*$", "", clean)
        clean = clean.strip()

    start = clean.find("{")
    end = clean.rfind("}")
    if start == -1 or end == -1:
        raise ValueError("Odpověď neobsahuje JSON objekt.")

    clean = clean[start : end + 1]

    try:
        data = json.loads(clean)
    except json.JSONDecodeError as exc:
        raise ValueError(f"JSON parse chyba: {exc}") from exc

    if "days" not in data or not isinstance(data["days"], list):
        raise ValueError('Chybí klíč "days" v JSON odpovědi.')

    if len(data["days"]) < 7:
        raise ValueError(
            f"Odpověď obsahuje pouze {len(data['days'])} dní místo 7."
        )

    return data["days"]


async def stream_and_store(
    job_id: int,
    user_id: int,
    week_id: int,
    system_prompt: str,
    user_prompt: str,
    model: str,
    temperature: float,
    max_completion_tokens: int,
    force: bool,
    shared_user_profiles: list[dict] | None = None,
) -> None:
    """
    Background async task: stream from OpenAI, write chunks to DB, then store meal plans.
    Logs the full call to the per-day LLM log SQLite file.
    """
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

        days = _parse_response(accumulated)
        _, proposal_meal_map = await create_llm_proposal(
            conn=conn,
            week_id=week_id,
            reference_user_id=user_id,
            generation_job_id=job_id,
            model=model,
            days=days,
        )

        profiles = shared_user_profiles or []
        if profiles:
            for profile in profiles:
                target_user_id = int(profile.get("user_id", 0))
                if target_user_id <= 0:
                    continue
                portion_factor = float(profile.get("portion_factor", 1.0))
                await seed_meal_plans(
                    conn,
                    target_user_id,
                    week_id,
                    proposal_meal_map,
                    force,
                    portion_factor=portion_factor,
                )
        else:
            await seed_meal_plans(conn, user_id, week_id, proposal_meal_map, force)
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
