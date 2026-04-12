<?php

declare(strict_types=1);

namespace Aidelnicek;

/**
 * Podepsané veřejné odkazy pro denní/týdenní jídelníček a recepty.
 *
 * Token má formát: base64url(payload).base64url(HMAC-SHA256)
 */
final class PlanShare
{
    private const SECRET_FILE        = 'invite_secret.key';
    private const DEFAULT_VALIDITY_H = 168; // 7 dní

    public static function getDefaultValidityHours(): int
    {
        return self::DEFAULT_VALIDITY_H;
    }

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
            throw new \RuntimeException('Soubor s tajemstvím pro sdílení je prázdný: ' . $secretFile);
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

    /**
     * @param array<string, int|string> $payload
     */
    private static function generateToken(array $payload): string
    {
        $payloadEncoded = self::b64uEncode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $signature      = self::b64uEncode(hash_hmac('sha256', $payloadEncoded, self::getSecretKey(), true));

        return $payloadEncoded . '.' . $signature;
    }

    private static function resolveExpires(int $validityHours, ?int $expiresAt): int
    {
        if ($expiresAt !== null && $expiresAt > 0) {
            return $expiresAt;
        }

        return time() + ($validityHours * 3600);
    }

    public static function getSignedPlanUrl(
        int $userId,
        int $weekId,
        ?int $day = null,
        int $validityHours = self::DEFAULT_VALIDITY_H,
        ?int $expiresAt = null
    ): string {
        $payload = [
            'scope'   => $day === null ? 'week' : 'day',
            'user_id' => $userId,
            'week_id' => $weekId,
            'expires' => self::resolveExpires($validityHours, $expiresAt),
            'nonce'   => bin2hex(random_bytes(8)),
        ];

        if ($day !== null) {
            $payload['day'] = max(1, min(7, $day));
        }

        $token = self::generateToken($payload);

        return Url::u('/share/plan?token=' . urlencode($token));
    }

    public static function getSignedRecipeUrl(
        int $userId,
        int $planId,
        int $weekId,
        int $day,
        int $validityHours = self::DEFAULT_VALIDITY_H,
        ?int $expiresAt = null
    ): string {
        $token = self::generateToken([
            'scope'   => 'recipe',
            'user_id' => $userId,
            'plan_id' => $planId,
            'week_id' => $weekId,
            'day'     => max(1, min(7, $day)),
            'expires' => self::resolveExpires($validityHours, $expiresAt),
            'nonce'   => bin2hex(random_bytes(8)),
        ]);

        return Url::u('/share/recipe?token=' . urlencode($token));
    }

    public static function toAbsoluteUrl(string $path): string
    {
        return Url::absoluteBaseUrl() . $path;
    }

    /**
     * @return array{
     *   scope: string,
     *   user_id: int,
     *   expires: int,
     *   week_id?: int,
     *   day?: int,
     *   plan_id?: int
     * }|null
     */
    public static function validateShareToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadEncoded, $signatureEncoded] = $parts;

        $expectedSignature = self::b64uEncode(hash_hmac('sha256', $payloadEncoded, self::getSecretKey(), true));
        if (!hash_equals($expectedSignature, $signatureEncoded)) {
            return null;
        }

        $payloadJson = self::b64uDecode($payloadEncoded);
        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($payload) || !isset($payload['scope'], $payload['user_id'], $payload['expires'], $payload['nonce'])) {
            return null;
        }

        $scope   = (string) $payload['scope'];
        $userId  = (int) $payload['user_id'];
        $expires = (int) $payload['expires'];

        if (!in_array($scope, ['week', 'day', 'recipe'], true) || $userId <= 0 || $expires <= 0 || time() > $expires) {
            return null;
        }

        $result = [
            'scope'   => $scope,
            'user_id' => $userId,
            'expires' => $expires,
        ];

        if ($scope === 'week' || $scope === 'day' || $scope === 'recipe') {
            $weekId = (int) ($payload['week_id'] ?? 0);
            if ($weekId <= 0) {
                return null;
            }
            $result['week_id'] = $weekId;
        }

        if ($scope === 'day' || $scope === 'recipe') {
            $day = (int) ($payload['day'] ?? 0);
            if ($day < 1 || $day > 7) {
                return null;
            }
            $result['day'] = $day;
        }

        if ($scope === 'recipe') {
            $planId = (int) ($payload['plan_id'] ?? 0);
            if ($planId <= 0) {
                return null;
            }
            $result['plan_id'] = $planId;
        }

        return $result;
    }
}
