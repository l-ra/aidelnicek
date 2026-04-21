<?php

declare(strict_types=1);

namespace Aidelnicek;

/**
 * Načtení a validace proměnných prostředí pro PostgreSQL.
 * Pokud je nastaveno PG_DATABASE, očekává se kompletní sada PG_* proměnných.
 */
final class PostgresEnv
{
    public static function isEnabled(): bool
    {
        $db = getenv('PG_DATABASE');

        return is_string($db) && trim($db) !== '';
    }

    /**
     * @return array{server: string, port: int, database: string, user: string, password: string}
     */
    public static function requireAll(): array
    {
        $database = self::requireNonEmpty('PG_DATABASE');
        $server   = self::requireNonEmpty('PG_SERVER');
        $user     = self::requireNonEmpty('PG_USER');
        if (getenv('PG_PASS') === false) {
            throw new \RuntimeException(
                'PostgreSQL režim: proměnná PG_PASS musí být nastavena (může být prázdný řetězec, pokud databáze nevyžaduje heslo).'
            );
        }
        // Trim: častý problém po kopírování do secretů (mezera / newline na konci).
        $pass = trim((string) getenv('PG_PASS'));
        $portStr = self::getRaw('PG_PORT');
        if ($portStr === null || trim($portStr) === '') {
            throw new \RuntimeException('PostgreSQL režim: chybí povinná proměnná PG_PORT.');
        }
        if (!is_numeric($portStr) || (int) $portStr <= 0 || (int) $portStr > 65535) {
            throw new \RuntimeException('PostgreSQL režim: PG_PORT musí být číslo portu 1–65535.');
        }

        return [
            'server'   => $server,
            'port'     => (int) $portStr,
            'database' => $database,
            'user'     => $user,
            'password' => $pass,
        ];
    }

    private static function requireNonEmpty(string $name): string
    {
        $v = self::getRaw($name);
        if ($v === null || trim($v) === '') {
            throw new \RuntimeException(
                "PostgreSQL režim (nastaveno PG_DATABASE): chybí nebo je prázdná povinná proměnná {$name}."
            );
        }

        return trim($v);
    }

    private static function getRaw(string $name): ?string
    {
        $v = getenv($name);

        return $v === false ? null : (string) $v;
    }
}
