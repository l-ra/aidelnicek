<?php

declare(strict_types=1);

namespace Aidelnicek;

use RuntimeException;

/**
 * Žádost o změnu e-mailu: podepsaný odkaz + jednorázové potvrzení POSTem (GET bez vedlejších účinků).
 */
final class EmailChange
{
    private const SECRET_FILE       = 'invite_secret.key';
    private const TOKEN_VERSION   = 1;
    private const DEFAULT_VALIDITY_H = 48;

    private static function getSecretKey(): string
    {
        $dataDir    = Database::getTenantDataDir();
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
            throw new RuntimeException('Soubor s tajemstvím pro tokeny je prázdný: ' . $secretFile);
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

    public static function defaultAdminEmail(): string
    {
        $slug = TenantContext::slug();
        if ($slug === null || $slug === '') {
            return 'admin@localhost';
        }

        return 'admin@' . strtolower($slug);
    }

    /**
     * @return array{id: int, new_email: string, old_email: string, expires_at: string}|null
     */
    public static function getPendingForUser(int $userId): ?array
    {
        $db = Database::get();
        $now  = Database::sqlNow();
        $stmt = $db->prepare(
            "SELECT id, new_email, old_email, expires_at
             FROM email_change_requests
             WHERE user_id = ? AND consumed_at IS NULL AND expires_at > {$now}
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id'         => (int) $row['id'],
            'new_email'  => (string) $row['new_email'],
            'old_email'  => (string) $row['old_email'],
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    public static function cancelPendingForUser(int $userId): void
    {
        $db = Database::get();
        $db->prepare(
            'DELETE FROM email_change_requests WHERE user_id = ? AND consumed_at IS NULL'
        )->execute([$userId]);
    }

    /**
     * @return array{errors: array<string, string>, request_id?: int}
     */
    public static function startRequest(int $userId, string $currentEmailNorm, string $newEmailRaw, string $password): array
    {
        $errors = [];
        $newEmail = mb_strtolower(trim($newEmailRaw));

        if ($newEmail === '') {
            $errors['new_email'] = 'Zadejte nový e-mail.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['new_email'] = 'Neplatný formát e-mailu.';
        } elseif ($newEmail === $currentEmailNorm) {
            $errors['new_email'] = 'Nový e-mail je stejný jako současný.';
        }

        if ($newEmail !== '' && User::findByEmail($newEmail) !== null) {
            $errors['new_email'] = 'Tento e-mail již používá jiný účet.';
        }

        if ($password === '') {
            $errors['password'] = 'Pro změnu e-mailu zadejte heslo.';
        } elseif (!User::verifyCurrentPassword($userId, $password)) {
            $errors['password'] = 'Nesprávné heslo.';
        }

        if (!empty($errors)) {
            return ['errors' => $errors];
        }

        self::cancelPendingForUser($userId);

        $db = Database::get();
        $expiresUnix = time() + self::DEFAULT_VALIDITY_H * 3600;
        $expiresAt   = date('Y-m-d H:i:s', $expiresUnix);
        $stmt = $db->prepare(
            'INSERT INTO email_change_requests (user_id, new_email, old_email, expires_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $newEmail, $currentEmailNorm, $expiresAt]);
        $requestId = (int) $db->lastInsertId();

        return [
            'errors'       => [],
            'request_id'   => $requestId,
            'expires_unix' => $expiresUnix,
        ];
    }

    public static function buildSignedToken(int $requestId, int $userId, string $newEmailNorm, int $expiresUnix): string
    {
        $payload = [
            'v'     => self::TOKEN_VERSION,
            'rid'   => $requestId,
            'uid'   => $userId,
            'email' => $newEmailNorm,
            'exp'   => $expiresUnix,
            'n'     => bin2hex(random_bytes(8)),
        ];
        $payloadEncoded = self::b64uEncode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $secret         = self::getSecretKey();
        $signature      = self::b64uEncode(hash_hmac('sha256', $payloadEncoded, $secret, true));

        return $payloadEncoded . '.' . $signature;
    }

    /**
     * @return array{request_id: int, user_id: int, new_email: string}|null
     */
    public static function validateSignedToken(string $token): ?array
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
        if (!hash_equals($expectedSignature, $signatureEncoded)) {
            return null;
        }

        $payloadJson = self::b64uDecode($payloadEncoded);
        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (($payload['v'] ?? null) !== self::TOKEN_VERSION) {
            return null;
        }
        $rid   = isset($payload['rid']) ? (int) $payload['rid'] : 0;
        $uid   = isset($payload['uid']) ? (int) $payload['uid'] : 0;
        $email = isset($payload['email']) ? mb_strtolower(trim((string) $payload['email'])) : '';
        $exp   = isset($payload['exp']) ? (int) $payload['exp'] : 0;

        if ($rid <= 0 || $uid <= 0 || $email === '' || $exp <= 0 || time() > $exp) {
            return null;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return [
            'request_id' => $rid,
            'user_id'    => $uid,
            'new_email'  => $email,
        ];
    }

    /**
     * @return 'ok'|'invalid'|'expired'|'consumed'|'mismatch'|'taken'
     */
    public static function applyConfirmedChange(string $token): string
    {
        $parsed = self::validateSignedToken($token);
        if ($parsed === null) {
            return 'invalid';
        }

        $db = Database::get();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT id, user_id, new_email, old_email, consumed_at, expires_at
                 FROM email_change_requests
                 WHERE id = ?'
            );
            $stmt->execute([$parsed['request_id']]);
            $row = $stmt->fetch();
            if ($row === false) {
                $db->rollBack();

                return 'invalid';
            }
            if ($row['consumed_at'] !== null && $row['consumed_at'] !== '') {
                $db->rollBack();

                return 'consumed';
            }
            $expiresAt = strtotime((string) $row['expires_at']);
            if ($expiresAt === false || time() > $expiresAt) {
                $db->rollBack();

                return 'expired';
            }
            if ((int) $row['user_id'] !== $parsed['user_id']) {
                $db->rollBack();

                return 'mismatch';
            }
            if (mb_strtolower((string) $row['new_email']) !== $parsed['new_email']) {
                $db->rollBack();

                return 'mismatch';
            }

            $oldEmail = (string) $row['old_email'];

            $other = User::findByEmail($parsed['new_email']);
            if ($other !== null && (int) $other['id'] !== $parsed['user_id']) {
                $db->rollBack();

                return 'taken';
            }

            $upd = $db->prepare(
                'UPDATE users SET email = ? WHERE id = ? AND email = ?'
            );
            $upd->execute([
                $parsed['new_email'],
                $parsed['user_id'],
                $oldEmail,
            ]);
            if ($upd->rowCount() !== 1) {
                $db->rollBack();

                return 'mismatch';
            }

            $consumedNow = Database::sqlNow();
            $db->prepare(
                "UPDATE email_change_requests SET consumed_at = {$consumedNow} WHERE id = ? AND consumed_at IS NULL"
            )->execute([$parsed['request_id']]);

            $db->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$parsed['user_id']]);

            $db->commit();

            return 'ok';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function absoluteBaseUrl(): string
    {
        return Url::absoluteBaseUrl();
    }

    /**
     * Stav odkazu pro stránku potvrzení (GET bez vedlejších účinků).
     *
     * @return array{status: 'invalid'|'expired'|'consumed'|'mismatch'|'ok', new_email?: string}
     */
    public static function confirmLinkState(string $token): array
    {
        $parsed = self::validateSignedToken($token);
        if ($parsed === null) {
            return ['status' => 'invalid'];
        }

        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT user_id, new_email, consumed_at, expires_at FROM email_change_requests WHERE id = ?'
        );
        $stmt->execute([$parsed['request_id']]);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['status' => 'invalid'];
        }
        if ((int) $row['user_id'] !== $parsed['user_id']) {
            return ['status' => 'mismatch'];
        }
        if (mb_strtolower((string) $row['new_email']) !== $parsed['new_email']) {
            return ['status' => 'mismatch'];
        }
        if ($row['consumed_at'] !== null && $row['consumed_at'] !== '') {
            return ['status' => 'consumed'];
        }
        $expiresAt = strtotime((string) $row['expires_at']);
        if ($expiresAt === false || time() > $expiresAt) {
            return ['status' => 'expired'];
        }

        return ['status' => 'ok', 'new_email' => $parsed['new_email']];
    }
}
