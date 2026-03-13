"""
LLM call logging — writes per-day SQLite files mirroring PHP LlmLogger.

File path: same data directory as aidelnicek.sqlite, named llm_YYYY-MM-DD.db
Table structure matches PHP LlmLogger schema so PHP /admin/llm-logs can display
records from both PHP and Python callers.
"""

import os
import sqlite3
from datetime import datetime, timezone


def _log_db_path() -> str:
    """Return today's log file path in the same directory as the main SQLite DB."""
    db_path = os.environ.get("DB_PATH", "/data/aidelnicek.sqlite")
    data_dir = os.path.dirname(db_path)
    today = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    return os.path.join(data_dir, f"llm_{today}.db")


def _ensure_schema(conn: sqlite3.Connection) -> None:
    conn.execute("""
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
    """)
    conn.commit()


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
) -> None:
    """Write an LLM call record to today's log SQLite file. Silently ignores errors."""
    try:
        path = _log_db_path()
        is_new = not os.path.exists(path)
        conn = sqlite3.connect(path)
        if is_new:
            _ensure_schema(conn)
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
        conn.close()
    except Exception:  # noqa: BLE001
        pass  # Logging failure must never crash the application
