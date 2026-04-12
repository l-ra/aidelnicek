<?php

declare(strict_types=1);

namespace Aidelnicek;

/**
 * URL aplikace s tenant prefixem (/slug/cesta). Bez tenanta vrací cestu od kořene webu.
 */
final class Url
{
    /** Prefix cesty tenanta (např. `/dplusk`) nebo prázdný řetězec. */
    public static function basePath(): string
    {
        $slug = TenantContext::slug();

        return ($slug !== null && $slug !== '') ? '/' . $slug : '';
    }

    public static function u(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $slug = TenantContext::slug();
        if ($slug === null || $slug === '') {
            return $path === '//' ? '/' : $path;
        }

        return '/' . $slug . ($path === '/' ? '/' : $path);
    }

    /**
     * Přidá tenant prefix k relativní cestě v Location, pokud ještě není v URL.
     * Nechá beze změny absolutní URL (http://...).
     */
    public static function tenantLocation(string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return self::u('/');
        }
        if (preg_match('#^[a-z][a-z0-9+\-.]*:#i', $location) === 1) {
            return $location;
        }

        $path     = parse_url($location, PHP_URL_PATH) ?: '/';
        $query    = parse_url($location, PHP_URL_QUERY);
        $fragment = parse_url($location, PHP_URL_FRAGMENT);

        $slug = TenantContext::slug();
        if ($slug !== null && $slug !== '') {
            $prefix = '/' . $slug;
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $out = $path;
            } else {
                $out = self::u($path);
            }
        } else {
            $out = $path;
        }

        if ($query !== null && $query !== '') {
            $out .= '?' . $query;
        }
        if ($fragment !== null && $fragment !== '') {
            $out .= '#' . $fragment;
        }

        return $out;
    }

    /** Escapovaná cesta pro atributy href/action v šablonách. */
    public static function hu(string $path): string
    {
        return htmlspecialchars(self::u($path), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Zjistí, zda klient přistupuje přes HTTPS (včetně běžných hlaviček za reverse proxy).
     */
    public static function isRequestHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (is_string($proto) && $proto !== '') {
            $first = strtolower(trim(explode(',', $proto)[0]));
            if ($first === 'https') {
                return true;
            }
        }

        $xfSsl = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';
        if (is_string($xfSsl) && strtolower(trim($xfSsl)) === 'on') {
            return true;
        }

        if (isset($_SERVER['REQUEST_SCHEME']) && strtolower((string) $_SERVER['REQUEST_SCHEME']) === 'https') {
            return true;
        }

        return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
    }

    /**
     * Kořenová absolutní URL aktuálního požadavku (schéma + host), bez koncového lomítka.
     */
    public static function absoluteBaseUrl(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return (self::isRequestHttps() ? 'https' : 'http') . '://' . ($host !== '' ? $host : 'localhost');
    }

    /**
     * Query řetězec pro denní plán (den v týdnu + ISO týden a rok v DB).
     *
     * @param array{week_number: int|string, year: int|string} $week
     */
    public static function planDayQuery(int $day, array $week): string
    {
        return http_build_query([
            'day'  => $day,
            'week' => (int) $week['week_number'],
            'year' => (int) $week['year'],
        ]);
    }

    /**
     * @param array{week_number: int|string, year: int|string} $week
     */
    public static function planDayPath(int $day, array $week): string
    {
        return '/plan/day?' . self::planDayQuery($day, $week);
    }

    /**
     * @param array{week_number: int|string, year: int|string} $week
     */
    public static function planDayMealPath(int $day, string $mealType, array $week): string
    {
        return '/plan/day/meal?' . http_build_query([
            'day'       => $day,
            'meal_type' => $mealType,
            'week'      => (int) $week['week_number'],
            'year'      => (int) $week['year'],
        ]);
    }
}
