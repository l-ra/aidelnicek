"""
SQLite async operations for the LLM worker.

Worker persists only technical generation state (jobs/chunks/final output).
Business projections are handled by PHP projector logic.
"""

import os
import re
from datetime import datetime, timezone
from pathlib import Path

import aiosqlite

DATA_ROOT = os.environ.get("DATA_ROOT", "/data").rstrip("/")
# Legacy single-file default (used only when tenant_id is absent)
LEGACY_DB_PATH = os.environ.get("DB_PATH", "/data/aidelnicek.sqlite")

_TENANT_SLUG_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,62}$")


def validate_tenant_id(tenant_id: str) -> str:
    tid = tenant_id.strip().lower()
    if not _TENANT_SLUG_RE.match(tid):
        raise ValueError("invalid tenant_id")
    return tid


def sqlite_path_for_tenant(tenant_id: str) -> str:
    """Return absolute path to aidelnicek.sqlite for a tenant data directory under DATA_ROOT."""
    tid = validate_tenant_id(tenant_id)
    root = Path(DATA_ROOT).resolve()
    tenant_dir = (root / tid).resolve()
    try:
        tenant_dir.relative_to(root)
    except ValueError as exc:
        raise ValueError("invalid tenant path") from exc
    if not tenant_dir.is_dir():
        raise ValueError("tenant data directory does not exist")
    # SQLite creates the file on first connect; PHP may not have opened the DB yet.
    return str(tenant_dir / "aidelnicek.sqlite")


async def open_db(tenant_id: str | None = None) -> aiosqlite.Connection:
    if tenant_id is None or tenant_id.strip() == "":
        db_path = str(Path(LEGACY_DB_PATH).resolve())
    else:
        db_path = sqlite_path_for_tenant(tenant_id)

    conn = await aiosqlite.connect(db_path)
    conn.row_factory = aiosqlite.Row
    await conn.execute("PRAGMA journal_mode=WAL")
    await conn.execute("PRAGMA foreign_keys=ON")
    await conn.execute("""
        CREATE TABLE IF NOT EXISTS generation_jobs (
            id                       INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id                  INTEGER NOT NULL,
            week_id                  INTEGER NOT NULL,
            job_type                 TEXT    NOT NULL DEFAULT 'mealplan_generate',
            mode                     TEXT    NOT NULL DEFAULT 'async',
            status                   TEXT    NOT NULL DEFAULT 'pending',
            progress_text            TEXT    NOT NULL DEFAULT '',
            chunk_count              INTEGER NOT NULL DEFAULT 0,
            input_payload            TEXT    NOT NULL DEFAULT '{}',
            projection_status        TEXT    NOT NULL DEFAULT 'pending',
            projection_error_message TEXT,
            projection_started_at    DATETIME,
            projection_finished_at   DATETIME,
            created_at               DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at               DATETIME,
            finished_at              DATETIME,
            error_message            TEXT
        )
    """)
    await conn.execute("""
        CREATE TABLE IF NOT EXISTS generation_job_chunks (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id     INTEGER NOT NULL REFERENCES generation_jobs(id) ON DELETE CASCADE,
            seq_no     INTEGER NOT NULL,
            chunk_text TEXT    NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(job_id, seq_no)
        )
    """)
    await conn.execute("""
        CREATE TABLE IF NOT EXISTS generation_job_outputs (
            job_id      INTEGER PRIMARY KEY REFERENCES generation_jobs(id) ON DELETE CASCADE,
            provider    TEXT    NOT NULL DEFAULT 'openai',
            model       TEXT    NOT NULL,
            raw_text    TEXT    NOT NULL,
            parsed_json TEXT,
            tokens_in   INTEGER,
            tokens_out  INTEGER,
            duration_ms INTEGER,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    """)
    await conn.execute("""
        CREATE INDEX IF NOT EXISTS idx_generation_job_chunks_job_seq
            ON generation_job_chunks(job_id, seq_no)
    """)
    await conn.execute("""
        CREATE INDEX IF NOT EXISTS idx_generation_jobs_status_projection
            ON generation_jobs(status, projection_status)
    """)
    await _ensure_generation_job_columns(conn)
    await conn.commit()
    return conn


async def _ensure_generation_job_columns(conn: aiosqlite.Connection) -> None:
    required_columns = {
        "job_type": "ALTER TABLE generation_jobs ADD COLUMN job_type TEXT NOT NULL DEFAULT 'mealplan_generate'",
        "mode": "ALTER TABLE generation_jobs ADD COLUMN mode TEXT NOT NULL DEFAULT 'async'",
        "input_payload": "ALTER TABLE generation_jobs ADD COLUMN input_payload TEXT NOT NULL DEFAULT '{}'",
        "projection_status": "ALTER TABLE generation_jobs ADD COLUMN projection_status TEXT NOT NULL DEFAULT 'pending'",
        "projection_error_message": "ALTER TABLE generation_jobs ADD COLUMN projection_error_message TEXT",
        "projection_started_at": "ALTER TABLE generation_jobs ADD COLUMN projection_started_at DATETIME",
        "projection_finished_at": "ALTER TABLE generation_jobs ADD COLUMN projection_finished_at DATETIME",
    }
    async with conn.execute("PRAGMA table_info(generation_jobs)") as cursor:
        rows = await cursor.fetchall()
    existing = {row["name"] for row in rows}
    for col_name, ddl in required_columns.items():
        if col_name in existing:
            continue
        await conn.execute(ddl)


def _now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


async def create_job(
    conn: aiosqlite.Connection,
    user_id: int,
    week_id: int,
    job_type: str,
    mode: str,
    input_payload: str,
) -> int:
    cursor = await conn.execute(
        """
        INSERT INTO generation_jobs
            (user_id, week_id, job_type, mode, status, input_payload, created_at, projection_status)
        VALUES (?, ?, ?, ?, 'pending', ?, ?, CASE WHEN ? = 'mealplan_generate' THEN 'pending' ELSE 'done' END)
        """,
        (user_id, week_id, job_type, mode, input_payload, _now(), job_type),
    )
    await conn.commit()
    return int(cursor.lastrowid)


async def mark_running(conn: aiosqlite.Connection, job_id: int) -> None:
    await conn.execute(
        "UPDATE generation_jobs SET status='running', started_at=? WHERE id=?",
        (_now(), job_id),
    )
    await conn.commit()


async def append_chunk(conn: aiosqlite.Connection, job_id: int, text: str) -> None:
    await conn.execute(
        "UPDATE generation_jobs SET progress_text = progress_text || ?, chunk_count = chunk_count + 1 WHERE id=?",
        (text, job_id),
    )
    async with conn.execute(
        "SELECT chunk_count FROM generation_jobs WHERE id=?",
        (job_id,),
    ) as cursor:
        row = await cursor.fetchone()
    seq_no = int(row["chunk_count"]) if row is not None else 0
    await conn.execute(
        """
        INSERT INTO generation_job_chunks (job_id, seq_no, chunk_text, created_at)
        VALUES (?, ?, ?, ?)
        """,
        (job_id, seq_no, text, _now()),
    )
    await conn.commit()


async def upsert_output(
    conn: aiosqlite.Connection,
    job_id: int,
    provider: str,
    model: str,
    raw_text: str,
    tokens_in: int | None,
    tokens_out: int | None,
    duration_ms: int,
    parsed_json: str | None = None,
) -> None:
    await conn.execute(
        """
        INSERT INTO generation_job_outputs
            (job_id, provider, model, raw_text, parsed_json, tokens_in, tokens_out, duration_ms, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(job_id) DO UPDATE SET
            provider    = excluded.provider,
            model       = excluded.model,
            raw_text    = excluded.raw_text,
            parsed_json = excluded.parsed_json,
            tokens_in   = excluded.tokens_in,
            tokens_out  = excluded.tokens_out,
            duration_ms = excluded.duration_ms,
            created_at  = excluded.created_at
        """,
        (job_id, provider, model, raw_text, parsed_json, tokens_in, tokens_out, duration_ms, _now()),
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
