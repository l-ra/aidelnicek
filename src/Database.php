<?php

namespace Aidelnicek;

use PDO;

class Database
{
    private static ?PDO $connection = null;
    private static string $dbPath;

    public static function init(string $basePath): void
    {
        $dataDir = $basePath . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        self::$dbPath = $dataDir . '/aidelnicek.sqlite';
    }

    public static function get(): PDO
    {
        if (self::$connection === null) {
            self::$connection = new PDO(
                'sqlite:' . self::$dbPath,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            self::$connection->exec('PRAGMA foreign_keys = ON');
            self::runMigrations();
        }
        return self::$connection;
    }

    private static function runMigrations(): void
    {
        $migrations = [
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                gender TEXT,
                age INTEGER,
                body_type TEXT,
                dietary_notes TEXT,
                is_admin INTEGER DEFAULT 0,
                push_subscription TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS weeks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                week_number INTEGER NOT NULL,
                year INTEGER NOT NULL,
                generated_at DATETIME,
                UNIQUE(week_number, year)
            );
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS meal_plans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id),
                week_id INTEGER NOT NULL REFERENCES weeks(id),
                day_of_week INTEGER NOT NULL,
                meal_type TEXT NOT NULL,
                alternative INTEGER NOT NULL,
                meal_name TEXT NOT NULL,
                description TEXT,
                ingredients TEXT,
                is_chosen INTEGER DEFAULT 0,
                is_eaten INTEGER DEFAULT 0,
                UNIQUE(user_id, week_id, day_of_week, meal_type, alternative)
            );
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS shopping_list_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                week_id INTEGER NOT NULL REFERENCES weeks(id),
                name TEXT NOT NULL,
                quantity REAL,
                unit TEXT,
                category TEXT,
                is_purchased INTEGER DEFAULT 0,
                purchased_by INTEGER REFERENCES users(id),
                added_manually INTEGER DEFAULT 0,
                added_by INTEGER REFERENCES users(id)
            );
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS meal_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id),
                meal_name TEXT NOT NULL,
                times_offered INTEGER DEFAULT 0,
                times_chosen INTEGER DEFAULT 0,
                times_eaten INTEGER DEFAULT 0,
                last_offered DATETIME
            );
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS notifications_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id),
                sent_at DATETIME,
                type TEXT,
                status TEXT
            );
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT
            );
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS remember_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id),
                token_hash TEXT NOT NULL,
                expires_at DATETIME NOT NULL
            );
            SQL,
        ];

        $db = self::$connection;
        foreach ($migrations as $i => $sql) {
            $name = 'migration_' . ($i + 1);
            $stmt = $db->prepare('SELECT 1 FROM migrations WHERE name = ?');
            $stmt->execute([$name]);
            if ($stmt->fetch() === false) {
                $db->exec($sql);
                $db->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$name]);
            }
        }
    }
}
