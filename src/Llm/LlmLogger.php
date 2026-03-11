<?php

declare(strict_types=1);

namespace Aidelnicek\Llm;

/**
 * Zapisuje záznamy o LLM voláních do per-denních SQLite souborů.
 * Soubory jsou vytvářeny automaticky ve složce data/ s názvem llm_YYYY-MM-DD.db.
 */
class LlmLogger
{
    private function getDbPath(): string
    {
        return dirname(__DIR__, 2) . '/data/llm_' . date('Y-m-d') . '.db';
    }

    private function getConnection(): \PDO
    {
        $path = $this->getDbPath();
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $isNew = !file_exists($path);
        $pdo   = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        if ($isNew) {
            $this->initSchema($pdo);
        }

        return $pdo;
    }

    private function initSchema(\PDO $pdo): void
    {
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

    /**
     * Zapíše záznam komunikace s LLM do dnešního log souboru.
     * Selhání logování nezastaví aplikaci — chyba se tichce zaznamená přes error_log().
     */
    public function log(array $data): void
    {
        try {
            $pdo  = $this->getConnection();
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
        } catch (\Throwable $e) {
            error_log('LlmLogger::log failed: ' . $e->getMessage());
        }
    }
}
