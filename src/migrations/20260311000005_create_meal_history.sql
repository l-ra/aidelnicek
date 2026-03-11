CREATE TABLE IF NOT EXISTS meal_history (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id),
    meal_name     TEXT    NOT NULL,
    times_offered INTEGER DEFAULT 0,
    times_chosen  INTEGER DEFAULT 0,
    times_eaten   INTEGER DEFAULT 0,
    last_offered  DATETIME
);
