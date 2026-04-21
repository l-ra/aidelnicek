<?php

declare(strict_types=1);

namespace Aidelnicek;

use PDO;

class Database
{
    private const INITIAL_ADMIN_PASSWORD_FILE = 'initial-admin-password.txt';

    private static ?PDO $connection = null;
    /** SQLite: cesta k souboru. PostgreSQL: popisný identifikátor (DSN + schéma). */
    private static string $dbPath = '';
    private static string $dataDir = '';
    private static ?string $activeTenantSlug = null;
    private static bool $usePostgres = false;
    private static string $pgSchema = '';

    public static function isPostgres(): bool
    {
        return self::$usePostgres;
    }

    /**
     * PostgreSQL schéma tenanta (search_path). Pouze v PG režimu.
     */
    public static function getPostgresTenantSchema(): string
    {
        if (!self::$usePostgres || self::$pgSchema === '') {
            throw new \RuntimeException('Database: PostgreSQL schéma není k dispozici.');
        }

        return self::$pgSchema;
    }

    /**
     * Inicializace DB pro jednoho tenanta. Bez $tenantSlug pouze připraví kořen dat (CLI / landing).
     *
     * @throws \InvalidArgumentException tenant neexistuje
     */
    public static function init(string $basePath, ?string $tenantSlug = null): void
    {
        $dataRoot = Tenant::dataRootPath($basePath);
        if (!is_dir($dataRoot)) {
            mkdir($dataRoot, 0755, true);
        }

        if ($tenantSlug === null || $tenantSlug === '') {
            self::$connection = null;
            self::$activeTenantSlug = null;
            self::$dataDir = '';
            self::$dbPath = '';
            self::$usePostgres = false;
            self::$pgSchema = '';

            return;
        }

        if (!Tenant::tenantExists($basePath, $tenantSlug)) {
            throw new \InvalidArgumentException('Neexistující tenant: ' . $tenantSlug);
        }

        if (self::$activeTenantSlug !== $tenantSlug) {
            self::$connection = null;
        }

        self::$activeTenantSlug = $tenantSlug;
        self::$dataDir = Tenant::tenantDataDir($basePath, $tenantSlug);

        if (PostgresEnv::isEnabled()) {
            self::$usePostgres = true;
            self::$pgSchema   = self::buildPostgresSchemaName($tenantSlug);
            $cfg               = PostgresEnv::requireAll();
            self::$dbPath     = sprintf(
                'pgsql://%s:%d/%s?schema=%s',
                $cfg['server'],
                $cfg['port'],
                $cfg['database'],
                self::$pgSchema
            );
        } else {
            self::$usePostgres = false;
            self::$pgSchema    = '';
            self::$dbPath      = self::$dataDir . '/aidelnicek.sqlite';
        }

        self::assertTenantStorageWritable();
    }

    /**
     * Název schématu v PostgreSQL (max. 63 znaků, bezpečné znaky).
     */
    public static function buildPostgresSchemaName(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $base  = 'tenant_' . $slug;
        if (strlen($base) <= 63) {
            return $base;
        }

        return 't_' . substr(sha1($slug), 0, 61);
    }

    /**
     * SQLite hlásí „attempt to write a readonly database“, když proces webového serveru
     * nemůže zapisovat do souboru DB nebo do složky tenanta (WAL/-shm soubory).
     * V PostgreSQL režimu kontrolujeme zapisovatelnost složky tenanta (soubory s klíči atd.).
     */
    private static function assertTenantStorageWritable(): void
    {
        if (self::$dataDir === '') {
            return;
        }
        if (!is_dir(self::$dataDir)) {
            throw new \RuntimeException(
                'Datová složka tenanta neexistuje: ' . self::$dataDir
            );
        }
        if (!is_writable(self::$dataDir)) {
            throw new \RuntimeException(
                'Datová složka tenanta není zapisovatelná pro webový server: ' . self::$dataDir
            );
        }
        if (self::$usePostgres) {
            return;
        }
        if (self::$dbPath === '') {
            return;
        }
        if (is_file(self::$dbPath) && !is_writable(self::$dbPath)) {
            throw new \RuntimeException(
                'Soubor databáze SQLite není zapisovatelný: ' . self::$dbPath
                . ' (chmod/chown pro uživatele webového serveru, např. www-data).'
            );
        }
    }

    public static function getTenantDataDir(): string
    {
        if (self::$dataDir === '') {
            throw new \RuntimeException('Database: tenant data dir není nastaven — chybí init s tenant slug.');
        }

        return self::$dataDir;
    }

    public static function getPath(): string
    {
        if (self::$dbPath === '') {
            throw new \RuntimeException('Database: není nastaven identifikátor databáze.');
        }

        return self::$dbPath;
    }

    public static function get(): PDO
    {
        if (self::$dbPath === '' || self::$activeTenantSlug === null) {
            throw new \RuntimeException('Database: tenant není inicializován.');
        }

        if (self::$connection === null) {
            if (self::$usePostgres) {
                $cfg = PostgresEnv::requireAll();
                $dsn = sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    $cfg['server'],
                    $cfg['port'],
                    $cfg['database']
                );
                try {
                    self::$connection = new PDO(
                        $dsn,
                        $cfg['user'],
                        $cfg['password'],
                        [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        ]
                    );
                } catch (\PDOException $e) {
                    if (self::isPostgresPasswordAuthFailure($e)) {
                        error_log(
                            'PostgreSQL: selhala autentizace (heslo). '
                            . self::postgresAuthDebugLine($cfg)
                            . ' | původní výjimka: ' . $e->getMessage()
                        );
                    }
                    throw $e;
                }
                self::$connection->exec('SET client_encoding TO UTF8');
                self::ensurePostgresTenantSchema(self::$connection, self::$pgSchema);
                self::$connection->exec(
                    'SET search_path TO ' . self::quotePgIdent(self::$pgSchema) . ', public'
                );
            } else {
                self::$connection = new PDO(
                    'sqlite:' . self::$dbPath,
                    null,
                    null,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                );
                self::$connection->exec('PRAGMA journal_mode=WAL');
                self::$connection->exec('PRAGMA foreign_keys = ON');
            }

            self::runMigrations();
            if (self::$usePostgres) {
                self::ensurePostgresLlmLogPartitionedTable(self::$connection);
            }
            self::ensureAdminUser();
        }

        return self::$connection;
    }

    /**
     * Diagnostika při chybě „password authentication failed“ — neprozrazuje celé heslo.
     */
    private static function isPostgresPasswordAuthFailure(\PDOException $e): bool
    {
        $msg = $e->getMessage();

        return stripos($msg, 'password authentication failed') !== false
            || ($e->errorInfo[0] ?? '') === '28P01';
    }

    /**
     * Krátký náhled tajného řetězce (začátek + konec) pro logy; střed je vynechán.
     */
    private static function redactSecretEdges(string $secret, int $edgeLen = 2): string
    {
        $len = strlen($secret);
        if ($len === 0) {
            return '(prázdné)';
        }
        $edgeLen = max(1, $edgeLen);
        if ($len <= $edgeLen * 2) {
            return str_repeat('*', min(8, $len)) . ' (délka ' . $len . ')';
        }

        return substr($secret, 0, $edgeLen)
            . '…'
            . substr($secret, -$edgeLen)
            . ' (délka ' . $len . ')';
    }

    /**
     * @param array{server: string, port: int, database: string, user: string, password: string} $cfg
     */
    private static function postgresAuthDebugLine(array $cfg): string
    {
        return sprintf(
            'DSN host=%s port=%d dbname=%s user=%s | heslo délka=%d náhled=%s',
            $cfg['server'],
            $cfg['port'],
            $cfg['database'],
            $cfg['user'],
            strlen($cfg['password']),
            self::redactSecretEdges($cfg['password'])
        );
    }

    /**
     * Zajistí partitiony llm_log pro blízké měsíce (volat před INSERT, např. při přechodu měsíce).
     */
    public static function touchLlmLogPartitions(): void
    {
        if (!self::$usePostgres || self::$connection === null || self::$pgSchema === '') {
            return;
        }
        self::ensurePostgresLlmLogPartitionsForUpcomingMonths(self::$connection, self::$pgSchema, 3);
    }

    private static function ensurePostgresTenantSchema(PDO $db, string $schema): void
    {
        $q = self::quotePgIdent($schema);
        $db->exec('CREATE SCHEMA IF NOT EXISTS ' . $q);
    }

    private static function quotePgIdent(string $ident): string
    {
        if ($ident === '' || preg_match('/[^a-z0-9_]/', $ident) === 1) {
            throw new \InvalidArgumentException('Neplatný identifikátor PostgreSQL schématu.');
        }

        return '"' . str_replace('"', '""', $ident) . '"';
    }

    /**
     * Jedna tabulka llm_log v tenant schématu, partitionovaná podle měsíce (log_date).
     */
    private static function ensurePostgresLlmLogPartitionedTable(PDO $db): void
    {
        $schema = self::$pgSchema;
        $exists  = (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = " . $db->quote($schema) . "
               AND table_name = 'llm_log'"
        )->fetchColumn();
        if ($exists > 0) {
            self::ensurePostgresLlmLogPartitionsForUpcomingMonths($db, $schema, 3);

            return;
        }

        $db->exec(
            'CREATE TABLE llm_log (
                id BIGSERIAL,
                provider TEXT NOT NULL,
                model TEXT NOT NULL,
                user_id BIGINT,
                prompt_system TEXT,
                prompt_user TEXT NOT NULL,
                response_text TEXT,
                tokens_in INTEGER,
                tokens_out INTEGER,
                request_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                duration_ms INTEGER,
                status TEXT NOT NULL DEFAULT \'ok\',
                error_message TEXT,
                PRIMARY KEY (id, request_at)
            ) PARTITION BY RANGE (request_at)'
        );

        self::ensurePostgresLlmLogPartitionsForUpcomingMonths($db, $schema, 3);
    }

    /**
     * Zajistí existenci měsíčních partition tabulek llm_log (aktuální + následující měsíce).
     */
    private static function ensurePostgresLlmLogPartitionsForUpcomingMonths(
        PDO $db,
        string $schema,
        int $monthsAhead
    ): void {
        $tz    = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable('first day of this month', $tz);
        for ($i = 0; $i <= max(0, $monthsAhead); $i++) {
            $monthStart = $start->modify('+' . $i . ' months');
            $monthEnd   = $monthStart->modify('+1 month');
            $suffix     = $monthStart->format('Y_m');
            $partName   = 'llm_log_p_' . $suffix;
            if (strlen($partName) > 63) {
                $partName = 'llm_p_' . substr(sha1($suffix), 0, 56);
            }
            $from = $monthStart->format('Y-m-d') . ' 00:00:00+00';
            $to   = $monthEnd->format('Y-m-d') . ' 00:00:00+00';

            $exists = (int) $db->query(
                "SELECT COUNT(*) FROM pg_catalog.pg_class c
                 JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = " . $db->quote($schema) . "
                   AND c.relname = " . $db->quote($partName)
            )->fetchColumn();
            if ($exists > 0) {
                continue;
            }

            $qPart = self::quotePgIdent($partName);
            $db->exec(
                'CREATE TABLE ' . $qPart . ' PARTITION OF llm_log FOR VALUES FROM (\''
                . $from . '\') TO (\'' . $to . '\')'
            );
        }
    }

    /**
     * Smaže soubor s počátečním heslem admina (po prvním úspěšném přihlášení admina).
     */
    public static function removeInitialAdminPasswordFileIfPresent(): void
    {
        if (self::$dataDir === '') {
            return;
        }
        $file = self::$dataDir . '/' . self::INITIAL_ADMIN_PASSWORD_FILE;
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private static function runMigrations(): void
    {
        $db      = self::$connection;
        $isPg    = self::$usePostgres;
        $paired  = DatabaseMigrations::pairedSteps();
        $firstSql = $isPg ? $paired[0]['pgsql'] : $paired[0]['sqlite'];
        $db->exec($firstSql);

        foreach ($paired as $step) {
            $sqliteSql = $step['sqlite'];
            $sql       = $isPg ? $step['pgsql'] : $sqliteSql;
            $name      = self::migrationName($sqliteSql);
            $stmt      = $db->prepare('SELECT 1 FROM migrations WHERE name = ?');
            $stmt->execute([$name]);
            if ($stmt->fetch() !== false) {
                continue;
            }
            try {
                $db->exec($sql);
            } catch (\Throwable $e) {
                if (!self::isBenignMigrationConflict($e, $isPg)) {
                    throw $e;
                }
            }
            $db->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$name]);
        }
    }

    private static function isBenignMigrationConflict(\Throwable $e, bool $isPostgres): bool
    {
        $message = mb_strtolower($e->getMessage());
        if (!$isPostgres) {
            return str_contains($message, 'duplicate column name');
        }
        if (str_contains($message, 'already exists')) {
            return true;
        }
        $state = $e instanceof \PDOException ? ($e->errorInfo[0] ?? '') : '';

        return $state === '42P07' // duplicate_table
            || $state === '42710' // duplicate_object
            || $state === '42701'; // duplicate_column
    }

    /**
     * Stabilní hash názvu migrace — počítá se vždy z SQLite SQL (shoda s existujícími SQLite DB).
     */
    private static function migrationName(string $sqliteSql): string
    {
        $normalizedSql = preg_replace('/\s+/', ' ', trim($sqliteSql)) ?? trim($sqliteSql);

        return 'migration_' . substr(sha1($normalizedSql), 0, 16);
    }

    /**
     * Dny, pro které existují LLM logy (SQLite: soubory, PostgreSQL: řádky v llm_log).
     *
     * @return list<string> formát YYYY-MM-DD, seřazeno sestupně
     */
    public static function listLlmLogDates(): array
    {
        if (self::$dataDir === '') {
            throw new \RuntimeException('Database: tenant není inicializován.');
        }
        if (self::$usePostgres) {
            $pdo = self::get();
            $stmt = $pdo->query(
                "SELECT DISTINCT (request_at AT TIME ZONE 'UTC')::date AS d
                 FROM llm_log
                 ORDER BY 1 DESC"
            );
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

            /** @var list<string> $out */
            $out = [];
            foreach ($rows as $r) {
                if ($r !== null && $r !== '') {
                    $out[] = (string) $r;
                }
            }

            return $out;
        }

        /** @var list<string> $logFiles */
        $logFiles = [];
        if (is_dir(self::$dataDir)) {
            foreach (glob(self::$dataDir . '/llm_*.db') ?: [] as $path) {
                $basename = basename($path);
                if (preg_match('/^llm_(\d{4}-\d{2}-\d{2})\.db$/', $basename, $m)) {
                    $logFiles[] = $m[1];
                }
            }
        }
        rsort($logFiles, SORT_STRING);

        return $logFiles;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fetchLlmLogRowsForDate(string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Neplatné datum logu.');
        }
        if (self::$dataDir === '') {
            throw new \RuntimeException('Database: tenant není inicializován.');
        }
        if (self::$usePostgres) {
            $pdo = self::get();
            $stmt = $pdo->prepare(
                'SELECT id,
                        to_char(request_at AT TIME ZONE \'UTC\', \'YYYY-MM-DD HH24:MI:SS\') AS request_at,
                        provider, model, user_id, prompt_system, prompt_user, response_text,
                        tokens_in, tokens_out, duration_ms, status, error_message
                 FROM llm_log
                 WHERE (request_at AT TIME ZONE \'UTC\')::date = ?::date
                 ORDER BY id DESC'
            );
            $stmt->execute([$date]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $rows !== false ? $rows : [];
        }

        $path = self::$dataDir . '/llm_' . $date . '.db';
        if (!is_file($path)) {
            return [];
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return $pdo->query(
            'SELECT id, request_at, provider, model, user_id, prompt_system, prompt_user,
                    response_text, tokens_in, tokens_out, duration_ms, status, error_message
             FROM llm_log ORDER BY id DESC'
        )->fetchAll();
    }

    private static function ensureAdminUser(): void
    {
        $db = self::$connection;

        $count = (int) $db->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
        $hash     = password_hash($password, PASSWORD_DEFAULT);

        $db->prepare(
            'INSERT INTO users (name, email, password_hash, is_admin) VALUES (?, ?, ?, 1)'
        )->execute(['Administrátor', 'admin@localhost', $hash]);

        $message  = "Aidelnicek: Byl vytvořen výchozí administrátorský účet (tenant).\n"
                  . "  E-mail:  admin@localhost\n"
                  . "  Heslo:   {$password}\n"
                  . "  Čas:     " . date('Y-m-d H:i:s') . "\n";

        error_log(str_replace("\n", ' | ', trim($message)));

        if (self::$dataDir !== '') {
            $file = self::$dataDir . '/' . self::INITIAL_ADMIN_PASSWORD_FILE;
            $written = @file_put_contents($file, $message, LOCK_EX);
            if ($written !== false) {
                @chmod($file, 0600);
            }
        }
    }
}
