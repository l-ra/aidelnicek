<?php

declare(strict_types=1);

namespace Aidelnicek;

use PDO;

class GenerationJobProjector
{
    private const MEAL_TYPES = ['breakfast', 'snack_am', 'lunch', 'snack_pm', 'dinner'];

    public static function processPendingJobs(int $limit = 5): int
    {
        $stmt = Database::get()->prepare(
            "SELECT id
             FROM generation_jobs
             WHERE status = 'done' AND projection_status = 'pending'
             ORDER BY finished_at ASC, id ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $processed = 0;
        foreach ($ids as $id) {
            if (self::projectJob((int) $id)) {
                $processed++;
            }
        }

        return $processed;
    }

    public static function recoverStaleProcessing(int $maxAgeSec = 300): int
    {
        $stmt = Database::get()->prepare(
            "UPDATE generation_jobs
             SET projection_status = 'pending',
                 projection_error_message = 'Projection lease expired; retrying.',
                 projection_started_at = NULL
             WHERE status = 'done'
               AND projection_status = 'processing'
               AND projection_started_at IS NOT NULL
               AND projection_started_at < datetime('now', ?)"
        );
        $stmt->execute(['-' . max(60, $maxAgeSec) . ' seconds']);
        return $stmt->rowCount();
    }

    public static function projectJob(int $jobId): bool
    {
        if (!self::claimForProjection($jobId)) {
            $job = GenerationJobService::getJob($jobId);
            return $job !== null && $job['projection_status'] === 'done';
        }

        try {
            $job = GenerationJobService::getJob($jobId);
            $output = GenerationJobService::getOutput($jobId);
            if ($job === null) {
                throw new \RuntimeException("Job #{$jobId} nenalezen.");
            }
            if ($output === null) {
                throw new \RuntimeException("Job #{$jobId} nemá uložený output.");
            }

            $jobType = (string) ($job['job_type'] ?? '');
            if ($jobType === 'mealplan_generate') {
                self::projectMealPlanJob($job, $output);
            }

            self::markProjectionDone($jobId);
            return true;
        } catch (\Throwable $e) {
            self::markProjectionError($jobId, $e->getMessage());
            error_log("GenerationJobProjector::projectJob #{$jobId}: " . $e->getMessage());
            return false;
        }
    }

    private static function claimForProjection(int $jobId): bool
    {
        $stmt = Database::get()->prepare(
            "UPDATE generation_jobs
             SET projection_status = 'processing',
                 projection_started_at = CURRENT_TIMESTAMP,
                 projection_error_message = NULL
             WHERE id = ?
               AND status = 'done'
               AND projection_status = 'pending'"
        );
        $stmt->execute([$jobId]);
        return $stmt->rowCount() > 0;
    }

    private static function markProjectionDone(int $jobId): void
    {
        Database::get()->prepare(
            "UPDATE generation_jobs
             SET projection_status = 'done',
                 projection_finished_at = CURRENT_TIMESTAMP,
                 projection_error_message = NULL
             WHERE id = ?"
        )->execute([$jobId]);
    }

    private static function markProjectionError(int $jobId, string $message): void
    {
        Database::get()->prepare(
            "UPDATE generation_jobs
             SET projection_status = 'error',
                 projection_finished_at = CURRENT_TIMESTAMP,
                 projection_error_message = ?
             WHERE id = ?"
        )->execute([mb_substr($message, 0, 2000), $jobId]);
    }

    private static function projectMealPlanJob(array $job, array $output): void
    {
        $days = self::extractDaysFromOutput((string) ($output['raw_text'] ?? ''));

        $payload = [];
        try {
            $rawPayload = (string) ($job['input_payload'] ?? '{}');
            $decoded = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        } catch (\Throwable $e) {
            $payload = [];
        }

        $weekId = (int) ($job['week_id'] ?? 0);
        if ($weekId <= 0) {
            throw new \RuntimeException('Job nemá platný week_id.');
        }

        $referenceUserId = (int) ($payload['reference_user_id'] ?? $job['user_id'] ?? 0);
        if ($referenceUserId <= 0) {
            throw new \RuntimeException('Job nemá platného referenčního uživatele.');
        }

        $force = (bool) ($payload['force'] ?? false);
        $profiles = $payload['shared_user_profiles'] ?? [];
        if (!is_array($profiles) || empty($profiles)) {
            $profiles = [[
                'user_id' => (int) ($job['user_id'] ?? 0),
                'portion_factor' => 1.0,
            ]];
        }

        $db = Database::get();
        $db->beginTransaction();
        try {
            $proposalStmt = $db->prepare(
                'INSERT INTO llm_meal_proposals (week_id, generation_job_id, reference_user_id, llm_model)
                 VALUES (?, ?, ?, ?)'
            );
            $proposalStmt->execute([
                $weekId,
                (int) $job['id'],
                $referenceUserId,
                (string) ($output['model'] ?? ''),
            ]);
            $proposalId = (int) $db->lastInsertId();

            $proposalMeals = self::insertProposalMeals($db, $proposalId, $days);
            if (empty($proposalMeals)) {
                throw new \RuntimeException('Výstup neobsahuje žádná validní jídla.');
            }

            foreach ($profiles as $profile) {
                if (!is_array($profile)) {
                    continue;
                }

                $targetUserId = isset($profile['user_id']) ? (int) $profile['user_id'] : 0;
                if ($targetUserId <= 0) {
                    continue;
                }

                $portionFactor = isset($profile['portion_factor']) ? (float) $profile['portion_factor'] : 1.0;
                if ($portionFactor <= 0) {
                    $portionFactor = 1.0;
                }

                self::seedMealPlansForUser(
                    $db,
                    $targetUserId,
                    $weekId,
                    $proposalMeals,
                    $force,
                    $portionFactor
                );
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, array{
     *     proposal_meal_id:int,
     *     canonical_proposal_meal_id:int,
     *     pairing_key:?string,
     *     meal_name:string,
     *     description:string,
     *     ingredients:array<int,mixed>,
     *     day:int,
     *     meal_type:string,
     *     alternative:int
     * }>
     */
    private static function insertProposalMeals(PDO $db, int $proposalId, array $days): array
    {
        $proposalMeals = self::buildProposalMealEntries($days);
        if (empty($proposalMeals)) {
            return [];
        }

        $insertStmt = $db->prepare(
            'INSERT INTO llm_proposal_meals
                (proposal_id, day_of_week, meal_type, alternative, meal_name, description, ingredients, canonical_proposal_meal_id, pairing_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL)'
        );
        $updateStmt = $db->prepare(
            'UPDATE llm_proposal_meals
             SET canonical_proposal_meal_id = ?, pairing_key = ?
             WHERE id = ?'
        );

        ksort($proposalMeals);
        foreach ($proposalMeals as $key => $mealEntry) {
            $insertStmt->execute([
                $proposalId,
                $mealEntry['day'],
                $mealEntry['meal_type'],
                $mealEntry['alternative'],
                $mealEntry['meal_name'],
                $mealEntry['description'],
                json_encode($mealEntry['ingredients'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $proposalMealId = (int) $db->lastInsertId();

            $proposalMeals[$key]['proposal_meal_id'] = $proposalMealId;
            $proposalMeals[$key]['canonical_proposal_meal_id'] = $proposalMealId;
            $proposalMeals[$key]['pairing_key'] = null;
        }

        foreach ($proposalMeals as $key => $mealEntry) {
            $canonicalLookupKey = $mealEntry['canonical_lookup_key'] ?? null;
            if (!is_string($canonicalLookupKey) || $canonicalLookupKey === '' || !isset($proposalMeals[$canonicalLookupKey])) {
                continue;
            }

            $canonicalEntry = $proposalMeals[$canonicalLookupKey];
            $pairingKey = 'carryover:' . $proposalId . ':' . $canonicalLookupKey;

            $proposalMeals[$canonicalLookupKey]['canonical_proposal_meal_id'] =
                (int) ($proposalMeals[$canonicalLookupKey]['proposal_meal_id'] ?? 0);
            $proposalMeals[$canonicalLookupKey]['pairing_key'] = $pairingKey;
            $proposalMeals[$key]['canonical_proposal_meal_id'] = (int) ($canonicalEntry['proposal_meal_id'] ?? 0);
            $proposalMeals[$key]['pairing_key'] = $pairingKey;
        }

        foreach ($proposalMeals as $key => $mealEntry) {
            $canonicalProposalMealId = (int) ($mealEntry['canonical_proposal_meal_id'] ?? 0);
            if ($canonicalProposalMealId <= 0) {
                $canonicalProposalMealId = (int) $mealEntry['proposal_meal_id'];
                $proposalMeals[$key]['canonical_proposal_meal_id'] = $canonicalProposalMealId;
            }

            $updateStmt->execute([
                $canonicalProposalMealId,
                $mealEntry['pairing_key'],
                (int) $mealEntry['proposal_meal_id'],
            ]);

            unset(
                $proposalMeals[$key]['canonical_lookup_key'],
                $proposalMeals[$key]['same_meal_as_previous_dinner']
            );
        }

        return $proposalMeals;
    }

    /**
     * @return array<string, array{
     *     proposal_meal_id:int,
     *     canonical_proposal_meal_id:int,
     *     pairing_key:?string,
     *     meal_name:string,
     *     description:string,
     *     ingredients:array<int,mixed>,
     *     day:int,
     *     meal_type:string,
     *     alternative:int,
     *     same_meal_as_previous_dinner:bool,
     *     canonical_lookup_key:?string
     * }>
     */
    private static function buildProposalMealEntries(array $days): array
    {
        $proposalMeals = [];

        foreach ($days as $dayData) {
            if (!is_array($dayData)) {
                continue;
            }
            $dayNum = (int) ($dayData['day'] ?? 0);
            if ($dayNum < 1 || $dayNum > 7) {
                continue;
            }

            $meals = $dayData['meals'] ?? [];
            if (!is_array($meals)) {
                continue;
            }

            foreach (self::MEAL_TYPES as $mealType) {
                $slot = $meals[$mealType] ?? [];
                if (!is_array($slot)) {
                    continue;
                }

                foreach (['alt1' => 1, 'alt2' => 2] as $altKey => $altNum) {
                    $alt = $slot[$altKey] ?? null;
                    if (!is_array($alt)) {
                        continue;
                    }

                    $mealName = trim((string) ($alt['name'] ?? ''));
                    $description = (string) ($alt['description'] ?? '');
                    $ingredients = $alt['ingredients'] ?? [];
                    if (!is_array($ingredients)) {
                        $ingredients = [];
                    }

                    $key = "{$dayNum}|{$mealType}|{$altNum}";
                    $canonicalLookupKey = null;
                    $sameMealAsPreviousDinner = false;

                    if (
                        $mealType === 'lunch'
                        && $dayNum > 1
                        && self::isEnabledCarryoverFlag($alt['same_meal_as_previous_dinner'] ?? false)
                    ) {
                        $candidateLookupKey = ($dayNum - 1) . '|dinner|' . $altNum;
                        if (isset($proposalMeals[$candidateLookupKey])) {
                            $canonicalLookupKey = $candidateLookupKey;
                            $sameMealAsPreviousDinner = true;
                            $mealName = $proposalMeals[$candidateLookupKey]['meal_name'];
                            $description = $proposalMeals[$candidateLookupKey]['description'];
                        }
                    }

                    if ($mealName === '') {
                        continue;
                    }

                    $proposalMeals[$key] = [
                        'proposal_meal_id' => 0,
                        'canonical_proposal_meal_id' => 0,
                        'pairing_key' => null,
                        'meal_name' => $mealName,
                        'description' => $description,
                        'ingredients' => $ingredients,
                        'day' => $dayNum,
                        'meal_type' => $mealType,
                        'alternative' => $altNum,
                        'same_meal_as_previous_dinner' => $sameMealAsPreviousDinner,
                        'canonical_lookup_key' => $canonicalLookupKey,
                    ];
                }
            }
        }

        return $proposalMeals;
    }

    /**
     * @param mixed $value
     */
    private static function isEnabledCarryoverFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'ano'], true);
    }

    /**
     * @param array<string, array{
     *     proposal_meal_id:int,
     *     canonical_proposal_meal_id:int,
     *     pairing_key:?string,
     *     meal_name:string,
     *     description:string,
     *     ingredients:array<int,mixed>,
     *     day:int,
     *     meal_type:string,
     *     alternative:int
     * }> $proposalMeals
     */
    private static function seedMealPlansForUser(
        PDO $db,
        int $userId,
        int $weekId,
        array $proposalMeals,
        bool $force,
        float $portionFactor
    ): void {
        if ($force) {
            $db->prepare('DELETE FROM meal_plans WHERE user_id = ? AND week_id = ?')->execute([$userId, $weekId]);
        }

        $insertStmt = $db->prepare(
            'INSERT OR IGNORE INTO meal_plans
                (user_id, week_id, day_of_week, meal_type, alternative, meal_name, description, ingredients, proposal_meal_id, canonical_proposal_meal_id, pairing_key, portion_factor, is_chosen)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        ksort($proposalMeals);
        foreach ($proposalMeals as $mealEntry) {
            $ingredients = self::scaleIngredients($mealEntry['ingredients'], $portionFactor);
            $ingredientsJson = json_encode($ingredients, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $insertStmt->execute([
                $userId,
                $weekId,
                $mealEntry['day'],
                $mealEntry['meal_type'],
                $mealEntry['alternative'],
                $mealEntry['meal_name'],
                $mealEntry['description'],
                $ingredientsJson,
                $mealEntry['proposal_meal_id'],
                $mealEntry['canonical_proposal_meal_id'],
                $mealEntry['pairing_key'],
                $portionFactor,
                ((int) $mealEntry['alternative'] === 1) ? 1 : 0,
            ]);

            if ($insertStmt->rowCount() > 0) {
                MealHistory::recordOffer($userId, $mealEntry['meal_name']);
            }
        }
    }

    /**
     * @param array<int,mixed> $ingredients
     * @return array<int,mixed>
     */
    private static function scaleIngredients(array $ingredients, float $portionFactor): array
    {
        if (abs($portionFactor - 1.0) < 0.0001) {
            return $ingredients;
        }

        $scaled = [];
        foreach ($ingredients as $ingredient) {
            if (!is_array($ingredient)) {
                $scaled[] = $ingredient;
                continue;
            }

            $entry = $ingredient;
            $quantity = $entry['quantity'] ?? null;
            if (is_numeric($quantity)) {
                $scaledQty = max(0.1, round(((float) $quantity) * $portionFactor, 1));
                $entry['quantity'] = ((float) ((int) $scaledQty) === (float) $scaledQty)
                    ? (int) $scaledQty
                    : $scaledQty;
            }
            $scaled[] = $entry;
        }

        return $scaled;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function extractDaysFromOutput(string $text): array
    {
        $clean = trim($text);

        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```[a-z]*\n?/i', '', $clean) ?? $clean;
            $clean = preg_replace('/```\s*$/', '', $clean) ?? $clean;
            $clean = trim($clean);
        }

        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new \RuntimeException('LLM output neobsahuje validní JSON objekt.');
        }

        $json = substr($clean, $start, $end - $start + 1);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('LLM output JSON není objekt.');
        }

        $days = $decoded['days'] ?? null;
        if (!is_array($days)) {
            throw new \RuntimeException('LLM output neobsahuje pole days.');
        }

        if (count($days) < 7) {
            throw new \RuntimeException('LLM output obsahuje méně než 7 dní.');
        }

        return $days;
    }
}
