"""
SQLite async operations for the LLM worker.

Shares the same aidelnicek.sqlite file as the PHP app via a mounted PVC volume.
WAL mode is enabled so PHP can read while this worker writes.
"""

import json
import os
from datetime import datetime, timezone
from typing import Any

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
    await conn.execute("""
        CREATE TABLE IF NOT EXISTS llm_meal_proposals (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            week_id            INTEGER NOT NULL,
            generation_job_id  INTEGER,
            reference_user_id  INTEGER NOT NULL,
            llm_model          TEXT,
            created_at         DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    """)
    await conn.execute("""
        CREATE TABLE IF NOT EXISTS llm_proposal_meals (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            proposal_id  INTEGER NOT NULL,
            day_of_week  INTEGER NOT NULL,
            meal_type    TEXT NOT NULL,
            alternative  INTEGER NOT NULL,
            meal_name    TEXT NOT NULL,
            description  TEXT,
            ingredients  TEXT NOT NULL,
            UNIQUE(proposal_id, day_of_week, meal_type, alternative)
        )
    """)
    await conn.execute("""
        CREATE TABLE IF NOT EXISTS meal_recipes (
            id                   INTEGER PRIMARY KEY AUTOINCREMENT,
            proposal_meal_id     INTEGER NOT NULL UNIQUE,
            generated_by_user_id INTEGER,
            model                TEXT,
            recipe_text          TEXT NOT NULL,
            created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    """)
    if await _table_exists(conn, "meal_plans"):
        if not await _column_exists(conn, "meal_plans", "proposal_meal_id"):
            await conn.execute(
                "ALTER TABLE meal_plans ADD COLUMN proposal_meal_id INTEGER"
            )
        if not await _column_exists(conn, "meal_plans", "portion_factor"):
            await conn.execute(
                "ALTER TABLE meal_plans ADD COLUMN portion_factor REAL NOT NULL DEFAULT 1.0"
            )
        await conn.execute(
            "CREATE INDEX IF NOT EXISTS idx_meal_plans_proposal_meal_id ON meal_plans(proposal_meal_id)"
        )
    await conn.commit()
    return conn


async def _column_exists(
    conn: aiosqlite.Connection, table_name: str, column_name: str
) -> bool:
    async with conn.execute(f"PRAGMA table_info({table_name})") as cursor:
        rows = await cursor.fetchall()
    for row in rows:
        if row["name"] == column_name:
            return True
    return False


async def _table_exists(conn: aiosqlite.Connection, table_name: str) -> bool:
    async with conn.execute(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
        (table_name,),
    ) as cursor:
        row = await cursor.fetchone()
    return row is not None


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


async def create_llm_proposal(
    conn: aiosqlite.Connection,
    week_id: int,
    reference_user_id: int,
    generation_job_id: int,
    model: str,
    days: list[dict[str, Any]],
) -> tuple[int, dict[tuple[int, str, int], dict[str, Any]]]:
    cursor = await conn.execute(
        """
        INSERT INTO llm_meal_proposals (week_id, generation_job_id, reference_user_id, llm_model)
        VALUES (?, ?, ?, ?)
        """,
        (week_id, generation_job_id, reference_user_id, model),
    )
    proposal_id = int(cursor.lastrowid)

    proposal_meal_map: dict[tuple[int, str, int], dict[str, Any]] = {}
    insert_stmt = """
        INSERT INTO llm_proposal_meals
            (proposal_id, day_of_week, meal_type, alternative, meal_name, description, ingredients)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    """

    for day_data in days:
        day_num = int(day_data.get("day", 0))
        if day_num < 1 or day_num > 7:
            continue

        meals = day_data.get("meals", {})
        if not isinstance(meals, dict):
            continue

        for meal_type in MEAL_TYPES:
            slot = meals.get(meal_type, {})
            if not isinstance(slot, dict):
                continue

            for key, alt_num in [("alt1", 1), ("alt2", 2)]:
                alt = slot.get(key)
                if not isinstance(alt, dict) or not alt.get("name"):
                    continue

                raw_ingredients = alt.get("ingredients", [])
                if not isinstance(raw_ingredients, list):
                    raw_ingredients = []

                meal_name = str(alt["name"])
                meal_desc = str(alt.get("description", ""))
                ingredients_json = json.dumps(raw_ingredients, ensure_ascii=False)

                cursor = await conn.execute(
                    insert_stmt,
                    (
                        proposal_id,
                        day_num,
                        meal_type,
                        alt_num,
                        meal_name,
                        meal_desc,
                        ingredients_json,
                    ),
                )
                proposal_meal_id = int(cursor.lastrowid)
                proposal_meal_map[(day_num, meal_type, alt_num)] = {
                    "proposal_meal_id": proposal_meal_id,
                    "meal_name": meal_name,
                    "description": meal_desc,
                    "ingredients": raw_ingredients,
                }

    await conn.commit()
    return proposal_id, proposal_meal_map


async def seed_meal_plans(
    conn: aiosqlite.Connection,
    user_id: int,
    week_id: int,
    proposal_meals: dict[tuple[int, str, int], dict[str, Any]],
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
            (user_id, week_id, day_of_week, meal_type, alternative, meal_name, description, ingredients, proposal_meal_id, portion_factor)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    """

    for key in sorted(proposal_meals.keys()):
        day_num, meal_type, alt_num = key
        meal_entry = proposal_meals[key]

        raw_ingredients = meal_entry.get("ingredients", [])
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
                str(meal_entry.get("meal_name", "")),
                str(meal_entry.get("description", "")),
                ingredients_json,
                int(meal_entry.get("proposal_meal_id", 0)),
                float(portion_factor),
            ),
        )
        await _record_meal_offer(conn, user_id, str(meal_entry.get("meal_name", "")))

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
