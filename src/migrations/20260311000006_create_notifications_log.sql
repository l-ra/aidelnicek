CREATE TABLE IF NOT EXISTS notifications_log (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id),
    sent_at DATETIME,
    type    TEXT,
    status  TEXT
);
