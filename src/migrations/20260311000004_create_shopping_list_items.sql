CREATE TABLE IF NOT EXISTS shopping_list_items (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    week_id        INTEGER NOT NULL REFERENCES weeks(id),
    name           TEXT    NOT NULL,
    quantity       REAL,
    unit           TEXT,
    category       TEXT,
    is_purchased   INTEGER DEFAULT 0,
    purchased_by   INTEGER REFERENCES users(id),
    added_manually INTEGER DEFAULT 0,
    added_by       INTEGER REFERENCES users(id)
);
