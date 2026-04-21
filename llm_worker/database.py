"""
Async DB access for the LLM worker: SQLite per tenant file or PostgreSQL per tenant schema.

Worker persists generation state (jobs/chunks/output). Domain projections are PHP.
"""

import os
import re
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator

import aiosqlite

try:
    import asyncpg  # type: ignore[import-not-found]
except ImportError:  # pragma: no cover
    asyncpg = None  # type: ignore[assignment]

DATA_ROOT = os.environ.get("DATA_ROOT", "/data").rstrip("/")
LEGACY_DB_PATH = os.environ.get("DB_PATH", "/data/aidelnicek.sqlite")

_TENANT_SLUG_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,62}$")


def validate_tenant_id(tenant_id: str) -> str:
    tid = tenant_id.strip().lower()
    if not _TENANT_SLUG_RE.match(tid):
        raise ValueError("invalid tenant_id")
    return tid


def _use_postgres() -> bool:
    return bool(os.environ.get("PG_DATABASE", "").strip())


def _pg_connect_kwargs() -> dict[str, Any]:
    db = os.environ.get("PG_DATABASE", "").strip()
    if not db:
        raise RuntimeError("PG_DATABASE is not set")
    server = os.environ.get("PG_SERVER", "").strip()
    user = os.environ.get("PG_USER", "").strip()
    if not server or not user:
        raise RuntimeError("PostgreSQL mode requires PG_SERVER and PG_USER")
    if "PG_PASS" not in os.environ:
        raise RuntimeError("PostgreSQL mode requires PG_PASS to be set (may be empty string)")
    password = os.environ.get("PG_PASS", "")
    port_raw = os.environ.get("PG_PORT", "").strip()
    if not port_raw.isdigit():
        raise RuntimeError("PostgreSQL mode requires numeric PG_PORT")
    port = int(port_raw)
    if port <= 0 or port > 65535:
        raise RuntimeError("PG_PORT out of range")

    return {
        "host": server,
        "port": port,
        "database": db,
        "user": user,
        "password": password,
    }


def _pg_schema_for_tenant(tenant_id: str) -> str:
    tid = validate_tenant_id(tenant_id)
    base = f"tenant_{tid}"
    if len(base) <= 63:
        return base
    import hashlib

    return "t_" + hashlib.sha1(tid.encode()).hexdigest()[:61]


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
    return str(tenant_dir / "aidelnicek.sqlite")


def _iter_month_starts(utc_now: datetime, count: int) -> Iterator[datetime]:
    """Yield UTC midnight at the first day of `count` consecutive months starting current month."""
    y, m = utc_now.year, utc_now.month
    for _ in range(count):
        yield datetime(y, m, 1, tzinfo=timezone.utc)
        m += 1
        if m > 12:
            m = 1
            y += 1


async def _ensure_pg_llm_partitions(conn: asyncpg.Connection, schema: str) -> None:
    """Create monthly partitions for llm_log if parent exists."""
    row = await conn.fetchrow(
        """
        SELECT c.oid FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = $1 AND c.relname = 'llm_log' AND c.relkind = 'p'
        """,
        schema,
    )
    if row is None:
        return

    now = datetime.now(timezone.utc)
    for ms in _iter_month_starts(now.replace(day=1, hour=0, minute=0, second=0, microsecond=0), 4):
        y2, m2 = ms.year, ms.month + 1
        if m2 > 12:
            m2 = 1
            y2 += 1
        me = datetime(y2, m2, 1, tzinfo=timezone.utc)
        suffix = ms.strftime("%Y_%m")
        part_name = f"llm_log_p_{suffix}"
        if len(part_name) > 63:
            import hashlib

            part_name = "llm_p_" + hashlib.sha1(suffix.encode()).hexdigest()[:56]
        exists = await conn.fetchval(
            """
            SELECT 1 FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = $1 AND c.relname = $2
            """,
            schema,
            part_name,
        )
        if exists:
            continue
        from_ts = ms.strftime("%Y-%m-%d %H:%M:%S+00")
        to_ts = me.strftime("%Y-%m-%d %H:%M:%S+00")
        await conn.execute(
            f'CREATE TABLE "{schema}"."{part_name}" PARTITION OF "{schema}".llm_log '
            f"FOR VALUES FROM ('{from_ts}') TO ('{to_ts}')"
        )


async def open_db(tenant_id: str | None = None) -> Any:
    if _use_postgres():
        if asyncpg is None:
            raise RuntimeError("asyncpg is not installed")
        if tenant_id is None or tenant_id.strip() == "":
            raise RuntimeError("PostgreSQL mode requires tenant_id")
        tid = validate_tenant_id(tenant_id)
        schema = _pg_schema_for_tenant(tid)
        conn = await asyncpg.connect(**_pg_connect_kwargs())
        await conn.execute(f'CREATE SCHEMA IF NOT EXISTS "{schema}"')
        await conn.execute(f'SET search_path TO "{schema}", public')
        await _ensure_pg_schema(conn)
        await _ensure_pg_llm_partitions(conn, schema)
        conn._aidelnicek_schema = schema  # type: ignore[attr-defined]
        return conn

    if tenant_id is None or tenant_id.strip() == "":
        db_path = str(Path(LEGACY_DB_PATH).resolve())
    else:
        db_path = sqlite_path_for_tenant(tenant_id)

    conn = await aiosqlite.connect(db_path)
    conn.row_factory = aiosqlite.Row
    await conn.execute("PRAGMA journal_mode=WAL")
    await conn.execute("PRAGMA foreign_keys=ON")
    await _ensure_sqlite_schema(conn)
    await conn.commit()
    return conn


async def _ensure_sqlite_schema(conn: aiosqlite.Connection) -> None:
    await conn.execute(
        """
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
    """
    )
    await conn.execute(
        """
        CREATE TABLE IF NOT EXISTS generation_job_chunks (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id     INTEGER NOT NULL REFERENCES generation_jobs(id) ON DELETE CASCADE,
            seq_no     INTEGER NOT NULL,
            chunk_text TEXT    NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(job_id, seq_no)
        )
    """
    )
    await conn.execute(
        """
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
    """
    )
    await conn.execute(
        """
        CREATE INDEX IF NOT EXISTS idx_generation_job_chunks_job_seq
            ON generation_job_chunks(job_id, seq_no)
    """
    )
    await conn.execute(
        """
        CREATE INDEX IF NOT EXISTS idx_generation_jobs_status_projection
            ON generation_jobs(status, projection_status)
    """
    )
    await _ensure_generation_job_columns(conn)


async def _ensure_pg_schema(conn: asyncpg.Connection) -> None:
    await conn.execute(
        """
        CREATE TABLE IF NOT EXISTS generation_jobs (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL,
            week_id INTEGER NOT NULL,
            job_type TEXT NOT NULL DEFAULT 'mealplan_generate',
            mode TEXT NOT NULL DEFAULT 'async',
            status TEXT NOT NULL DEFAULT 'pending',
            progress_text TEXT NOT NULL DEFAULT '',
            chunk_count INTEGER NOT NULL DEFAULT 0,
            input_payload TEXT NOT NULL DEFAULT '{}',
            projection_status TEXT NOT NULL DEFAULT 'pending',
            projection_error_message TEXT,
            projection_started_at TIMESTAMPTZ,
            projection_finished_at TIMESTAMPTZ,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            started_at TIMESTAMPTZ,
            finished_at TIMESTAMPTZ,
            error_message TEXT
        )
    """
    )
    await conn.execute(
        """
        CREATE TABLE IF NOT EXISTS generation_job_chunks (
            id SERIAL PRIMARY KEY,
            job_id INTEGER NOT NULL REFERENCES generation_jobs(id) ON DELETE CASCADE,
            seq_no INTEGER NOT NULL,
            chunk_text TEXT NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            UNIQUE(job_id, seq_no)
        )
    """
    )
    await conn.execute(
        """
        CREATE INDEX IF NOT EXISTS idx_generation_job_chunks_job_seq
            ON generation_job_chunks(job_id, seq_no)
    """
    )
    await conn.execute(
        """
        CREATE TABLE IF NOT EXISTS generation_job_outputs (
            job_id INTEGER PRIMARY KEY REFERENCES generation_jobs(id) ON DELETE CASCADE,
            provider TEXT NOT NULL DEFAULT 'openai',
            model TEXT NOT NULL,
            raw_text TEXT NOT NULL,
            parsed_json TEXT,
            tokens_in INTEGER,
            tokens_out INTEGER,
            duration_ms INTEGER,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    """
    )
    await conn.execute(
        """
        CREATE INDEX IF NOT EXISTS idx_generation_jobs_status_projection
            ON generation_jobs(status, projection_status)
    """
    )

    exists = await conn.fetchval(
        """
        SELECT COUNT(*)::int FROM information_schema.tables
        WHERE table_schema = current_schema() AND table_name = 'llm_log'
        """
    )
    if exists == 0:
        await conn.execute(
            """
            CREATE TABLE llm_log (
                id BIGSERIAL,
                provider TEXT NOT NULL,
                model TEXT NOT NULL,
                user_id BIGINT,
                prompt_system TEXT,
                prompt_user TEXT NOT NULL,
                response_text TEXT,
                tokens_in INTEGER,
                tokens_out INTEGER,
                request_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                duration_ms INTEGER,
                status TEXT NOT NULL DEFAULT 'ok',
                error_message TEXT,
                PRIMARY KEY (id, request_at)
            ) PARTITION BY RANGE (request_at)
        """
        )
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


def _is_asyncpg_conn(conn: Any) -> bool:
    return asyncpg is not None and isinstance(conn, asyncpg.Connection)


async def create_job(
    conn: Any,
    user_id: int,
    week_id: int,
    job_type: str,
    mode: str,
    input_payload: str,
) -> int:
    if _is_asyncpg_conn(conn):
        row = await conn.fetchrow(
            """
            INSERT INTO generation_jobs
                (user_id, week_id, job_type, mode, status, input_payload, created_at, projection_status)
            VALUES ($1, $2, $3, $4, 'pending', $5, NOW(),
                CASE WHEN $6 = 'mealplan_generate' THEN 'pending' ELSE 'done' END)
            RETURNING id
            """,
            user_id,
            week_id,
            job_type,
            mode,
            input_payload,
            job_type,
        )
        jid = int(row["id"]) if row else 0
        return jid

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


async def mark_running(conn: Any, job_id: int) -> None:
    if _is_asyncpg_conn(conn):
        await conn.execute(
            "UPDATE generation_jobs SET status='running', started_at=NOW() WHERE id=$1",
            job_id,
        )
        return

    await conn.execute(
        "UPDATE generation_jobs SET status='running', started_at=? WHERE id=?",
        (_now(), job_id),
    )
    await conn.commit()


async def append_chunk(conn: Any, job_id: int, text: str) -> None:
    if _is_asyncpg_conn(conn):
        await conn.execute(
            "UPDATE generation_jobs SET progress_text = progress_text || $1, chunk_count = chunk_count + 1 WHERE id=$2",
            text,
            job_id,
        )
        row = await conn.fetchrow(
            "SELECT chunk_count FROM generation_jobs WHERE id=$1",
            job_id,
        )
        seq_no = int(row["chunk_count"]) if row is not None else 0
        await conn.execute(
            """
            INSERT INTO generation_job_chunks (job_id, seq_no, chunk_text, created_at)
            VALUES ($1, $2, $3, NOW())
            """,
            job_id,
            seq_no,
            text,
        )
        return

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
    conn: Any,
    job_id: int,
    provider: str,
    model: str,
    raw_text: str,
    tokens_in: int | None,
    tokens_out: int | None,
    duration_ms: int,
    parsed_json: str | None = None,
) -> None:
    if _is_asyncpg_conn(conn):
        await conn.execute(
            """
            INSERT INTO generation_job_outputs
                (job_id, provider, model, raw_text, parsed_json, tokens_in, tokens_out, duration_ms, created_at)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, NOW())
            ON CONFLICT (job_id) DO UPDATE SET
                provider    = EXCLUDED.provider,
                model       = EXCLUDED.model,
                raw_text    = EXCLUDED.raw_text,
                parsed_json = EXCLUDED.parsed_json,
                tokens_in   = EXCLUDED.tokens_in,
                tokens_out  = EXCLUDED.tokens_out,
                duration_ms = EXCLUDED.duration_ms,
                created_at  = EXCLUDED.created_at
            """,
            job_id,
            provider,
            model,
            raw_text,
            parsed_json,
            tokens_in,
            tokens_out,
            duration_ms,
        )
        return

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


async def mark_done(conn: Any, job_id: int) -> None:
    if _is_asyncpg_conn(conn):
        await conn.execute(
            "UPDATE generation_jobs SET status='done', finished_at=NOW() WHERE id=$1",
            job_id,
        )
        return

    await conn.execute(
        "UPDATE generation_jobs SET status='done', finished_at=? WHERE id=?",
        (_now(), job_id),
    )
    await conn.commit()


async def mark_error(conn: Any, job_id: int, message: str) -> None:
    if _is_asyncpg_conn(conn):
        await conn.execute(
            "UPDATE generation_jobs SET status='error', finished_at=NOW(), error_message=$1 WHERE id=$2",
            message[:2000],
            job_id,
        )
        return

    await conn.execute(
        "UPDATE generation_jobs SET status='error', finished_at=?, error_message=? WHERE id=?",
        (_now(), message[:2000], job_id),
    )
    await conn.commit()
