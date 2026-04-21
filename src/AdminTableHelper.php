<?php

declare(strict_types=1);

namespace Aidelnicek;

use PDO;

/**
 * Pomocné dotazy pro admin prohlížeč tabulek (SQLite i PostgreSQL).
 */
final class AdminTableHelper
{
    /**
     * @return list<string>
     */
    public static function listTables(PDO $db): array
    {
        return ApplicationDataExport::listDataTables($db);
    }

    /**
     * Metadata sloupců ve formátu blízkém PRAGMA table_info (name, pk).
     *
     * @return list<array{name: string, pk: int}>
     */
    public static function listColumnsMeta(PDO $db, string $table): array
    {
        self::assertIdent($table);
        if (Database::isPostgres()) {
            $pkStmt = $db->prepare(
                "SELECT kcu.column_name
                 FROM information_schema.table_constraints tc
                 JOIN information_schema.key_column_usage kcu
                   ON tc.constraint_schema = kcu.constraint_schema
                  AND tc.constraint_name = kcu.constraint_name
                 WHERE tc.table_schema = current_schema()
                   AND tc.table_name = ?
                   AND tc.constraint_type = 'PRIMARY KEY'
                 ORDER BY kcu.ordinal_position"
            );
            $pkStmt->execute([$table]);
            $pkCols = $pkStmt->fetchAll(PDO::FETCH_COLUMN);
            $pkSet  = [];
            foreach ($pkCols as $c) {
                if (is_string($c) && $c !== '') {
                    $pkSet[$c] = true;
                }
            }

            $stmt = $db->prepare(
                'SELECT column_name FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = ?
                 ORDER BY ordinal_position'
            );
            $stmt->execute([$table]);
            $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $out   = [];
            foreach ($names as $n) {
                if (!is_string($n) || $n === '') {
                    continue;
                }
                $out[] = ['name' => $n, 'pk' => isset($pkSet[$n]) ? 1 : 0];
            }

            return $out;
        }

        $qt = self::quoteIdent($table);
        $out = [];
        foreach ($db->query('PRAGMA table_info(' . $qt . ')') as $col) {
            $out[] = [
                'name' => (string) ($col['name'] ?? ''),
                'pk'   => (int) ($col['pk'] ?? 0),
            ];
        }

        return $out;
    }

    public static function primaryKeyColumn(PDO $db, string $table): ?string
    {
        foreach (self::listColumnsMeta($db, $table) as $col) {
            if (($col['pk'] ?? 0) === 1) {
                return $col['name'];
            }
        }

        return null;
    }

    /**
     * @param list<array{name: string, pk: int}> $columns
     */
    public static function selectPageSql(string $table, array $columns, int $limit, int $offset): string
    {
        self::assertIdent($table);
        $qt = self::quoteIdent($table);
        if (Database::isPostgres()) {
            $pk = null;
            foreach ($columns as $col) {
                if (($col['pk'] ?? 0) === 1) {
                    $pk = $col['name'];
                    break;
                }
            }
            if ($pk === null) {
                throw new \RuntimeException('Tabulka nemá primární klíč — prohlížeč ji v PostgreSQL nepodporuje.');
            }
            $qpk = self::quoteIdent($pk);

            return 'SELECT ' . $qpk . ' AS __rowid, * FROM ' . $qt
                . ' ORDER BY ' . $qpk . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }

        return 'SELECT rowid AS __rowid, * FROM ' . $qt
            . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    }

    private static function assertIdent(string $ident): void
    {
        if ($ident === '' || preg_match('/[^A-Za-z0-9_]/', $ident) === 1) {
            throw new \InvalidArgumentException('Neplatný název tabulky.');
        }
    }

    private static function quoteIdent(string $ident): string
    {
        self::assertIdent($ident);

        return '"' . str_replace('"', '""', $ident) . '"';
    }
}
