<?php

declare(strict_types=1);

namespace Aidelnicek;

class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function getToken(): string
    {
        Auth::init();
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(string $token): bool
    {
        Auth::init();
        $stored = $_SESSION[self::SESSION_KEY] ?? '';
        if ($stored === '' || $token === '') {
            return false;
        }
        return hash_equals($stored, $token);
    }

    public static function generate(): string
    {
        return self::getToken();
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::getToken()) . '">';
    }
}
