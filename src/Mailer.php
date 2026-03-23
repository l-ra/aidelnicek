<?php

declare(strict_types=1);

namespace Aidelnicek;

use RuntimeException;

/**
 * Odesílání e-mailů přes SMTP podle proměnných MAILER_HOST, MAILER_PORT, MAILER_LOGIN, MAILER_PASSWORD.
 */
final class Mailer
{
    private const REQUIRED_ENV = ['MAILER_HOST', 'MAILER_PORT', 'MAILER_LOGIN', 'MAILER_PASSWORD'];

    public static function isConfigured(): bool
    {
        foreach (self::REQUIRED_ENV as $key) {
            if (self::envTrimmed($key) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{configured: bool, missing: list<string>, host: ?string, port: ?int}
     */
    public static function getAdminStatus(): array
    {
        $missing = [];
        foreach (self::REQUIRED_ENV as $key) {
            if (self::envTrimmed($key) === '') {
                $missing[] = $key;
            }
        }
        $host = self::envTrimmed('MAILER_HOST');
        $portStr = self::envTrimmed('MAILER_PORT');
        $port = $portStr !== '' ? (int) $portStr : null;

        return [
            'configured' => $missing === [],
            'missing' => $missing,
            'host' => $host !== '' ? $host : null,
            'port' => $port,
        ];
    }

    /**
     * Odešle jednoduchou zprávu text/plain v UTF-8.
     *
     * @throws RuntimeException při chybě SMTP nebo neplatné konfiguraci
     */
    public static function sendPlain(string $to, string $subject, string $body, ?string $fromAddress = null): void
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('E-mail není nakonfigurován (chybí proměnné prostředí).');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Neplatná cílová e-mailová adresa.');
        }

        $host = self::envTrimmed('MAILER_HOST');
        $port = (int) self::envTrimmed('MAILER_PORT');
        $login = self::envTrimmed('MAILER_LOGIN');
        $password = (string) (getenv('MAILER_PASSWORD') ?: '');

        if ($fromAddress === null || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $fromAddress = filter_var($login, FILTER_VALIDATE_EMAIL) ? $login : $to;
        }

        self::smtpSend($host, $port, $login, $password, $fromAddress, $to, $subject, $body);
    }

    private static function envTrimmed(string $name): string
    {
        $v = getenv($name);
        if ($v === false) {
            return '';
        }

        return trim((string) $v);
    }

    private static function smtpSend(
        string $host,
        int $port,
        string $login,
        string $password,
        string $from,
        string $to,
        string $subject,
        string $body
    ): void {
        $useImplicitTls = $port === 465;
        $remote = $useImplicitTls
            ? 'ssl://' . $host . ':' . $port
            : 'tcp://' . $host . ':' . $port;

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $fp = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($fp === false) {
            throw new RuntimeException('Nepodařilo se připojit k SMTP serveru: ' . $errstr . ' (' . $errno . ')');
        }

        stream_set_timeout($fp, 30);

        try {
            self::expectSmtpCode($fp, [220]);
            self::smtpLine($fp, 'EHLO aidelnicek');
            $ehloLines = self::readSmtpMultiline($fp);
            self::assertSmtpCode($ehloLines, [250]);

            if (!$useImplicitTls && self::ehloSupportsStartTls($ehloLines)) {
                self::smtpLine($fp, 'STARTTLS');
                self::expectSmtpCode($fp, [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS selhalo.');
                }
                self::smtpLine($fp, 'EHLO aidelnicek');
                $ehloLines = self::readSmtpMultiline($fp);
                self::assertSmtpCode($ehloLines, [250]);
            }

            if (self::ehloSupportsAuthLogin($ehloLines) && $login !== '' && $password !== '') {
                self::smtpLine($fp, 'AUTH LOGIN');
                self::expectSmtpCode($fp, [334]);
                self::smtpLine($fp, base64_encode($login));
                self::expectSmtpCode($fp, [334]);
                self::smtpLine($fp, base64_encode($password));
                self::expectSmtpCode($fp, [235]);
            }

            self::smtpLine($fp, 'MAIL FROM:<' . self::sanitizeAddr($from) . '>');
            self::expectSmtpCode($fp, [250]);
            self::smtpLine($fp, 'RCPT TO:<' . self::sanitizeAddr($to) . '>');
            self::expectSmtpCode($fp, [250, 251]);
            self::smtpLine($fp, 'DATA');
            self::expectSmtpCode($fp, [354]);

            $encodedSubject = self::encodeSubject($subject);
            $headers = [
                'From: ' . self::formatAddress($from),
                'To: ' . self::formatAddress($to),
                'Subject: ' . $encodedSubject,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            $data = implode("\r\n", $headers) . "\r\n\r\n" . self::dotStuff($body) . "\r\n.";
            fwrite($fp, $data . "\r\n");
            self::expectSmtpCode($fp, [250]);

            self::smtpLine($fp, 'QUIT');
            self::expectSmtpCode($fp, [221]);
        } finally {
            fclose($fp);
        }
    }

    private static function sanitizeAddr(string $email): string
    {
        return str_replace(["\r", "\n", '<', '>'], '', $email);
    }

    private static function formatAddress(string $email): string
    {
        return '<' . self::sanitizeAddr($email) . '>';
    }

    private static function encodeSubject(string $subject): string
    {
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $subject)) {
            $subject = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $subject) ?? '';
        }

        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    private static function dotStuff(string $body): string
    {
        $normalized = str_replace("\r\n", "\n", $body);
        $normalized = str_replace("\r", "\n", $normalized);
        $lines = explode("\n", $normalized);
        $out = [];
        foreach ($lines as $line) {
            if (isset($line[0]) && $line[0] === '.') {
                $out[] = '.' . $line;
            } else {
                $out[] = $line;
            }
        }

        return implode("\r\n", $out);
    }

    /**
     * @param list<string> $lines
     */
    private static function ehloSupportsStartTls(array $lines): bool
    {
        foreach ($lines as $raw) {
            $line = rtrim($raw, "\r\n");
            if (preg_match('/^250[ -](.+)$/i', $line, $m) && preg_match('/\bSTARTTLS\b/i', $m[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $lines
     */
    private static function ehloSupportsAuthLogin(array $lines): bool
    {
        foreach ($lines as $raw) {
            $line = rtrim($raw, "\r\n");
            if (!preg_match('/^250[ -](.+)$/i', $line, $m)) {
                continue;
            }
            if (preg_match('/\bAUTH\b/i', $m[1]) && preg_match('/\bLOGIN\b/i', $m[1])) {
                return true;
            }
        }

        return false;
    }

    private static function smtpLine($fp, string $line): void
    {
        fwrite($fp, $line . "\r\n");
    }

    /**
     * @return list<string>
     */
    private static function readSmtpMultiline($fp): array
    {
        $lines = [];
        while (($line = fgets($fp, 2048)) !== false) {
            $lines[] = $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        if ($lines === []) {
            throw new RuntimeException('SMTP server neodpověděl.');
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     * @param list<int> $codes
     */
    private static function assertSmtpCode(array $lines, array $codes): void
    {
        $first = $lines[0] ?? '';
        $code = (int) substr($first, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP neočekávaná odpověď: ' . trim(implode('', $lines)));
        }
    }

    /**
     * @param list<int> $codes
     */
    private static function expectSmtpCode($fp, array $codes): void
    {
        self::assertSmtpCode(self::readSmtpMultiline($fp), $codes);
    }
}
