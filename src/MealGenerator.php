<?php

declare(strict_types=1);

namespace Aidelnicek;

/**
 * Generuje týdenní jídelníčky pomocí LLM (OpenAI) prostřednictvím Python LLM workeru.
 *
 * Veškerá komunikace s LLM probíhá přes Python FastAPI sidecar (port 8001).
 * PHP sestaví prompty a předá je workeru, který asynchronně volá OpenAI API.
 * Metoda generateWeek() spustí job a polluje výsledek (pro cron job kompatibilitu).
 * Metoda startGenerationJob() spustí job bez čekání (pro user-facing UI).
 *
 * Ingredience jsou ukládány jako JSON pole objektů {name, quantity, unit}.
 * Šablony promptů se načítají ze složky prompts/ v kořenu projektu.
 */
class MealGenerator
{
    private const PROMPTS_DIR = __DIR__ . '/../prompts';

    /**
     * Vrátí URL Python LLM workeru z env proměnné LLM_WORKER_URL (výchozí: http://localhost:8001).
     */
    private static function workerBaseUrl(): string
    {
        return rtrim(getenv('LLM_WORKER_URL') ?: 'http://localhost:8001', '/');
    }

    /**
     * Spustí generování jídelníčku přes LLM worker a vrátí job_id.
     * Volající je zodpovědný za polling výsledku (nebo ignorování — fire-and-forget).
     *
     * @param bool $force  true = worker přepíše existující plány uživatele+týden
     * @return int          job_id (> 0) při úspěchu, 0 při chybě
     */
    public static function startGenerationJob(int $userId, int $weekId, bool $force = false): int
    {
        try {
            [$systemPrompt, $userPrompt] = self::getPromptsForWeek($userId, $weekId);
        } catch (\Throwable $e) {
            error_log("MealGenerator::startGenerationJob user={$userId} week={$weekId}: " . $e->getMessage());
            return 0;
        }

        $model     = getenv('OPENAI_MODEL') ?: 'gpt-4o';
        $maxTokens = (int) (getenv('LLM_MAX_COMPLETION_TOKENS') ?: 16000);
        $payload   = json_encode([
            'user_id'               => $userId,
            'week_id'               => $weekId,
            'system_prompt'         => $systemPrompt,
            'user_prompt'           => $userPrompt,
            'model'                 => $model,
            'temperature'           => 0.8,
            'max_completion_tokens' => $maxTokens,
            'force'                 => $force,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init(self::workerBaseUrl() . '/generate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $body     = curl_exec($ch);
        $errno    = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $httpCode < 200 || $httpCode >= 300) {
            $detail = $errno !== 0 ? curl_strerror($errno) : "HTTP {$httpCode}";
            error_log("MealGenerator::startGenerationJob: LLM worker nedostupný: {$detail}");
            return 0;
        }

        try {
            $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            $jobId   = (int) ($decoded['job_id'] ?? 0);
        } catch (\Throwable $e) {
            error_log("MealGenerator::startGenerationJob: Neplatná odpověď workeru: " . $e->getMessage());
            return 0;
        }

        return $jobId;
    }

    /**
     * Vygeneruje týdenní jídelníček pro daného uživatele přes LLM worker.
     * Spustí job a polluje výsledek (max. 10 minut) — vhodné pro cron joby.
     *
     * @param bool $force  true = přegeneruje i existující plány
     * @return bool         true = generování úspěšné, false = selhalo (fallback na demo data)
     */
    public static function generateWeek(int $userId, int $weekId, bool $force = false): bool
    {
        if (!$force && MealPlan::hasPlansForWeek($userId, $weekId)) {
            return true;
        }

        $jobId = self::startGenerationJob($userId, $weekId, $force);
        if ($jobId <= 0) {
            return false;
        }

        return self::waitForJob($jobId);
    }

    /**
     * Polluje stav jobu v DB, dokud není dokončen nebo nenastane timeout.
     *
     * @param int $maxWaitSec  Maximální čekací doba v sekundách (výchozí: 600)
     */
    public static function waitForJob(int $jobId, int $maxWaitSec = 600): bool
    {
        $db       = Database::get();
        $stmt     = $db->prepare('SELECT status, error_message FROM generation_jobs WHERE id = ?');
        $deadline = time() + $maxWaitSec;

        while (time() < $deadline) {
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();

            if ($job === false) {
                error_log("MealGenerator::waitForJob: job #{$jobId} nenalezen");
                return false;
            }

            if ($job['status'] === 'done') {
                return true;
            }

            if ($job['status'] === 'error') {
                error_log("MealGenerator::waitForJob: job #{$jobId} selhal: " . ($job['error_message'] ?? ''));
                return false;
            }

            sleep(2);
        }

        error_log("MealGenerator::waitForJob: job #{$jobId} vypršel po {$maxWaitSec}s");
        return false;
    }

    /**
     * Vrátí [systemPrompt, userPrompt] sestavené pro daného uživatele a týden.
     * Používá PHP route /admin/llm-generate před předáním dat Python workeru.
     *
     * @return array{0: string, 1: string}
     * @throws \RuntimeException pokud uživatel nebo týden neexistuje, nebo nelze načíst šablony
     */
    public static function getPromptsForWeek(int $userId, int $weekId): array
    {
        $user = User::findById($userId);
        if ($user === null) {
            throw new \RuntimeException("Uživatel #{$userId} nenalezen.");
        }

        $week = MealPlan::getWeekById($weekId);
        if ($week === null) {
            throw new \RuntimeException("Týden #{$weekId} nenalezen.");
        }

        return self::buildPrompts($user, (int) $week['week_number'], (int) $week['year']);
    }

    /**
     * Sestaví systémový a uživatelský prompt ze šablon.
     *
     * @return array{0: string, 1: string}  [systemPrompt, userPrompt]
     * @throws \RuntimeException pokud nelze načíst šablony
     */
    private static function buildPrompts(array $user, int $weekNumber, int $year): array
    {
        $dir = self::PROMPTS_DIR;

        $systemPrompt = @file_get_contents("{$dir}/system.txt");
        $userTemplate = @file_get_contents("{$dir}/meal_plan_generate.txt");

        if ($systemPrompt === false || $userTemplate === false) {
            throw new \RuntimeException('Nelze načíst šablony promptů z ' . $dir);
        }

        $preferences  = self::getPreferences((int) $user['id']);
        $historyBlock = '';
        $totalHistory = count($preferences['liked']) + count($preferences['disliked']);

        if ($totalHistory >= 5) {
            $historyTemplate = @file_get_contents("{$dir}/meal_plan_history.txt");
            if ($historyTemplate !== false) {
                $liked    = implode(', ', $preferences['liked'])    ?: '(žádná data)';
                $disliked = implode(', ', $preferences['disliked']) ?: '(žádná data)';
                $historyBlock = "\n" . strtr($historyTemplate, [
                    '{LIKED_MEALS}'    => $liked,
                    '{DISLIKED_MEALS}' => $disliked,
                ]);
            }
        }

        $genderMap = ['male' => 'muž', 'female' => 'žena', 'other' => 'jiné'];
        $gender    = $genderMap[$user['gender'] ?? ''] ?? ($user['gender'] ?: 'neuvedeno');

        $bodyTypeMap = [
            'slim'       => 'štíhlá',
            'athletic'   => 'sportovní',
            'average'    => 'průměrná',
            'overweight' => 'nadváha',
        ];
        $bodyType = $bodyTypeMap[$user['body_type'] ?? ''] ?? ($user['body_type'] ?: 'neuvedena');

        $height = isset($user['height']) && $user['height'] !== null && $user['height'] !== ''
            ? (int) $user['height'] . ' cm'
            : 'neuvedena';
        $weight = isset($user['weight']) && $user['weight'] !== null && $user['weight'] !== ''
            ? number_format((float) $user['weight'], 1, '.', '') . ' kg'
            : 'neuvedena';

        $userPrompt = strtr($userTemplate, [
            '{USER_NAME}'     => $user['name']          ?? 'Uživatel',
            '{GENDER}'        => $gender,
            '{AGE}'           => $user['age']            ?? 'neuvedeno',
            '{HEIGHT}'        => $height,
            '{WEIGHT}'        => $weight,
            '{BODY_TYPE}'     => $bodyType,
            '{DIETARY_NOTES}' => $user['dietary_notes'] ?: 'žádná omezení',
            '{DIET_GOAL}'     => $user['diet_goal']     ?: 'neuvedeno',
            '{WEEK_NUMBER}'   => (string) $weekNumber,
            '{YEAR}'          => (string) $year,
            '{HISTORY_BLOCK}' => $historyBlock,
            '{JSON_SCHEMA}'   => self::getJsonSchema(),
        ]);

        return [$systemPrompt, $userPrompt];
    }

    /**
     * Načte preference uživatele z meal_history.
     *
     * Liked:    times_eaten / times_offered >= 0.6  AND times_offered >= 3
     * Disliked: times_eaten / times_offered <= 0.2  AND times_offered >= 3
     *
     * @return array{liked: string[], disliked: string[]}
     */
    private static function getPreferences(int $userId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT meal_name, times_offered, times_eaten
             FROM meal_history
             WHERE user_id = ? AND times_offered >= 3'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        $liked    = [];
        $disliked = [];

        foreach ($rows as $row) {
            $offered = (int) $row['times_offered'];
            $eaten   = (int) $row['times_eaten'];

            if ($offered === 0) {
                continue;
            }

            $ratio = $eaten / $offered;
            if ($ratio >= 0.6) {
                $liked[] = $row['meal_name'];
            } elseif ($ratio <= 0.2) {
                $disliked[] = $row['meal_name'];
            }
        }

        return ['liked' => $liked, 'disliked' => $disliked];
    }

    /**
     * Vrátí inline JSON schéma výstupního formátu pro dosazení do {JSON_SCHEMA}.
     */
    private static function getJsonSchema(): string
    {
        return <<<'JSON'
{
  "days": [
    {
      "day": 1,
      "meals": {
        "breakfast": {
          "alt1": {"name": "Název jídla", "description": "Krátký popis", "ingredients": [{"name": "ingredience", "quantity": 100, "unit": "g"}]},
          "alt2": {"name": "Název jídla", "description": "Krátký popis", "ingredients": [...]}
        },
        "snack_am": {"alt1": {...}, "alt2": {...}},
        "lunch":    {"alt1": {...}, "alt2": {...}},
        "snack_pm": {"alt1": {...}, "alt2": {...}},
        "dinner":   {"alt1": {...}, "alt2": {...}}
      }
    },
    {"day": 2, "meals": {...}},
    {"day": 3, "meals": {...}},
    {"day": 4, "meals": {...}},
    {"day": 5, "meals": {...}},
    {"day": 6, "meals": {...}},
    {"day": 7, "meals": {...}}
  ]
}
JSON;
    }
}
