<?php

declare(strict_types=1);

namespace Aidelnicek;

class MealRecipe
{
    private const PROMPTS_DIR = __DIR__ . '/../prompts';

    private static function getRecipeProposalMealId(array $plan): int
    {
        $canonical = isset($plan['recipe_proposal_meal_id']) ? (int) $plan['recipe_proposal_meal_id'] : 0;
        if ($canonical <= 0) {
            $canonical = isset($plan['canonical_proposal_meal_id']) ? (int) $plan['canonical_proposal_meal_id'] : 0;
        }

        return $canonical > 0 ? $canonical : (int) ($plan['proposal_meal_id'] ?? 0);
    }


    public static function startOrFetchForPlan(int $userId, int $planId): ?array
    {
        $plan = self::loadPlanWithProposalLink($userId, $planId);
        if ($plan === null) {
            return null;
        }

        $proposalMealId = self::getRecipeProposalMealId($plan);
        if ($proposalMealId <= 0) {
            return ['status' => 'error', 'error' => 'Neplatný návrh jídla pro recept.'];
        }

        $stored = self::getRecipeByProposalMealId($proposalMealId);
        if ($stored !== null) {
            return self::buildReadyPayload(
                $plan,
                $proposalMealId,
                (string) $stored['recipe_text'],
                false
            );
        }

        $runningJobId = self::findRunningRecipeJob(
            $userId,
            (int) ($plan['week_id'] ?? 0),
            $proposalMealId
        );
        if ($runningJobId !== null) {
            return ['status' => 'generating', 'job_id' => $runningJobId];
        }

        $jobId = self::startRecipeGenerationJob($plan);
        if ($jobId <= 0) {
            return ['status' => 'error', 'error' => 'Generování receptu se nepodařilo spustit.'];
        }

        return ['status' => 'generating', 'job_id' => $jobId];
    }

    /**
     * Vrátí recept pro zobrazení v samostatném okně (bez spuštění generování).
     * @return array{meal_name: string, recipe_text: string}|null
     */
    public static function getRecipeForView(int $userId, int $planId): ?array
    {
        $plan = self::loadPlanWithProposalLink($userId, $planId);
        if ($plan === null) {
            return null;
        }

        $proposalMealId = self::getRecipeProposalMealId($plan);
        if ($proposalMealId <= 0) {
            return null;
        }

        $stored = self::getRecipeByProposalMealId($proposalMealId);
        if ($stored === null) {
            return null;
        }

        $mealName = (string) ($plan['meal_name'] ?? $plan['proposal_meal_name'] ?? 'Recept');
        return [
            'meal_name'   => $mealName,
            'recipe_text' => (string) $stored['recipe_text'],
        ];
    }

    /**
     * Vrátí již uložený recept pro veřejné sdílení bez vytváření nových vazeb nebo generování.
     *
     * @return array{meal_name: string, recipe_text: string}|null
     */
    public static function getRecipeForSharedView(int $userId, int $planId): ?array
    {
        $plan = MealPlan::getPlanByIdForUser($userId, $planId);
        if ($plan === null) {
            return null;
        }

        $proposalMealId = self::getRecipeProposalMealId($plan);
        if ($proposalMealId <= 0) {
            return null;
        }

        $stored = self::getRecipeByProposalMealId($proposalMealId);
        if ($stored === null) {
            return null;
        }

        return [
            'meal_name'   => (string) ($plan['meal_name'] ?? 'Recept'),
            'recipe_text' => (string) $stored['recipe_text'],
        ];
    }

    /**
     * Cesta s tenant prefixem pro návrat na denní plán podle záznamu meal_plans (den + ISO týden).
     */
    public static function planDayPathForPlanRow(array $plan): string
    {
        $day = (int) ($plan['day_of_week'] ?? 1);
        $day = max(1, min(7, $day));
        $weekId = (int) ($plan['week_id'] ?? 0);
        $week   = $weekId > 0 ? MealPlan::getWeekById($weekId) : null;

        if ($week === null) {
            return Url::u('/plan/day?day=' . $day);
        }

        return Url::u(Url::planDayPath($day, $week));
    }

    public static function planDayBackPathForPlanId(int $userId, int $planId): string
    {
        $plan = self::findPlanForUser($userId, $planId);

        return $plan !== null ? self::planDayPathForPlanRow($plan) : Url::u('/plan/day');
    }

    public static function getStatusForPlan(int $userId, int $planId, ?int $jobId = null): ?array
    {
        $plan = self::loadPlanWithProposalLink($userId, $planId);
        if ($plan === null) {
            return null;
        }

        $proposalMealId = self::getRecipeProposalMealId($plan);
        if ($proposalMealId <= 0) {
            return ['status' => 'error', 'error' => 'Neplatný návrh jídla pro recept.'];
        }

        $stored = self::getRecipeByProposalMealId($proposalMealId);
        if ($stored !== null) {
            return self::buildReadyPayload(
                $plan,
                $proposalMealId,
                (string) $stored['recipe_text'],
                false
            );
        }

        if ($jobId === null || $jobId <= 0) {
            return ['status' => 'generating'];
        }

        $job = GenerationJobService::getJob($jobId);
        if ($job === null) {
            return ['status' => 'error', 'error' => 'Generační job receptu nebyl nalezen.'];
        }

        if ((int) ($job['user_id'] ?? 0) !== $userId || (string) ($job['job_type'] ?? '') !== 'recipe_generate') {
            return ['status' => 'error', 'error' => 'Neplatný generační job receptu.'];
        }

        $payload = self::decodeInputPayload((string) ($job['input_payload'] ?? '{}'));
        $payloadProposalMealId = isset($payload['proposal_meal_id']) ? (int) $payload['proposal_meal_id'] : 0;
        if ($payloadProposalMealId > 0 && $payloadProposalMealId !== $proposalMealId) {
            return ['status' => 'error', 'error' => 'Job neodpovídá zvolenému jídlu.'];
        }

        $status = (string) ($job['status'] ?? '');
        if ($status === 'error') {
            return [
                'status' => 'error',
                'error' => (string) ($job['error_message'] ?? 'Generování receptu selhalo.'),
            ];
        }
        if ($status !== 'done') {
            return ['status' => 'generating', 'job_id' => $jobId];
        }

        $output = GenerationJobService::getOutput($jobId);
        if ($output === null) {
            return ['status' => 'generating', 'job_id' => $jobId];
        }

        $recipeText = trim((string) ($output['raw_text'] ?? ''));
        if ($recipeText === '') {
            return ['status' => 'error', 'error' => 'Vygenerovaný recept je prázdný.'];
        }

        self::storeRecipe(
            $proposalMealId,
            $userId,
            (string) ($output['model'] ?? (getenv('OPENAI_MODEL') ?: 'gpt-4o')),
            $recipeText
        );

        return self::buildReadyPayload($plan, $proposalMealId, $recipeText, true);
    }

    public static function getOrGenerateForPlan(int $userId, int $planId): ?array
    {
        $plan = self::findPlanForUser($userId, $planId);
        if ($plan === null) {
            return null;
        }

        $proposalMealId = self::ensureProposalMealLink($plan, $userId);
        if ($proposalMealId <= 0) {
            return null;
        }

        // Re-load so joins (proposal_* columns) are guaranteed to be present after legacy link creation.
        $plan = self::findPlanForUser($userId, $planId);
        if ($plan === null) {
            return null;
        }

        $stored = self::getRecipeByProposalMealId($proposalMealId);
        if ($stored !== null) {
            return [
                'recipe_text'         => $stored['recipe_text'],
                'was_generated'       => false,
                'shared_across_users' => self::countDistinctUsersForProposalMeal($proposalMealId) > 1,
                'proposal_meal_id'    => $proposalMealId,
                'portion_factor'      => isset($plan['portion_factor']) ? (float) $plan['portion_factor'] : 1.0,
            ];
        }

        $generated = self::generateRecipeViaWorker($plan);
        if ($generated === null) {
            return null;
        }

        self::storeRecipe(
            $proposalMealId,
            $userId,
            $generated['model'],
            $generated['recipe_text']
        );

        return [
            'recipe_text'         => $generated['recipe_text'],
            'was_generated'       => true,
            'shared_across_users' => self::countDistinctUsersForProposalMeal($proposalMealId) > 1,
            'proposal_meal_id'    => $proposalMealId,
            'portion_factor'      => isset($plan['portion_factor']) ? (float) $plan['portion_factor'] : 1.0,
        ];
    }

    private static function loadPlanWithProposalLink(int $userId, int $planId): ?array
    {
        $plan = self::findPlanForUser($userId, $planId);
        if ($plan === null) {
            return null;
        }

        $proposalMealId = self::ensureProposalMealLink($plan, $userId);
        if ($proposalMealId <= 0) {
            return null;
        }

        return self::findPlanForUser($userId, $planId);
    }

    private static function buildReadyPayload(
        array $plan,
        int $proposalMealId,
        string $recipeText,
        bool $wasGenerated
    ): array {
        return [
            'status'              => 'ready',
            'recipe'              => $recipeText,
            'was_generated'       => $wasGenerated,
            'shared_across_users' => self::countDistinctUsersForProposalMeal($proposalMealId) > 1,
            'proposal_meal_id'    => $proposalMealId,
            'portion_factor'      => isset($plan['portion_factor']) ? (float) $plan['portion_factor'] : 1.0,
        ];
    }

    private static function findPlanForUser(int $userId, int $planId): ?array
    {
        $stmt = Database::get()->prepare(
            'SELECT mp.*,
                    COALESCE(mp.canonical_proposal_meal_id, mp.proposal_meal_id) AS recipe_proposal_meal_id,
                    lpm.proposal_id,
                    lpm.meal_name AS proposal_meal_name,
                    lpm.description AS proposal_description,
                    lpm.ingredients AS proposal_ingredients,
                    cpm.meal_name AS canonical_meal_name,
                    cpm.description AS canonical_description,
                    cpm.ingredients AS canonical_ingredients
             FROM meal_plans mp
             LEFT JOIN llm_proposal_meals lpm ON lpm.id = mp.proposal_meal_id
             LEFT JOIN llm_proposal_meals cpm ON cpm.id = COALESCE(mp.canonical_proposal_meal_id, mp.proposal_meal_id)
             WHERE mp.id = ? AND mp.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$planId, $userId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private static function ensureProposalMealLink(array $plan, int $userId): int
    {
        $existing = self::getRecipeProposalMealId($plan);
        if ($existing > 0) {
            return $existing;
        }

        $db = Database::get();
        $db->beginTransaction();
        try {
            $proposalStmt = $db->prepare(
                'INSERT INTO llm_meal_proposals (week_id, generation_job_id, reference_user_id, llm_model)
                 VALUES (?, NULL, ?, ?)'
            );
            $proposalStmt->execute([
                (int) $plan['week_id'],
                $userId,
                'legacy-meal-link',
            ]);
            $proposalId = (int) $db->lastInsertId();

            $ingredientsJson = self::normalizeIngredientsJson((string) ($plan['ingredients'] ?? '[]'));

            $mealStmt = $db->prepare(
                'INSERT INTO llm_proposal_meals
                    (proposal_id, day_of_week, meal_type, alternative, meal_name, description, ingredients, canonical_proposal_meal_id, pairing_key)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL)'
            );
            $canonicalStmt = $db->prepare(
                'UPDATE llm_proposal_meals SET canonical_proposal_meal_id = ? WHERE id = ?'
            );
            $mealStmt->execute([
                $proposalId,
                (int) $plan['day_of_week'],
                (string) $plan['meal_type'],
                (int) $plan['alternative'],
                (string) $plan['meal_name'],
                (string) ($plan['description'] ?? ''),
                $ingredientsJson,
            ]);
            $proposalMealId = (int) $db->lastInsertId();
            $canonicalStmt->execute([$proposalMealId, $proposalMealId]);

            $updateStmt = $db->prepare(
                'UPDATE meal_plans
                 SET proposal_meal_id = ?,
                     canonical_proposal_meal_id = ?,
                     portion_factor = COALESCE(NULLIF(portion_factor, 0), 1.0)
                 WHERE id = ?'
            );
            $updateStmt->execute([$proposalMealId, $proposalMealId, (int) $plan['id']]);

            $db->commit();
            return $proposalMealId;
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('MealRecipe::ensureProposalMealLink: ' . $e->getMessage());
            return 0;
        }
    }

    private static function normalizeIngredientsJson(string $json): string
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return '[]';
            }
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return '[]';
        }
    }

    private static function getRecipeByProposalMealId(int $proposalMealId): ?array
    {
        $stmt = Database::get()->prepare(
            'SELECT recipe_text, model
             FROM meal_recipes
             WHERE proposal_meal_id = ?
             LIMIT 1'
        );
        $stmt->execute([$proposalMealId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private static function countDistinctUsersForProposalMeal(int $proposalMealId): int
    {
        $stmt = Database::get()->prepare(
            'SELECT COUNT(DISTINCT user_id)
             FROM meal_plans
             WHERE COALESCE(canonical_proposal_meal_id, proposal_meal_id) = ?'
        );
        $stmt->execute([$proposalMealId]);
        return (int) $stmt->fetchColumn();
    }

    private static function storeRecipe(
        int $proposalMealId,
        int $generatedByUserId,
        string $model,
        string $recipeText
    ): void {
        Database::get()->prepare(
            'INSERT INTO meal_recipes (proposal_meal_id, generated_by_user_id, model, recipe_text, created_at, updated_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT(proposal_meal_id)
             DO UPDATE SET
                generated_by_user_id = excluded.generated_by_user_id,
                model                = excluded.model,
                recipe_text          = excluded.recipe_text,
                updated_at           = CURRENT_TIMESTAMP'
        )->execute([$proposalMealId, $generatedByUserId, $model, $recipeText]);
    }

    private static function findRunningRecipeJob(int $userId, int $weekId, int $proposalMealId): ?int
    {
        $stmt = Database::get()->prepare(
            "SELECT id, input_payload
             FROM generation_jobs
             WHERE user_id = ? AND week_id = ? AND job_type = 'recipe_generate' AND status IN ('pending', 'running')
             ORDER BY id DESC
             LIMIT 25"
        );
        $stmt->execute([$userId, $weekId]);

        foreach ($stmt->fetchAll() as $row) {
            $payload = self::decodeInputPayload((string) ($row['input_payload'] ?? '{}'));
            if ((int) ($payload['proposal_meal_id'] ?? 0) === $proposalMealId) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    private static function startRecipeGenerationJob(array $plan): int
    {
        [$systemPrompt, $userPrompt] = self::buildRecipePrompts($plan);

        $model = getenv('OPENAI_MODEL') ?: 'gpt-4o';
        return GenerationJobService::startJob([
            'user_id' => isset($plan['user_id']) ? (int) $plan['user_id'] : 0,
            'week_id' => isset($plan['week_id']) ? (int) $plan['week_id'] : 0,
            'job_type' => 'recipe_generate',
            'mode' => 'async',
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userPrompt,
            'model' => $model,
            'temperature' => 0.3,
            'max_completion_tokens' => LlmEnv::maxCompletionTokens(),
            'input_payload' => [
                'plan_id' => isset($plan['id']) ? (int) $plan['id'] : null,
                'proposal_meal_id' => self::getRecipeProposalMealId($plan),
            ],
        ]);
    }

    private static function decodeInputPayload(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function generateRecipeViaWorker(array $plan): ?array
    {
        [$systemPrompt, $userPrompt] = self::buildRecipePrompts($plan);

        $model   = getenv('OPENAI_MODEL') ?: 'gpt-4o';
        $jobId = GenerationJobService::startJob([
            'user_id' => isset($plan['user_id']) ? (int) $plan['user_id'] : 0,
            'week_id' => isset($plan['week_id']) ? (int) $plan['week_id'] : 0,
            'job_type' => 'recipe_generate',
            'mode' => 'sync',
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userPrompt,
            'model' => $model,
            'temperature' => 0.3,
            'max_completion_tokens' => LlmEnv::maxCompletionTokens(),
            'input_payload' => [
                'plan_id' => isset($plan['id']) ? (int) $plan['id'] : null,
                'proposal_meal_id' => self::getRecipeProposalMealId($plan),
            ],
        ]);

        if ($jobId <= 0) {
            error_log('MealRecipe::generateRecipeViaWorker: job se nepodařilo spustit.');
            return null;
        }

        if (!GenerationJobService::waitForCompletion($jobId, 180, false)) {
            error_log("MealRecipe::generateRecipeViaWorker: job #{$jobId} nedokončen.");
            return null;
        }

        $output = GenerationJobService::getOutput($jobId);
        if ($output === null) {
            error_log("MealRecipe::generateRecipeViaWorker: chybí output jobu #{$jobId}.");
            return null;
        }

        $recipeText = trim((string) ($output['raw_text'] ?? ''));
        if ($recipeText === '') {
            return null;
        }

        return [
            'recipe_text' => $recipeText,
            'model'       => (string) ($output['model'] ?? $model),
        ];
    }

    private static function buildRecipePrompts(array $plan): array
    {
        $systemPrompt = @file_get_contents(self::PROMPTS_DIR . '/recipe_system.txt');
        $userTemplate = @file_get_contents(self::PROMPTS_DIR . '/meal_recipe_generate.txt');

        if ($systemPrompt === false) {
            $systemPrompt = 'Jsi zkušený kuchař. Piš jasné, stručné a praktické recepty v češtině.';
        }
        if ($userTemplate === false) {
            $userTemplate = "Připrav recept pro jídlo: {MEAL_NAME}\n"
                . "Popis: {MEAL_DESCRIPTION}\n"
                . "Ingredience:\n{INGREDIENTS}\n";
        }

        $mealName = (string) ($plan['canonical_meal_name'] ?? $plan['proposal_meal_name'] ?? $plan['meal_name'] ?? 'Jídlo');
        $mealDesc = (string) ($plan['canonical_description'] ?? $plan['proposal_description'] ?? $plan['description'] ?? '');
        $ingredientsJson = (string) ($plan['canonical_ingredients'] ?? $plan['proposal_ingredients'] ?? $plan['ingredients'] ?? '[]');

        $userPrompt = strtr($userTemplate, [
            '{MEAL_NAME}'        => $mealName,
            '{MEAL_DESCRIPTION}' => $mealDesc !== '' ? $mealDesc : 'Neuvedeno',
            '{INGREDIENTS}'      => self::formatIngredientsForPrompt($ingredientsJson),
            '{PORTION_FACTOR}'   => number_format(
                isset($plan['portion_factor']) ? (float) $plan['portion_factor'] : 1.0,
                2,
                '.',
                ''
            ),
        ]);

        return [$systemPrompt, $userPrompt];
    }

    private static function formatIngredientsForPrompt(string $ingredientsJson): string
    {
        try {
            $ingredients = json_decode($ingredientsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $ingredients = [];
        }

        if (!is_array($ingredients) || empty($ingredients)) {
            return '- bez uvedených ingrediencí';
        }

        $lines = [];
        foreach ($ingredients as $ingredient) {
            if (is_array($ingredient)) {
                $name = trim((string) ($ingredient['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $quantity = $ingredient['quantity'] ?? null;
                $unit     = trim((string) ($ingredient['unit'] ?? ''));
                $parts     = [$name];

                if (is_numeric($quantity)) {
                    $parts[] = (string) $quantity;
                }
                if ($unit !== '') {
                    $parts[] = $unit;
                }
                $lines[] = '- ' . implode(' ', $parts);
                continue;
            }

            if (is_string($ingredient) && trim($ingredient) !== '') {
                $lines[] = '- ' . trim($ingredient);
            }
        }

        return !empty($lines) ? implode("\n", $lines) : '- bez uvedených ingrediencí';
    }
}
