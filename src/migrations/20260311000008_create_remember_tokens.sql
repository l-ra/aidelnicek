CREATE TABLE IF NOT EXISTS remember_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token_hash TEXT    NOT NULL,
    expires_at DATETIME NOT NULL
);
