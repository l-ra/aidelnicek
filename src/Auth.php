<?php

declare(strict_types=1);

namespace Aidelnicek;

class Auth
{
    private const SESSION_USER_ID = 'user_id';
    private const SESSION_LAST_ACTIVITY = 'last_activity';
    private const SESSION_TIMEOUT = 86400; // 24 hodin
    private const REMEMBER_COOKIE = 'remember_token';
    private const REMEMBER_DAYS = 30;
    private const REMEMBER_EXPIRY = 2592000; // 30 dní v sekundách

    /**
     * Nastaví session cookie path a název session podle tenanta (před session_start).
     */
    public static function configureTenantSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        $slug = TenantContext::slug();
        if ($slug === null || $slug === '') {
            return;
        }

        $path = '/' . $slug . '/';
        session_name('ASID_' . hash('crc32b', $slug));

        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $path,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function cookiePath(): string
    {
        $slug = TenantContext::slug();
        if ($slug === null || $slug === '') {
            return '/';
        }

        return '/' . $slug . '/';
    }

    public static function init(): void
    {
        self::configureTenantSession();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        self::checkRememberMe();
        self::checkSessionTimeout();
    }

    private static function checkSessionTimeout(): void
    {
        if (!isset($_SESSION[self::SESSION_USER_ID])) {
            return;
        }
        $now = time();
        $lastActivity = $_SESSION[self::SESSION_LAST_ACTIVITY] ?? $now;
        if ($now - $lastActivity > self::SESSION_TIMEOUT) {
            self::logout();
            return;
        }
        $_SESSION[self::SESSION_LAST_ACTIVITY] = $now;
    }

    private static function checkRememberMe(): void
    {
        if (isset($_SESSION[self::SESSION_USER_ID])) {
            return;
        }
        $token = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if ($token === null || $token === '') {
            return;
        }
        $userId = self::validateRememberToken($token);
        if ($userId !== null) {
            self::login($userId);
        } else {
            self::clearRememberCookie();
        }
    }

    private static function validateRememberToken(string $token): ?int
    {
        $db = Database::get();
        $hash = hash('sha256', $token);
        $stmt = $db->prepare(
            'SELECT user_id FROM remember_tokens WHERE token_hash = ? AND expires_at > datetime("now")'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ? (int) $row['user_id'] : null;
    }

    private static function clearRememberCookie(): void
    {
        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            setcookie(self::REMEMBER_COOKIE, '', time() - 3600, self::cookiePath(), '', true, true);
        }
    }

    public static function login(int $userId, bool $rememberMe = false): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION[self::SESSION_USER_ID] = $userId;
        $_SESSION[self::SESSION_LAST_ACTIVITY] = time();

        if ($rememberMe) {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $expires = date('Y-m-d H:i:s', time() + self::REMEMBER_EXPIRY);

            $db = Database::get();
            $stmt = $db->prepare('INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $hash, $expires]);

            setcookie(
                self::REMEMBER_COOKIE,
                $token,
                time() + self::REMEMBER_EXPIRY,
                self::cookiePath(),
                '',
                true,
                true
            );
        }

        $user = User::findById($userId);
        if ($user !== null && !empty($user['is_admin'])) {
            Database::removeInitialAdminPasswordFileIfPresent();
        }
    }

    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $userId = $_SESSION[self::SESSION_USER_ID] ?? null;

        if ($userId !== null) {
            $db = Database::get();
            $stmt = $db->prepare('DELETE FROM remember_tokens WHERE user_id = ?');
            $stmt->execute([$userId]);
        }
        unset($_SESSION[self::SESSION_USER_ID], $_SESSION[self::SESSION_LAST_ACTIVITY]);
        self::clearRememberCookie();
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
            header('Location: ' . Url::u('/login'));
            exit;
        }
        return $user;
    }
}
