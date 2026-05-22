<?php

namespace Aidelnicek;

class MealHistory
{
    public static function recordOffer(int $userId, string $mealName): void
    {
        self::upsert($userId, $mealName, 'times_offered');
    }

    public static function recordChoice(int $userId, string $mealName): void
    {
        self::upsert($userId, $mealName, 'times_chosen');
    }

    public static function recordEaten(int $userId, string $mealName): void
    {
        self::upsert($userId, $mealName, 'times_eaten');
    }

    public static function getUserHistory(int $userId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT * FROM meal_history WHERE user_id = ? ORDER BY times_chosen DESC, meal_name ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    private static function upsert(int $userId, string $mealName, string $column): void
    {
        $allowed = ['times_offered', 'times_chosen', 'times_eaten'];
        if (!in_array($column, $allowed, true)) {
            return;
        }

        if (Database::isPostgres()) {
            // PostgreSQL: nequalifikovaný sloupec v ON CONFLICT DO UPDATE je nejednoznačný
            // (existující řádek vs. EXCLUDED). meal_history = řádek v tabulce, EXCLUDED = hodnoty z INSERT.
            $incrementExpr = "meal_history.{$column} + EXCLUDED.{$column}";
            $lastOfferedElse = 'meal_history.last_offered';
        } else {
            $incrementExpr = "{$column} + 1";
            $lastOfferedElse = 'last_offered';
        }

        Database::get()->prepare(
            "INSERT INTO meal_history (user_id, meal_name, {$column}, last_offered)
             VALUES (?, ?, 1, CURRENT_TIMESTAMP)
             ON CONFLICT(user_id, meal_name) DO UPDATE SET
                {$column} = {$incrementExpr},
                last_offered = CASE WHEN '{$column}' = 'times_offered' THEN CURRENT_TIMESTAMP ELSE {$lastOfferedElse} END"
        )->execute([$userId, $mealName]);
    }
}
