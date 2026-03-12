<?php

declare(strict_types=1);

namespace Aidelnicek;

use Aidelnicek\Llm\LlmFactory;

/**
 * Generuje týdenní jídelníčky pomocí LLM (OpenAI).
 *
 * Ingredience jsou ukládány jako JSON pole objektů {name, quantity, unit}.
 * Šablony promptů se načítají ze složky prompts/ v kořenu projektu.
 */
class MealGenerator
{
    private const MEAL_TYPES  = ['breakfast', 'snack_am', 'lunch', 'snack_pm', 'dinner'];
    private const PROMPTS_DIR = __DIR__ . '/../prompts';

    /**
     * Vygeneruje týdenní jídelníček pro daného uživatele přes LLM.
     *
     * @param bool $force  true = smaže existující plány uživatele+týden a přegeneruje
     * @return bool         true = LLM úspěch, false = fallback na demo data
     */
    public static function generateWeek(int $userId, int $weekId, bool $force = false): bool
    {
        if (!$force && MealPlan::hasPlansForWeek($userId, $weekId)) {
            return true;
        }

        if ($force) {
            Database::get()->prepare(
                'DELETE FROM meal_plans WHERE user_id = ? AND week_id = ?'
            )->execute([$userId, $weekId]);
        }

        try {
            $user = User::findById($userId);
            if ($user === null) {
                throw new \RuntimeException("Uživatel #{$userId} nenalezen.");
            }

            $week = MealPlan::getWeekById($weekId);
            if ($week === null) {
                throw new \RuntimeException("Týden #{$weekId} nenalezen.");
            }

            [$systemPrompt, $userPrompt] = self::buildPrompts(
                $user,
                (int) $week['week_number'],
                (int) $week['year']
            );

            $llm     = LlmFactory::create();
            $options = ['user_id' => $userId, 'temperature' => 0.8, 'max_tokens' => 4096];

            $response = $llm->complete($systemPrompt, $userPrompt, $options);

            try {
                $days = self::parseResponse($response);
            } catch (\InvalidArgumentException $e) {
                $correctionPrompt = "Předchozí odpověď nebyla validní JSON nebo měla chybnou strukturu.\n"
                    . "Vrať VÝHRADNĚ platný JSON objekt bez markdown bloků. Začni přímo znakem {";
                $response2 = $llm->complete($systemPrompt, $correctionPrompt, $options);
                $days      = self::parseResponse($response2);
            }

            self::seedFromLlm($userId, $weekId, $days);
            return true;
        } catch (\Throwable $e) {
            error_log("MealGenerator::generateWeek user={$userId} week={$weekId}: " . $e->getMessage());
            return false;
        }
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
     * Zparsuje JSON odpověď LLM do strukturovaného pole dní.
     *
     * @throws \InvalidArgumentException při neplatném JSON nebo chybějící/neúplné struktuře
     */
    private static function parseResponse(string $response): array
    {
        $clean = trim($response);

        // Odstraní případné markdown code bloky (```json ... ```)
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```[a-z]*\n?/i', '', $clean);
            $clean = preg_replace('/```\s*$/', '', $clean);
            $clean = trim($clean);
        }

        // Ořízne na první { a poslední }
        $start = strpos($clean, '{');
        $end   = strrpos($clean, '}');
        if ($start === false || $end === false) {
            throw new \InvalidArgumentException('Odpověď neobsahuje JSON objekt.');
        }
        $clean = substr($clean, $start, $end - $start + 1);

        try {
            $data = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('JSON parse chyba: ' . $e->getMessage());
        }

        if (!isset($data['days']) || !is_array($data['days'])) {
            throw new \InvalidArgumentException('Chybí klíč "days" v JSON odpovědi.');
        }

        if (count($data['days']) < 7) {
            throw new \InvalidArgumentException(
                'Odpověď obsahuje pouze ' . count($data['days']) . ' dní místo 7.'
            );
        }

        return $data['days'];
    }

    /**
     * Vloží zparsovaná LLM data do tabulky meal_plans.
     * Volá MealHistory::recordOffer() pro každé vložené jídlo.
     */
    private static function seedFromLlm(int $userId, int $weekId, array $days): void
    {
        $db   = Database::get();
        $stmt = $db->prepare(
            'INSERT OR IGNORE INTO meal_plans
                (user_id, week_id, day_of_week, meal_type, alternative, meal_name, description, ingredients)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($days as $dayData) {
            $dayNum = (int) ($dayData['day'] ?? 0);
            if ($dayNum < 1 || $dayNum > 7) {
                continue;
            }

            $meals = $dayData['meals'] ?? [];

            foreach (self::MEAL_TYPES as $mealType) {
                $slot = $meals[$mealType] ?? [];

                foreach (['alt1' => 1, 'alt2' => 2] as $key => $altNum) {
                    $alt = $slot[$key] ?? null;
                    if (empty($alt['name'])) {
                        continue;
                    }

                    $ingredientsJson = json_encode(
                        $alt['ingredients'] ?? [],
                        JSON_UNESCAPED_UNICODE
                    );

                    $stmt->execute([
                        $userId,
                        $weekId,
                        $dayNum,
                        $mealType,
                        $altNum,
                        (string) $alt['name'],
                        (string) ($alt['description'] ?? ''),
                        $ingredientsJson,
                    ]);

                    MealHistory::recordOffer($userId, (string) $alt['name']);
                }
            }
        }
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
