"""
SQLite async operations for the LLM worker.

Shares the same aidelnicek.sqlite file as the PHP app via a mounted PVC volume.
WAL mode is enabled so PHP can read while this worker writes.
"""

import json
import os
from datetime import datetime, timezone

import aiosqlite

DB_PATH = os.environ.get("DB_PATH", "/data/aidelnicek.sqlite")

MEAL_TYPES = ["breakfast", "snack_am", "lunch", "snack_pm", "dinner"]


async def open_db() -> aiosqlite.Connection:
    conn = await aiosqlite.connect(DB_PATH)
    conn.row_factory = aiosqlite.Row
    await conn.execute("PRAGMA journal_mode=WAL")
    await conn.execute("PRAGMA foreign_keys=ON")
    # Ensure the table exists even if PHP hasn't initialised it yet
    await conn.execute("""
        CREATE TABLE IF NOT EXISTS generation_jobs (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id       INTEGER NOT NULL,
            week_id       INTEGER NOT NULL,
            status        TEXT    NOT NULL DEFAULT 'pending',
            progress_text TEXT    NOT NULL DEFAULT '',
            chunk_count   INTEGER NOT NULL DEFAULT 0,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at    DATETIME,
            finished_at   DATETIME,
            error_message TEXT
        )
    """)
    await conn.commit()
    return conn


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


async def create_job(conn: aiosqlite.Connection, user_id: int, week_id: int) -> int:
    cursor = await conn.execute(
        "INSERT INTO generation_jobs (user_id, week_id, status, created_at) VALUES (?, ?, 'pending', ?)",
        (user_id, week_id, _now()),
    )
    await conn.commit()
    return cursor.lastrowid


async def mark_running(conn: aiosqlite.Connection, job_id: int) -> None:
    await conn.execute(
        "UPDATE generation_jobs SET status='running', started_at=? WHERE id=?",
        (_now(), job_id),
    )
    await conn.commit()


async def append_chunk(conn: aiosqlite.Connection, job_id: int, text: str) -> None:
    """Append streaming chunk — uses SQLite || concatenation to avoid read-modify-write race."""
    await conn.execute(
        "UPDATE generation_jobs SET progress_text = progress_text || ?, chunk_count = chunk_count + 1 WHERE id=?",
        (text, job_id),
    )
    await conn.commit()


async def mark_done(conn: aiosqlite.Connection, job_id: int) -> None:
    await conn.execute(
        "UPDATE generation_jobs SET status='done', finished_at=? WHERE id=?",
        (_now(), job_id),
    )
    await conn.commit()


async def mark_error(conn: aiosqlite.Connection, job_id: int, message: str) -> None:
    await conn.execute(
        "UPDATE generation_jobs SET status='error', finished_at=?, error_message=? WHERE id=?",
        (_now(), message[:2000], job_id),
    )
    await conn.commit()


async def seed_meal_plans(
    conn: aiosqlite.Connection,
    user_id: int,
    week_id: int,
    days: list,
    force: bool,
    portion_factor: float = 1.0,
) -> None:
    if force:
        await conn.execute(
            "DELETE FROM meal_plans WHERE user_id=? AND week_id=?",
            (user_id, week_id),
        )

    stmt = """
        INSERT OR IGNORE INTO meal_plans
            (user_id, week_id, day_of_week, meal_type, alternative, meal_name, description, ingredients)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    """

    for day_data in days:
        day_num = int(day_data.get("day", 0))
        if day_num < 1 or day_num > 7:
            continue

        meals = day_data.get("meals", {})

        for meal_type in MEAL_TYPES:
            slot = meals.get(meal_type, {})
            for key, alt_num in [("alt1", 1), ("alt2", 2)]:
                alt = slot.get(key)
                if not alt or not alt.get("name"):
                    continue

                raw_ingredients = alt.get("ingredients", [])
                if not isinstance(raw_ingredients, list):
                    raw_ingredients = []
                ingredients = _scale_ingredients(raw_ingredients, portion_factor)
                ingredients_json = json.dumps(ingredients, ensure_ascii=False)
                await conn.execute(
                    stmt,
                    (
                        user_id,
                        week_id,
                        day_num,
                        meal_type,
                        alt_num,
                        str(alt["name"]),
                        str(alt.get("description", "")),
                        ingredients_json,
                    ),
                )
                await _record_meal_offer(conn, user_id, str(alt["name"]))

    await conn.commit()


def _scale_ingredients(raw_ingredients: list, portion_factor: float) -> list:
    if abs(portion_factor - 1.0) < 0.0001:
        return raw_ingredients

    scaled: list = []
    for ingredient in raw_ingredients:
        if not isinstance(ingredient, dict):
            scaled.append(ingredient)
            continue

        entry = dict(ingredient)
        quantity = entry.get("quantity")

        if isinstance(quantity, (int, float)) and not isinstance(quantity, bool):
            scaled_qty = max(0.1, round(float(quantity) * portion_factor, 1))
            entry["quantity"] = int(scaled_qty) if float(scaled_qty).is_integer() else scaled_qty

        scaled.append(entry)

    return scaled


async def _record_meal_offer(
    conn: aiosqlite.Connection, user_id: int, meal_name: str
) -> None:
    await conn.execute(
        """
        INSERT INTO meal_history (user_id, meal_name, times_offered, last_offered)
        VALUES (?, ?, 1, CURRENT_TIMESTAMP)
        ON CONFLICT(user_id, meal_name)
        DO UPDATE SET
            times_offered = times_offered + 1,
            last_offered  = CURRENT_TIMESTAMP
        """,
        (user_id, meal_name),
    )
