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
    private const MIN_PORTION_FACTOR = 0.60;
    private const MAX_PORTION_FACTOR = 1.80;

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

        return self::submitGenerationJob(
            $userId,
            $weekId,
            $systemPrompt,
            $userPrompt,
            $force
        );
    }

    /**
     * Spustí společné LLM generování pro všechny uživatele:
     * - model vygeneruje jednu sadu jídel (podle referenčního uživatele),
     * - pro každého uživatele se upraví pouze velikost porce (quantity ingrediencí).
     *
     * @param int $referenceUserId Uživatel, podle kterého se vybere složení jídel
     * @param bool $force          true = worker přepíše existující plány uživatele+týden
     * @return int                 job_id (> 0) při úspěchu, 0 při chybě
     */
    public static function startSharedGenerationJob(int $referenceUserId, int $weekId, bool $force = false): int
    {
        try {
            [$systemPrompt, $userPrompt] = self::getPromptsForWeek($referenceUserId, $weekId);
            $profiles = self::buildSharedUserPortionProfiles($referenceUserId);
        } catch (\Throwable $e) {
            error_log(
                "MealGenerator::startSharedGenerationJob ref_user={$referenceUserId} week={$weekId}: "
                . $e->getMessage()
            );
            return 0;
        }

        if (empty($profiles)) {
            error_log('MealGenerator::startSharedGenerationJob: Žádné profily uživatelů pro společné generování.');
            return 0;
        }

        return self::submitGenerationJob(
            $referenceUserId,
            $weekId,
            $systemPrompt,
            $userPrompt,
            $force,
            $profiles
        );
    }

    /**
     * Odešle požadavek na LLM worker a vrátí job_id.
     *
     * @param array<int, array{user_id: int, portion_factor: float}> $sharedProfiles
     */
    private static function submitGenerationJob(
        int $userId,
        int $weekId,
        string $systemPrompt,
        string $userPrompt,
        bool $force = false,
        array $sharedProfiles = []
    ): int {
        $payloadData = [
            'user_id'               => $userId,
            'week_id'               => $weekId,
            'system_prompt'         => $systemPrompt,
            'user_prompt'           => $userPrompt,
            'model'                 => getenv('OPENAI_MODEL') ?: 'gpt-4o',
            'temperature'           => 0.8,
            'max_completion_tokens' => LlmEnv::maxCompletionTokens(),
            'force'                 => $force,
        ];

        if (!empty($sharedProfiles)) {
            $payloadData['shared_user_profiles'] = $sharedProfiles;
        }
        return GenerationJobService::startJob([
            'user_id' => $userId,
            'week_id' => $weekId,
            'job_type' => 'mealplan_generate',
            'mode' => 'async',
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userPrompt,
            'model' => (string) $payloadData['model'],
            'temperature' => (float) $payloadData['temperature'],
            'max_completion_tokens' => (int) $payloadData['max_completion_tokens'],
            'input_payload' => [
                'force' => (bool) $force,
                'reference_user_id' => $userId,
                'shared_user_profiles' => $sharedProfiles,
            ],
        ]);
    }

    /**
     * @return array<int, array{user_id: int, portion_factor: float}>
     */
    private static function buildSharedUserPortionProfiles(int $referenceUserId): array
    {
        $users = Database::get()->query(
            'SELECT id, gender, age, height, weight, body_type, diet_goal
             FROM users
             ORDER BY id ASC'
        )->fetchAll();

        if (empty($users)) {
            throw new \RuntimeException('V databázi nejsou žádní uživatelé.');
        }

        $referenceUser = null;
        foreach ($users as $u) {
            if ((int) $u['id'] === $referenceUserId) {
                $referenceUser = $u;
                break;
            }
        }

        if ($referenceUser === null) {
            throw new \RuntimeException("Referenční uživatel #{$referenceUserId} nebyl nalezen.");
        }

        $referenceCalories = self::estimateDailyCalories($referenceUser);
        if ($referenceCalories <= 0) {
            throw new \RuntimeException('Nelze vypočítat porce pro referenčního uživatele.');
        }

        $profiles = [];
        foreach ($users as $u) {
            $targetCalories = self::estimateDailyCalories($u);
            $ratio          = $targetCalories / $referenceCalories;
            $ratio          = max(self::MIN_PORTION_FACTOR, min(self::MAX_PORTION_FACTOR, $ratio));

            $profiles[] = [
                'user_id'        => (int) $u['id'],
                'portion_factor' => round($ratio, 2),
            ];
        }

        return $profiles;
    }

    private static function estimateDailyCalories(array $user): float
    {
        $gender = strtolower((string) ($user['gender'] ?? ''));
        $age    = isset($user['age']) ? (int) $user['age'] : 0;
        $height = isset($user['height']) ? (int) $user['height'] : 0;
        $weight = isset($user['weight']) ? (float) $user['weight'] : 0.0;

        $baseByGender = match ($gender) {
            'male'   => 2200.0,
            'female' => 1800.0,
            default  => 2000.0,
        };

        if ($age > 0 && $height > 0 && $weight > 0) {
            $genderConstant = match ($gender) {
                'male'   => 5.0,
                'female' => -161.0,
                default  => -78.0, // střed mezi male/female pro "other/unknown"
            };
            $bmr          = (10.0 * $weight) + (6.25 * $height) - (5.0 * $age) + $genderConstant;
            $activity     = self::activityMultiplier((string) ($user['body_type'] ?? ''));
            $goalModifier = self::goalMultiplier((string) ($user['diet_goal'] ?? ''));
            $calories     = $bmr * $activity * $goalModifier;
            return max(1200.0, min(4200.0, $calories));
        }

        return max(1200.0, min(4200.0, $baseByGender * self::goalMultiplier((string) ($user['diet_goal'] ?? ''))));
    }

    private static function activityMultiplier(string $bodyType): float
    {
        return match (strtolower($bodyType)) {
            'slim'      => 1.45,
            'athletic'  => 1.55,
            'average'   => 1.40,
            'large'     => 1.30,
            'overweight'=> 1.30,
            default     => 1.35,
        };
    }

    private static function goalMultiplier(string $dietGoal): float
    {
        $goal = mb_strtolower($dietGoal);
        if ($goal === '') {
            return 1.0;
        }

        $reduceKeywords = ['hubn', 'redukc', 'deficit', 'zhubn'];
        foreach ($reduceKeywords as $keyword) {
            if (str_contains($goal, $keyword)) {
                return 0.85;
            }
        }

        $gainKeywords = ['nabrat', 'přibrat', 'pribrat', 'objem', 'gain'];
        foreach ($gainKeywords as $keyword) {
            if (str_contains($goal, $keyword)) {
                return 1.15;
            }
        }

        return 1.0;
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
        return GenerationJobService::waitForCompletion($jobId, $maxWaitSec, true);
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
