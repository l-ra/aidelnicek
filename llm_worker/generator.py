"""
Async LLM generation with OpenAI streaming.

Streams response chunks into the generation_jobs table so the PHP SSE endpoint
can forward them to the browser in near real-time.
"""

import json
import os
import re

from openai import AsyncOpenAI

from database import (
    append_chunk,
    mark_done,
    mark_error,
    mark_running,
    open_db,
    seed_meal_plans,
)


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
) -> None:
    """
    Background async task: stream from OpenAI, write chunks to DB, then store meal plans.
    """
    conn = await open_db()
    try:
        await mark_running(conn, job_id)

        client = _build_client()
        accumulated = ""
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
        )
        async for chunk in stream:
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
        await seed_meal_plans(conn, user_id, week_id, days, force)
        await mark_done(conn, job_id)

    except Exception as exc:  # noqa: BLE001
        await mark_error(conn, job_id, str(exc))
    finally:
        await conn.close()
