<?php

declare(strict_types=1);

namespace Aidelnicek;

/**
 * Validace tenant slugů a cesty k datům ({projectRoot}/data/{slug}/).
 */
final class Tenant
{
    /** Jeden segment URL — malá písmena, čísla, pomlčka, podtržítko. */
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,62}$/';

    /** Nesmí být interpretováno jako tenant (sdílená statika / veřejné soubory). */
    private const RESERVED_SLUGS = [
        'css', 'js', 'img', 'images', 'fonts', 'assets', 'static',
        'favicon.ico', 'robots.txt', 'manifest.json', 'sw.js',
        // Aplikační cesty bez tenant prefixu (nesmí být vyhodnoceny jako tenant)
        'login', 'register', 'logout', 'profile', 'plan', 'shopping', 'admin', 'llm',
    ];

    public static function isValidSlug(string $slug): bool
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || in_array($slug, self::RESERVED_SLUGS, true)) {
            return false;
        }
        return (bool) preg_match(self::SLUG_PATTERN, $slug);
    }

    public static function dataRootPath(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/data';
    }

    public static function tenantDataDir(string $projectRoot, string $slug): string
    {
        return self::dataRootPath($projectRoot) . '/' . $slug;
    }

    public static function tenantExists(string $projectRoot, string $slug): bool
    {
        if (!self::isValidSlug($slug)) {
            return false;
        }
        $dir = self::tenantDataDir($projectRoot, $slug);

        return is_dir($dir);
    }

    /**
     * @return list<string>
     */
    public static function listTenantSlugs(string $projectRoot): array
    {
        $root = self::dataRootPath($projectRoot);
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!self::isValidSlug($name)) {
                continue;
            }
            if (!is_dir($root . '/' . $name)) {
                continue;
            }
            $out[] = $name;
        }
        sort($out);

        return $out;
    }
}
