<?php

namespace Aidelnicek;

use PDO;

class Database
{
    private static ?PDO $connection = null;
    private static string $dbPath;
    private static string $migrationsPath;

    public static function init(string $basePath): void
    {
        $dataDir = $basePath . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        self::$dbPath = $dataDir . '/aidelnicek.sqlite';
        self::$migrationsPath = $basePath . '/src/migrations';
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
        $db = self::$connection;

        // The migrations tracking table cannot track itself – bootstrap it unconditionally.
        $db->exec('CREATE TABLE IF NOT EXISTS migrations (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT UNIQUE NOT NULL,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        $files = glob(self::$migrationsPath . '/*.sql');
        if (empty($files)) {
            return;
        }
        sort($files);

        foreach ($files as $file) {
            $name = basename($file, '.sql');
            $stmt = $db->prepare('SELECT 1 FROM migrations WHERE name = ?');
            $stmt->execute([$name]);
            if ($stmt->fetch() === false) {
                $db->exec((string) file_get_contents($file));
                $db->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$name]);
            }
        }
    }
}
