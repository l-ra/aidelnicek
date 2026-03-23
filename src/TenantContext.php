<?php

declare(strict_types=1);

namespace Aidelnicek;

/**
 * Aktuální tenant pro HTTP request nebo CLI iteraci.
 */
final class TenantContext
{
    private static ?string $slug = null;

    public static function reset(): void
    {
        self::$slug = null;
    }

    public static function setSlug(?string $slug): void
    {
        self::$slug = $slug !== null && $slug !== '' ? $slug : null;
    }

    public static function slug(): ?string
    {
        return self::$slug;
    }

    public static function requireSlug(): string
    {
        if (self::$slug === null || self::$slug === '') {
            throw new \RuntimeException('TenantContext: tenant není nastaven.');
        }

        return self::$slug;
    }

    public static function initFromSlug(string $slug): void
    {
        if (!Tenant::isValidSlug($slug)) {
            throw new \InvalidArgumentException('Neplatný tenant slug.');
        }
        self::$slug = $slug;
    }
}
