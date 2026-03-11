CREATE TABLE IF NOT EXISTS meal_plans (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id),
    week_id      INTEGER NOT NULL REFERENCES weeks(id),
    day_of_week  INTEGER NOT NULL,
    meal_type    TEXT    NOT NULL,
    alternative  INTEGER NOT NULL,
    meal_name    TEXT    NOT NULL,
    description  TEXT,
    ingredients  TEXT,
    is_chosen    INTEGER DEFAULT 0,
    is_eaten     INTEGER DEFAULT 0,
    UNIQUE(user_id, week_id, day_of_week, meal_type, alternative)
);
