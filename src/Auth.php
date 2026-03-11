<?php

declare(strict_types=1);

namespace Aidelnicek;

class Auth
{
    private const SESSION_USER_ID = 'user_id';

    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function login(int $userId): void
    {
        self::init();
        $_SESSION[self::SESSION_USER_ID] = $userId;
    }

    public static function logout(): void
    {
        self::init();
        unset($_SESSION[self::SESSION_USER_ID]);
    }

    public static function isLoggedIn(): bool
    {
        self::init();
        return isset($_SESSION[self::SESSION_USER_ID]);
    }

    public static function getUserId(): ?int
    {
        self::init();
        $id = $_SESSION[self::SESSION_USER_ID] ?? null;
        return $id !== null ? (int) $id : null;
    }

    public static function getCurrentUser(): ?array
    {
        $userId = self::getUserId();
        if ($userId === null) {
            return null;
        }
        return User::findById($userId);
    }

    /**
     * Redirect to login if not authenticated. Returns current user array if logged in.
     */
    public static function requireLogin(): ?array
    {
        $user = self::getCurrentUser();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        return $user;
    }
}
