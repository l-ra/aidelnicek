<?php

declare(strict_types=1);

namespace Aidelnicek\Llm;

use Aidelnicek\Database;

/**
 * Zapisuje záznamy o LLM voláních: SQLite do per-denních souborů llm_YYYY-MM-DD.db,
 * PostgreSQL do partitionované tabulky llm_log v tenant schématu.
 */
class LlmLogger
{
    public function log(array $data): void
    {
        try {
            if (Database::isPostgres()) {
                $this->logPostgres($data);

                return;
            }
            $this->logSqlite($data);
        } catch (\Throwable $e) {
            error_log('LlmLogger::log failed: ' . $e->getMessage());
        }
    }

    private function logPostgres(array $data): void
    {
        $pdo = Database::get();
        Database::touchLlmLogPartitions();
        $requestAt = isset($data['request_at']) && is_string($data['request_at']) && $data['request_at'] !== ''
            ? $data['request_at']
            : (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s') . '+00';
        $stmt = $pdo->prepare(
            'INSERT INTO llm_log
                (provider, model, user_id, prompt_system, prompt_user, response_text,
                 tokens_in, tokens_out, request_at, duration_ms, status, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::timestamptz, ?, ?, ?)'
        );
        $stmt->execute([
            $data['provider']       ?? 'unknown',
            $data['model']          ?? 'unknown',
            $data['user_id']        ?? null,
            $data['prompt_system']  ?? null,
            $data['prompt_user']    ?? '',
            $data['response_text']  ?? null,
            $data['tokens_in']      ?? null,
            $data['tokens_out']     ?? null,
            $requestAt,
            $data['duration_ms']    ?? null,
            $data['status']         ?? 'ok',
            $data['error_message']  ?? null,
        ]);
    }

    private function logSqlite(array $data): void
    {
        $path = Database::getTenantDataDir() . '/llm_' . date('Y-m-d') . '.db';
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $isNew = !file_exists($path);
        $pdo   = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        if ($isNew) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS llm_log (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                provider       TEXT    NOT NULL,
                model          TEXT    NOT NULL,
                user_id        INTEGER,
                prompt_system  TEXT,
                prompt_user    TEXT    NOT NULL,
                response_text  TEXT,
                tokens_in      INTEGER,
                tokens_out     INTEGER,
                request_at     TEXT    NOT NULL,
                duration_ms    INTEGER,
                status         TEXT    NOT NULL DEFAULT 'ok',
                error_message  TEXT
            )");
        }

        $stmt = $pdo->prepare(
            'INSERT INTO llm_log
                (provider, model, user_id, prompt_system, prompt_user, response_text,
                 tokens_in, tokens_out, request_at, duration_ms, status, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['provider']      ?? 'unknown',
            $data['model']         ?? 'unknown',
            $data['user_id']       ?? null,
            $data['prompt_system'] ?? null,
            $data['prompt_user']   ?? '',
            $data['response_text'] ?? null,
            $data['tokens_in']     ?? null,
            $data['tokens_out']    ?? null,
            $data['request_at']    ?? date('Y-m-d H:i:s'),
            $data['duration_ms']   ?? null,
            $data['status']        ?? 'ok',
            $data['error_message'] ?? null,
        ]);
    }
}
