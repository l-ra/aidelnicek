<?php

declare(strict_types=1);

namespace Aidelnicek;

class GenerationJobService
{
    private static function workerBaseUrl(): string
    {
        return rtrim(getenv('LLM_WORKER_URL') ?: 'http://localhost:8001', '/');
    }

    /**
     * @param array{
     *   user_id:int,
     *   week_id:int,
     *   job_type:string,
     *   system_prompt:string,
     *   user_prompt:string,
     *   mode?:string,
     *   temperature?:float,
     *   max_completion_tokens?:int,
     *   model?:string,
     *   input_payload?:array<string,mixed>
     * } $job
     */
    public static function startJob(array $job): int
    {
        $payloadData = [
            'user_id'               => (int) ($job['user_id'] ?? 0),
            'week_id'               => (int) ($job['week_id'] ?? 0),
            'job_type'              => (string) ($job['job_type'] ?? 'generic_completion'),
            'mode'                  => (string) ($job['mode'] ?? 'async'),
            'system_prompt'         => (string) ($job['system_prompt'] ?? ''),
            'user_prompt'           => (string) ($job['user_prompt'] ?? ''),
            'model'                 => (string) ($job['model'] ?? (getenv('OPENAI_MODEL') ?: 'gpt-4o')),
            'temperature'           => (float) ($job['temperature'] ?? 0.7),
            'max_completion_tokens' => (int) (
                $job['max_completion_tokens'] ?? (int) (getenv('LLM_MAX_COMPLETION_TOKENS') ?: 16000)
            ),
            'input_payload'         => (array) ($job['input_payload'] ?? []),
        ];

        $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init(self::workerBaseUrl() . '/generate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $body     = curl_exec($ch);
        $errno    = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $httpCode < 200 || $httpCode >= 300) {
            $detail = $errno !== 0 ? curl_strerror($errno) : "HTTP {$httpCode}";
            error_log("GenerationJobService::startJob: LLM worker nedostupný: {$detail}");
            return 0;
        }

        try {
            $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            return (int) ($decoded['job_id'] ?? 0);
        } catch (\Throwable $e) {
            error_log('GenerationJobService::startJob: Neplatná odpověď workeru: ' . $e->getMessage());
            return 0;
        }
    }

    public static function getJob(int $jobId): ?array
    {
        $stmt = Database::get()->prepare(
            'SELECT id, user_id, week_id, job_type, mode, status, error_message,
                    projection_status, projection_error_message, input_payload,
                    created_at, started_at, finished_at
             FROM generation_jobs
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public static function getOutput(int $jobId): ?array
    {
        $stmt = Database::get()->prepare(
            'SELECT job_id, provider, model, raw_text, parsed_json, tokens_in, tokens_out, duration_ms, created_at
             FROM generation_job_outputs
             WHERE job_id = ?
             LIMIT 1'
        );
        $stmt->execute([$jobId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public static function getOutputText(int $jobId): ?string
    {
        $output = self::getOutput($jobId);
        if ($output === null) {
            return null;
        }
        $text = trim((string) ($output['raw_text'] ?? ''));
        return $text !== '' ? $text : null;
    }

    public static function waitForCompletion(int $jobId, int $maxWaitSec = 600, bool $requireProjection = false): bool
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT status, error_message, projection_status, projection_error_message
             FROM generation_jobs
             WHERE id = ?'
        );
        $deadline = time() + $maxWaitSec;

        while (time() < $deadline) {
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();

            if ($job === false) {
                error_log("GenerationJobService::waitForCompletion: job #{$jobId} nenalezen");
                return false;
            }

            if ($job['status'] === 'error') {
                error_log(
                    "GenerationJobService::waitForCompletion: job #{$jobId} selhal: "
                    . (string) ($job['error_message'] ?? '')
                );
                return false;
            }

            if ($job['status'] === 'done') {
                if (!$requireProjection) {
                    return true;
                }

                if ($job['projection_status'] === 'done') {
                    return true;
                }

                if ($job['projection_status'] === 'error') {
                    error_log(
                        "GenerationJobService::waitForCompletion: projekce jobu #{$jobId} selhala: "
                        . (string) ($job['projection_error_message'] ?? '')
                    );
                    return false;
                }

                // Opportunistic inline projection keeps sync callers deterministic.
                GenerationJobProjector::projectJob($jobId);
            }

            sleep(2);
        }

        error_log("GenerationJobService::waitForCompletion: job #{$jobId} vypršel po {$maxWaitSec}s");
        return false;
    }
}
