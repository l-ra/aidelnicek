CREATE TABLE IF NOT EXISTS weeks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    week_number  INTEGER NOT NULL,
    year         INTEGER NOT NULL,
    generated_at DATETIME,
    UNIQUE(week_number, year)
);
