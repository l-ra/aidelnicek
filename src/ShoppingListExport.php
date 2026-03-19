<?php

declare(strict_types=1);

namespace Aidelnicek;

/**
 * Export nákupního seznamu do CSV/JSON a generování podepsaných odkazů.
 *
 * Podepsaný odkaz používá stejné tajemství jako pozvánky (data/invite_secret.key).
 * Token má formát: base64url(payload).base64url(HMAC-SHA256)
 * Payload: week_id, expires, nonce
 */
class ShoppingListExport
{
    private const SECRET_FILE        = 'invite_secret.key';
    private const DEFAULT_VALIDITY_H = 168; // 7 dní

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

    /**
     * Vygeneruje podepsaný token pro export nákupního seznamu daného týdne.
     *
     * @param int $validityHours Platnost tokenu v hodinách (výchozí: 168 = 7 dní)
     */
    public static function generateExportToken(int $weekId, int $validityHours = self::DEFAULT_VALIDITY_H): string
    {
        $payload = [
            'week_id' => $weekId,
            'expires' => time() + ($validityHours * 3600),
            'nonce'   => bin2hex(random_bytes(8)),
        ];

        $payloadEncoded = self::b64uEncode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $secret         = self::getSecretKey();
        $signature      = self::b64uEncode(hash_hmac('sha256', $payloadEncoded, $secret, true));

        return $payloadEncoded . '.' . $signature;
    }

    /**
     * Ověří token a vrátí week_id, nebo null při neplatném/prošlém tokenu.
     *
     * @return array{week_id: int}|null
     */
    public static function validateExportToken(string $token): ?array
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

        if (!isset($payload['week_id'], $payload['expires'], $payload['nonce'])) {
            return null;
        }

        if (time() > (int) $payload['expires']) {
            return null;
        }

        $weekId = (int) $payload['week_id'];
        if ($weekId <= 0) {
            return null;
        }

        return ['week_id' => $weekId];
    }

    /**
     * Vrátí URL pro stažení přes podepsaný odkaz.
     */
    public static function getSignedExportUrl(
        int $weekId,
        string $format = 'csv',
        int $validityHours = self::DEFAULT_VALIDITY_H
    ): string {
        $token = self::generateExportToken($weekId, $validityHours);
        $format = in_array($format, ['csv', 'json'], true) ? $format : 'csv';
        return '/shopping/export?token=' . urlencode($token) . '&format=' . $format;
    }

    /**
     * Vrátí data pro export (název, množství, jednotka).
     *
     * @return array<array{nazev: string, mnozstvi: float|null, jednotka: string|null}>
     */
    public static function getExportData(int $weekId): array
    {
        $items = ShoppingList::getItems($weekId);
        $result = [];

        foreach ($items as $row) {
            $quantity = isset($row['quantity']) && $row['quantity'] !== null && $row['quantity'] !== ''
                ? (float) $row['quantity']
                : null;
            $unit = isset($row['unit']) && $row['unit'] !== null && $row['unit'] !== ''
                ? (string) trim($row['unit'])
                : null;

            $result[] = [
                'nazev'    => trim((string) ($row['name'] ?? '')),
                'mnozstvi' => $quantity,
                'jednotka' => $unit,
            ];
        }

        return $result;
    }

    public static function formatCsv(array $items): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Nelze vytvořit dočasný stream pro CSV.');
        }

        // BOM pro Excel v UTF-8
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['nazev', 'mnozstvi', 'jednotka'], ';');

        foreach ($items as $item) {
            $qty = $item['mnozstvi'] !== null ? (string) $item['mnozstvi'] : '';
            $unit = $item['jednotka'] ?? '';
            fputcsv($out, [$item['nazev'], $qty, $unit], ';');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    public static function formatJson(array $items): string
    {
        return json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

}
