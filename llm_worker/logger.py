"""
LLM call logging — SQLite per-day files or PostgreSQL partitioned llm_log in tenant schema.
"""

import os
import sqlite3
from datetime import datetime, timezone

from database import (
    LEGACY_DB_PATH,
    _ensure_pg_llm_partitions,
    _pg_connect_kwargs,
    _pg_schema_for_tenant,
    _use_postgres,
    sqlite_path_for_tenant,
    validate_tenant_id,
)

try:
    import asyncpg  # type: ignore[import-not-found]
except ImportError:  # pragma: no cover
    asyncpg = None  # type: ignore[assignment]


def _log_db_path_sqlite(tenant_id: str | None = None) -> str:
    if tenant_id is not None and tenant_id.strip() != "":
        db_path = sqlite_path_for_tenant(tenant_id)
    else:
        db_path = LEGACY_DB_PATH
    data_dir = os.path.dirname(db_path)
    today = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    return os.path.join(data_dir, f"llm_{today}.db")


def _log_sqlite_sync(
    provider: str,
    model: str,
    prompt_system: str,
    prompt_user: str,
    response_text: str | None,
    tokens_in: int | None,
    tokens_out: int | None,
    request_at: str | None,
    duration_ms: int | None,
    status: str,
    error_message: str | None,
    user_id: int | None,
    tenant_id: str | None,
) -> None:
    path = _log_db_path_sqlite(tenant_id)
    is_new = not os.path.exists(path)
    conn = sqlite3.connect(path)
    try:
        if is_new:
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS llm_log (
                    id             INTEGER PRIMARY KEY AUTOINCREMENT,
                    provider       TEXT    NOT NULL,
                    model          TEXT    NOT NULL,
                    user_id        INTEGER,
                    prompt_system  TEXT,
                    prompt_user    TEXT    NOT NULL,
                    response_text  TEXT,
                    tokens_in      INTEGER,
                    tokens_out     INTEGER,
                    request_at     TEXT    NOT NULL,
                    duration_ms    INTEGER,
                    status         TEXT    NOT NULL DEFAULT 'ok',
                    error_message  TEXT
                )
            """
            )
        conn.execute(
            """
            INSERT INTO llm_log
                (provider, model, user_id, prompt_system, prompt_user, response_text,
                 tokens_in, tokens_out, request_at, duration_ms, status, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                provider,
                model,
                user_id,
                prompt_system,
                prompt_user,
                response_text,
                tokens_in,
                tokens_out,
                request_at or datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
                duration_ms,
                status,
                error_message,
            ),
        )
        conn.commit()
    finally:
        conn.close()


async def log_llm_call_async(
    provider: str,
    model: str,
    prompt_system: str,
    prompt_user: str,
    response_text: str | None = None,
    tokens_in: int | None = None,
    tokens_out: int | None = None,
    request_at: str | None = None,
    duration_ms: int | None = None,
    status: str = "ok",
    error_message: str | None = None,
    user_id: int | None = None,
    tenant_id: str | None = None,
) -> None:
    """Awaitable LLM log write."""
    try:
        if _use_postgres():
            if asyncpg is None or not tenant_id or not tenant_id.strip():
                return
            tid = validate_tenant_id(tenant_id)
            schema = _pg_schema_for_tenant(tid)
            conn = await asyncpg.connect(**_pg_connect_kwargs())
            try:
                await conn.execute(f'CREATE SCHEMA IF NOT EXISTS "{schema}"')
                await conn.execute(f'SET search_path TO "{schema}", public')
                await _ensure_pg_llm_partitions(conn, schema)
                ra = request_at or datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S") + "+00"
                await conn.execute(
                    """
                    INSERT INTO llm_log
                        (provider, model, user_id, prompt_system, prompt_user, response_text,
                         tokens_in, tokens_out, request_at, duration_ms, status, error_message)
                    VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9::timestamptz, $10, $11, $12)
                    """,
                    provider,
                    model,
                    user_id,
                    prompt_system,
                    prompt_user,
                    response_text,
                    tokens_in,
                    tokens_out,
                    ra,
                    duration_ms,
                    status,
                    error_message,
                )
            finally:
                await conn.close()
            return

        _log_sqlite_sync(
            provider,
            model,
            prompt_system,
            prompt_user,
            response_text,
            tokens_in,
            tokens_out,
            request_at,
            duration_ms,
            status,
            error_message,
            user_id,
            tenant_id,
        )
    except Exception:  # noqa: BLE001
        pass


def log_llm_call(
    provider: str,
    model: str,
    prompt_system: str,
    prompt_user: str,
    response_text: str | None = None,
    tokens_in: int | None = None,
    tokens_out: int | None = None,
    request_at: str | None = None,
    duration_ms: int | None = None,
    status: str = "ok",
    error_message: str | None = None,
    user_id: int | None = None,
    tenant_id: str | None = None,
) -> None:
    """Sync API: SQLite only. V PostgreSQL režimu použijte await log_llm_call_async()."""
    try:
        if _use_postgres():
            return
        _log_sqlite_sync(
            provider,
            model,
            prompt_system,
            prompt_user,
            response_text,
            tokens_in,
            tokens_out,
            request_at,
            duration_ms,
            status,
            error_message,
            user_id,
            tenant_id,
        )
    except Exception:  # noqa: BLE001
        pass
