<?php

declare(strict_types=1);

namespace Aidelnicek;

class MealRecipe
{
    private const PROMPTS_DIR = __DIR__ . '/../prompts';

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

    private static function findPlanForUser(int $userId, int $planId): ?array
    {
        $stmt = Database::get()->prepare(
            'SELECT mp.*,
                    lpm.proposal_id,
                    lpm.meal_name AS proposal_meal_name,
                    lpm.description AS proposal_description,
                    lpm.ingredients AS proposal_ingredients
             FROM meal_plans mp
             LEFT JOIN llm_proposal_meals lpm ON lpm.id = mp.proposal_meal_id
             WHERE mp.id = ? AND mp.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$planId, $userId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private static function ensureProposalMealLink(array $plan, int $userId): int
    {
        $existing = isset($plan['proposal_meal_id']) ? (int) $plan['proposal_meal_id'] : 0;
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
                    (proposal_id, day_of_week, meal_type, alternative, meal_name, description, ingredients)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
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

            $updateStmt = $db->prepare(
                'UPDATE meal_plans
                 SET proposal_meal_id = ?, portion_factor = COALESCE(NULLIF(portion_factor, 0), 1.0)
                 WHERE id = ?'
            );
            $updateStmt->execute([$proposalMealId, (int) $plan['id']]);

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
             WHERE proposal_meal_id = ?'
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
            'max_completion_tokens' => 2200,
            'input_payload' => [
                'plan_id' => isset($plan['id']) ? (int) $plan['id'] : null,
                'proposal_meal_id' => isset($plan['proposal_meal_id']) ? (int) $plan['proposal_meal_id'] : null,
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

        $mealName = (string) ($plan['proposal_meal_name'] ?? $plan['meal_name'] ?? 'Jídlo');
        $mealDesc = (string) ($plan['proposal_description'] ?? $plan['description'] ?? '');
        $ingredientsJson = (string) ($plan['proposal_ingredients'] ?? $plan['ingredients'] ?? '[]');

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
