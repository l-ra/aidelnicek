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

    public static function getPath(): string
    {
        return self::$dbPath;
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
            // WAL mode allows the Python LLM worker sidecar to write concurrently
            // while PHP reads (required for SSE streaming progress endpoint).
            self::$connection->exec('PRAGMA journal_mode=WAL');
            self::$connection->exec('PRAGMA foreign_keys = ON');
            self::runMigrations();
            self::ensureAdminUser();
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
            // M3: unique index on meal_history so ON CONFLICT upserts work
            <<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_meal_history_user_meal
                ON meal_history(user_id, meal_name);
            SQL,
            // M6: výška, váha, cíl jídelníčku na profilu uživatele
            <<<'SQL'
            ALTER TABLE users ADD COLUMN height INTEGER;
            SQL,
            <<<'SQL'
            ALTER TABLE users ADD COLUMN weight REAL;
            SQL,
            <<<'SQL'
            ALTER TABLE users ADD COLUMN diet_goal TEXT;
            SQL,
            // M7: sledování průběhu generování pro Python LLM worker sidecar
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS generation_jobs (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       INTEGER NOT NULL,
                week_id       INTEGER NOT NULL,
                status        TEXT    NOT NULL DEFAULT 'pending',
                progress_text TEXT    NOT NULL DEFAULT '',
                chunk_count   INTEGER NOT NULL DEFAULT 0,
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                started_at    DATETIME,
                finished_at   DATETIME,
                error_message TEXT
            );
            SQL,
            // M9: sdílené LLM návrhy jídelníčků a recepty napříč uživateli
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS llm_meal_proposals (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                week_id            INTEGER NOT NULL REFERENCES weeks(id),
                generation_job_id  INTEGER REFERENCES generation_jobs(id),
                reference_user_id  INTEGER NOT NULL REFERENCES users(id),
                llm_model          TEXT,
                created_at         DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS llm_proposal_meals (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                proposal_id  INTEGER NOT NULL REFERENCES llm_meal_proposals(id) ON DELETE CASCADE,
                day_of_week  INTEGER NOT NULL,
                meal_type    TEXT NOT NULL,
                alternative  INTEGER NOT NULL,
                meal_name    TEXT NOT NULL,
                description  TEXT,
                ingredients  TEXT NOT NULL,
                UNIQUE(proposal_id, day_of_week, meal_type, alternative)
            );
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS meal_recipes (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                proposal_meal_id     INTEGER NOT NULL UNIQUE REFERENCES llm_proposal_meals(id) ON DELETE CASCADE,
                generated_by_user_id INTEGER REFERENCES users(id),
                model                TEXT,
                recipe_text          TEXT NOT NULL,
                created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            SQL,
            <<<'SQL'
            ALTER TABLE meal_plans ADD COLUMN proposal_meal_id INTEGER REFERENCES llm_proposal_meals(id);
            SQL,
            <<<'SQL'
            ALTER TABLE meal_plans ADD COLUMN portion_factor REAL NOT NULL DEFAULT 1.0;
            SQL,
            <<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_meal_plans_proposal_meal_id
                ON meal_plans(proposal_meal_id);
            SQL,
            // M10: sjednocená LLM job orchestrace + chunk/output tabulky
            <<<'SQL'
            ALTER TABLE generation_jobs ADD COLUMN job_type TEXT NOT NULL DEFAULT 'mealplan_generate';
            SQL,
            <<<'SQL'
            ALTER TABLE generation_jobs ADD COLUMN mode TEXT NOT NULL DEFAULT 'async';
            SQL,
            <<<'SQL'
            ALTER TABLE generation_jobs ADD COLUMN input_payload TEXT NOT NULL DEFAULT '{}';
            SQL,
            <<<'SQL'
            ALTER TABLE generation_jobs ADD COLUMN projection_status TEXT NOT NULL DEFAULT 'pending';
            SQL,
            <<<'SQL'
            ALTER TABLE generation_jobs ADD COLUMN projection_error_message TEXT;
            SQL,
            <<<'SQL'
            ALTER TABLE generation_jobs ADD COLUMN projection_started_at DATETIME;
            SQL,
            <<<'SQL'
            ALTER TABLE generation_jobs ADD COLUMN projection_finished_at DATETIME;
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS generation_job_chunks (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id     INTEGER NOT NULL REFERENCES generation_jobs(id) ON DELETE CASCADE,
                seq_no     INTEGER NOT NULL,
                chunk_text TEXT    NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(job_id, seq_no)
            );
            SQL,
            <<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_generation_job_chunks_job_seq
                ON generation_job_chunks(job_id, seq_no);
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS generation_job_outputs (
                job_id       INTEGER PRIMARY KEY REFERENCES generation_jobs(id) ON DELETE CASCADE,
                provider     TEXT    NOT NULL DEFAULT 'openai',
                model        TEXT    NOT NULL,
                raw_text     TEXT    NOT NULL,
                parsed_json  TEXT,
                tokens_in    INTEGER,
                tokens_out   INTEGER,
                duration_ms  INTEGER,
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            SQL,
            <<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_generation_jobs_status_projection
                ON generation_jobs(status, projection_status);
            SQL,
        ];

        $db = self::$connection;

        // Bootstrap the tracking table before the loop queries it.
        // Without this, the very first iteration would SELECT FROM a table that does not exist yet.
        $db->exec('CREATE TABLE IF NOT EXISTS migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

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

    /**
     * Zajistí existenci výchozího administrátorského účtu.
     *
     * Spustí se při prvním otevření DB spojení. Pokud v databázi neexistuje žádný
     * uživatel s příznakem is_admin = 1, vytvoří výchozí admin účet s náhodným heslem.
     * Heslo je zapsáno do logu aplikace a do souboru /tmp/initial-admin-password.
     */
    private static function ensureAdminUser(): void
    {
        $db = self::$connection;

        $count = (int) $db->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
        if ($count > 0) {
            return;
        }

        // Náhodné heslo: 16 čitelných znaků z base64 bez padding
        $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
        $hash     = password_hash($password, PASSWORD_DEFAULT);

        $db->prepare(
            'INSERT INTO users (name, email, password_hash, is_admin) VALUES (?, ?, ?, 1)'
        )->execute(['Administrátor', 'admin@localhost', $hash]);

        $message  = "Aidelnicek: Byl vytvořen výchozí administrátorský účet.\n"
                  . "  E-mail:  admin@localhost\n"
                  . "  Heslo:   {$password}\n"
                  . "  Čas:     " . date('Y-m-d H:i:s') . "\n";

        error_log(str_replace("\n", ' | ', trim($message)));

        $file    = '/tmp/initial-admin-password';
        $written = @file_put_contents($file, $message);
        if ($written !== false) {
            @chmod($file, 0600);
        }
    }
}
