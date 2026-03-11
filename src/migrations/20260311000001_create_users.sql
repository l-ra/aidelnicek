CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT    NOT NULL,
    email         TEXT    UNIQUE NOT NULL,
    password_hash TEXT    NOT NULL,
    gender        TEXT,
    age           INTEGER,
    body_type     TEXT,
    dietary_notes TEXT,
    is_admin      INTEGER DEFAULT 0,
    push_subscription TEXT,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);
