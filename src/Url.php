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
