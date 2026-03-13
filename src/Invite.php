<?php

declare(strict_types=1);

namespace Aidelnicek;

/**
 * Správa pozvánek pro registraci nových uživatelů.
 *
 * Token je self-contained JWT-like řetězec:
 *   base64url(JSON payload) . "." . base64url(HMAC-SHA256 podpis)
 *
 * Payload obsahuje: email, expires (Unix timestamp), nonce (náhodný hex řetězec).
 * Tajemství pro podpis se generuje automaticky a ukládá do data/invite_secret.key.
 */
class Invite
{
    private const SECRET_FILE         = 'invite_secret.key';
    private const DEFAULT_VALIDITY_H  = 168; // 7 dní

    // ── Interní pomocné metody ────────────────────────────────────────────────

    private static function getSecretKey(): string
    {
        $dataDir    = dirname(__DIR__) . '/data';
        $secretFile = $dataDir . '/' . self::SECRET_FILE;

        if (!file_exists($secretFile)) {
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }
            $secret = bin2hex(random_bytes(32));
            file_put_contents($secretFile, $secret, LOCK_EX);
            chmod($secretFile, 0600);
        }

        $secret = trim((string) file_get_contents($secretFile));
        if ($secret === '') {
            throw new \RuntimeException('Soubor s tajemstvím pro pozvánky je prázdný: ' . $secretFile);
        }

        return $secret;
    }

    private static function b64uEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $len    = strlen($padded) % 4;
        if ($len > 0) {
            $padded .= str_repeat('=', 4 - $len);
        }
        return (string) base64_decode($padded, true);
    }

    // ── Veřejné API ───────────────────────────────────────────────────────────

    /**
     * Vygeneruje podepsaný token pozvánky pro daný e-mail.
     *
     * @param int $validityHours  Platnost tokenu v hodinách (výchozí: 168 = 7 dní)
     */
    public static function generateToken(string $email, int $validityHours = self::DEFAULT_VALIDITY_H): string
    {
        $payload = [
            'email'   => $email,
            'expires' => time() + ($validityHours * 3600),
            'nonce'   => bin2hex(random_bytes(8)),
        ];

        $payloadEncoded = self::b64uEncode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $secret         = self::getSecretKey();
        $signature      = self::b64uEncode(hash_hmac('sha256', $payloadEncoded, $secret, true));

        return $payloadEncoded . '.' . $signature;
    }

    /**
     * Ověří token a vrátí obsah payloadu, nebo null při neplatném/prošlém tokenu.
     *
     * @return array{email: string, expires: int, nonce: string}|null
     */
    public static function validateToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadEncoded, $signatureEncoded] = $parts;

        $secret            = self::getSecretKey();
        $expectedSignature = self::b64uEncode(hash_hmac('sha256', $payloadEncoded, $secret, true));

        // Constant-time comparison to prevent timing-based attacks
        if (!hash_equals($expectedSignature, $signatureEncoded)) {
            return null;
        }

        $payloadJson = self::b64uDecode($payloadEncoded);

        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!isset($payload['email'], $payload['expires'], $payload['nonce'])) {
            return null;
        }

        if (time() > (int) $payload['expires']) {
            return null;
        }

        return [
            'email'   => (string) $payload['email'],
            'expires' => (int)    $payload['expires'],
            'nonce'   => (string) $payload['nonce'],
        ];
    }

    /**
     * Vrátí plnou URL registrační pozvánky (absolutní cesta, bez hostname).
     */
    public static function getInviteUrl(string $email, int $validityHours = self::DEFAULT_VALIDITY_H): string
    {
        return '/register?invite=' . urlencode(self::generateToken($email, $validityHours));
    }
}
