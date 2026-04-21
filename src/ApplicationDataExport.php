<?php

declare(strict_types=1);

namespace Aidelnicek;

use PDO;

/**
 * Export / import dat hlavní databáze tenanta (SQLite soubor nebo PostgreSQL schéma).
 * LLM komunikační logy jsou mimo tento export (SQLite: llm_*.db, PostgreSQL: tabulka llm_log).
 */
final class ApplicationDataExport
{
    /** Verze formátu exportního JSON (při nekompatibilní změně struktury obalu zvýšit). */
    public const EXPORT_FORMAT_VERSION = 1;

    /**
     * Otisk „tvaru“ schématu: tabulky a sloupce (bez pořadí řádků).
     * Slouží k tomu, aby import šel jen do databáze se shodnou strukturou.
     */
    public static function schemaFingerprint(PDO $db): string
    {
        $tables = self::listDataTables($db);
        $parts  = [];
        foreach ($tables as $table) {
            $cols = self::listTableColumns($db, $table);
            sort($cols, SORT_STRING);
            $parts[] = $table . ':' . implode(',', $cols);
        }
        sort($parts, SORT_STRING);

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @return list<string>
     */
    public static function listDataTables(PDO $db): array
    {
        if (Database::isPostgres()) {
            $stmt = $db->query(
                "SELECT c.relname AS table_name
                 FROM pg_catalog.pg_class c
                 JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = current_schema()
                   AND c.relkind IN ('r', 'p')
                   AND NOT EXISTS (
                       SELECT 1 FROM pg_catalog.pg_inherits i WHERE i.inhrelid = c.oid
                   )
                   AND c.relname <> 'llm_log'
                 ORDER BY c.relname"
            );
        } else {
            $stmt = $db->query(
                "SELECT name FROM sqlite_master
                 WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
                 ORDER BY name"
            );
        }
        $names = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if (!is_array($names)) {
            return [];
        }

        /** @var list<string> $out */
        $out = [];
        foreach ($names as $n) {
            if (is_string($n) && $n !== '') {
                $out[] = $n;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function listTableColumns(PDO $db, string $table): array
    {
        $qt = self::quoteIdent($table);
        if (Database::isPostgres()) {
            $stmt = $db->prepare(
                'SELECT column_name FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = ?
                 ORDER BY ordinal_position'
            );
            $stmt->execute([$table]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt  = $db->query('PRAGMA table_info(' . $qt . ')');
            $rows  = [];
            foreach ($stmt ?: [] as $col) {
                $rows[] = (string) ($col['name'] ?? '');
            }
        }

        /** @var list<string> $out */
        $out = [];
        foreach ($rows as $c) {
            if (is_string($c) && $c !== '') {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * @return array{export_format_version: int, schema_fingerprint: string, exported_at: string, tenant_slug: string, tables: array<string, list<array<string, mixed>>>}
     */
    public static function buildExportPayload(PDO $db, string $tenantSlug): array
    {
        $tables   = self::listDataTables($db);
        $exported = [];
        foreach ($tables as $table) {
            $rows = $db->query('SELECT * FROM ' . self::quoteIdent($table))->fetchAll(PDO::FETCH_ASSOC);
            $exported[$table] = $rows !== false ? $rows : [];
        }

        return [
            'export_format_version' => self::EXPORT_FORMAT_VERSION,
            'schema_fingerprint'    => self::schemaFingerprint($db),
            'exported_at'           => gmdate('c'),
            'tenant_slug'           => $tenantSlug,
            'tables'                => $exported,
        ];
    }

    public static function exportToGzipJson(PDO $db, string $tenantSlug): string
    {
        $payload = self::buildExportPayload($db, $tenantSlug);
        $json    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $gz = gzencode($json, 9);
        if ($gz === false) {
            throw new \RuntimeException('Komprese exportu (gzip) selhala.');
        }

        return $gz;
    }

    /**
     * @return array{ok: true, tables_imported: int, rows_imported: int}|array{ok: false, error: string}
     */
    public static function importFromGzipJson(PDO $db, string $gzipBinary): array
    {
        $json = @gzdecode($gzipBinary);
        if ($json === false || $json === '') {
            return ['ok' => false, 'error' => 'Soubor není platný gzip nebo je prázdný.'];
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['ok' => false, 'error' => 'Neplatný JSON: ' . $e->getMessage()];
        }

        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Kořen exportu musí být objekt.'];
        }

        $formatVersion = isset($data['export_format_version']) ? (int) $data['export_format_version'] : 0;
        if ($formatVersion !== self::EXPORT_FORMAT_VERSION) {
            return [
                'ok'    => false,
                'error' => 'Nepodporovaná verze formátu exportu ('
                    . $formatVersion . ', očekáváno ' . self::EXPORT_FORMAT_VERSION . ').',
            ];
        }

        $fp = isset($data['schema_fingerprint']) ? (string) $data['schema_fingerprint'] : '';
        if ($fp === '' || !hash_equals(self::schemaFingerprint($db), $fp)) {
            return [
                'ok'    => false,
                'error' => 'Schéma databáze neodpovídá exportu (jiný otisk tabulek/sloupců). Import odmítnut.',
            ];
        }

        $tablesPayload = $data['tables'] ?? null;
        if (!is_array($tablesPayload)) {
            return ['ok' => false, 'error' => 'V exportu chybí pole tables.'];
        }

        $expectedTables = self::listDataTables($db);
        $payloadTables  = array_keys($tablesPayload);
        sort($expectedTables, SORT_STRING);
        sort($payloadTables, SORT_STRING);
        if ($expectedTables !== $payloadTables) {
            return ['ok' => false, 'error' => 'Množina tabulek v exportu neodpovídá databázi.'];
        }

        $rowsImported   = 0;
        $tablesTouched  = 0;
        $isPostgres     = Database::isPostgres();

        if (!$isPostgres) {
            $db->exec('PRAGMA foreign_keys = OFF');
        }

        try {
            $db->beginTransaction();
            if ($isPostgres) {
                $quoted = array_map(static fn (string $t) => self::quoteIdent($t), $expectedTables);
                $db->exec('TRUNCATE TABLE ' . implode(', ', $quoted) . ' RESTART IDENTITY CASCADE');
            } else {
                foreach ($expectedTables as $table) {
                    $db->exec('DELETE FROM ' . self::quoteIdent($table));
                }
            }

            foreach ($expectedTables as $table) {
                $rows = $tablesPayload[$table];
                if (!is_array($rows)) {
                    throw new \InvalidArgumentException('Tabulka ' . $table . ': očekáno pole řádků.');
                }
                if ($rows === []) {
                    $tablesTouched++;
                    continue;
                }
                self::insertRows($db, $table, $rows);
                $rowsImported += count($rows);
                $tablesTouched++;
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (!$isPostgres) {
                $db->exec('PRAGMA foreign_keys = ON');
            }

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (!$isPostgres) {
            $db->exec('PRAGMA foreign_keys = ON');
        }

        return [
            'ok'               => true,
            'tables_imported' => $tablesTouched,
            'rows_imported'   => $rowsImported,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function insertRows(PDO $db, string $table, array $rows): void
    {
        $qt = self::quoteIdent($table);

        $columns = self::listTableColumns($db, $table);
        if ($columns === []) {
            throw new \InvalidArgumentException('Tabulka ' . $table . ' nemá sloupce.');
        }

        $qcols        = array_map(static fn (string $c) => self::quoteIdent($c), $columns);
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $sql          = 'INSERT INTO ' . $qt . ' (' . implode(', ', $qcols) . ') VALUES ' . $placeholders;
        $stmt         = $db->prepare($sql);

        foreach ($rows as $idx => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Tabulka ' . $table . ', řádek ' . $idx . ': očekáván objekt řádku.');
            }
            $values = [];
            foreach ($columns as $col) {
                if (!array_key_exists($col, $row)) {
                    throw new \InvalidArgumentException('Tabulka ' . $table . ': chybí sloupec ' . $col . '.');
                }
                $values[] = $row[$col];
            }
            $stmt->execute($values);
        }
    }

    private static function quoteIdent(string $ident): string
    {
        if ($ident === '' || preg_match('/[^A-Za-z0-9_]/', $ident) === 1) {
            throw new \InvalidArgumentException('Neplatný identifikátor tabulky/sloupce.');
        }

        return '"' . str_replace('"', '""', $ident) . '"';
    }
}
