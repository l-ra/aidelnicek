<?php

declare(strict_types=1);

namespace Aidelnicek;

use PDO;

class ShoppingList
{
    /**
     * Returns items for the given week, sorted by category then name.
     * $purchased: null = all, true = purchased only, false = remaining only.
     */
    public static function getItems(int $weekId, ?bool $purchased = null): array
    {
        $db  = Database::get();
        $sql = 'SELECT * FROM shopping_list_items WHERE week_id = ?';
        $params = [$weekId];

        if ($purchased !== null) {
            $sql    .= ' AND is_purchased = ?';
            $params[] = $purchased ? 1 : 0;
        }

        // NULLs (Ostatní) sorted last
        $sql .= ' ORDER BY CASE WHEN category IS NULL THEN 1 ELSE 0 END ASC, category ASC, name ASC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Auto-generates shopping list items from chosen meal plans for all users
     * in the given week.  Idempotent — skips if auto-generated rows already
     * exist unless $force = true, in which case old auto-generated rows are
     * deleted first.
     */
    public static function generateFromMealPlans(int $weekId, bool $force = false): void
    {
        $db = Database::get();

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM shopping_list_items WHERE week_id = ? AND added_manually = 0'
        );
        $stmt->execute([$weekId]);
        $existingCount = (int) $stmt->fetchColumn();

        if ($existingCount > 0) {
            if (!$force) {
                return;
            }
            $db->prepare('DELETE FROM shopping_list_items WHERE week_id = ? AND added_manually = 0')
               ->execute([$weekId]);
        }

        // For each user+day+meal_type slot: take is_chosen=1, fallback to alternative=1
        $stmt = $db->prepare(
            'SELECT mp1.*
             FROM meal_plans mp1
             WHERE mp1.week_id = ?
               AND (
                 mp1.is_chosen = 1
                 OR (
                   mp1.alternative = 1
                   AND NOT EXISTS (
                     SELECT 1 FROM meal_plans mp2
                     WHERE mp2.week_id    = mp1.week_id
                       AND mp2.user_id   = mp1.user_id
                       AND mp2.day_of_week = mp1.day_of_week
                       AND mp2.meal_type = mp1.meal_type
                       AND mp2.is_chosen = 1
                   )
                 )
               )'
        );
        $stmt->execute([$weekId]);
        $mealPlanRows = $stmt->fetchAll();

        if (empty($mealPlanRows)) {
            return;
        }

        $aggregated = self::aggregateIngredients($mealPlanRows);

        if (empty($aggregated)) {
            return;
        }

        $insertStmt = $db->prepare(
            'INSERT INTO shopping_list_items
                (week_id, name, quantity, unit, category, added_manually, added_by)
             VALUES (?, ?, ?, ?, ?, 0, NULL)'
        );

        foreach ($aggregated as $item) {
            $insertStmt->execute([
                $weekId,
                $item['name'],
                $item['quantity'],
                $item['unit'],
                $item['category'],
            ]);
        }
    }

    /**
     * Toggles is_purchased for the given item.
     * Sets purchased_by = $userId when marking purchased, NULL when unmarking.
     * Returns false if the item does not exist.
     */
    public static function togglePurchased(int $userId, int $itemId): bool
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id, is_purchased FROM shopping_list_items WHERE id = ?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();

        if ($item === false) {
            return false;
        }

        $newPurchased   = (int) $item['is_purchased'] ? 0 : 1;
        $newPurchasedBy = $newPurchased ? $userId : null;

        $db->prepare(
            'UPDATE shopping_list_items SET is_purchased = ?, purchased_by = ? WHERE id = ?'
        )->execute([$newPurchased, $newPurchasedBy, $itemId]);

        return true;
    }

    /**
     * Manually adds a new item to the list.  Returns the new row ID.
     */
    public static function addItem(
        int $userId,
        int $weekId,
        string $name,
        ?float $quantity,
        ?string $unit,
        ?string $category
    ): int {
        $db   = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO shopping_list_items
                (week_id, name, quantity, unit, category, added_manually, added_by)
             VALUES (?, ?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([
            $weekId,
            trim($name),
            $quantity,
            $unit     ?: null,
            $category ?: null,
            $userId,
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * Removes an item.  Only the item's original adder or an admin may remove it.
     * Returns false if item not found or user lacks permission.
     */
    public static function removeItem(int $userId, int $itemId): bool
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT * FROM shopping_list_items WHERE id = ?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();

        if ($item === false) {
            return false;
        }

        $addedBy = $item['added_by'] !== null ? (int) $item['added_by'] : null;
        if ($addedBy !== $userId && !User::isAdmin($userId)) {
            return false;
        }

        $db->prepare('DELETE FROM shopping_list_items WHERE id = ?')->execute([$itemId]);
        return true;
    }

    /**
     * Deletes all purchased items for the given week.
     * Returns the number of deleted rows.
     */
    public static function clearPurchased(int $weekId): int
    {
        $stmt = Database::get()->prepare(
            'DELETE FROM shopping_list_items WHERE week_id = ? AND is_purchased = 1'
        );
        $stmt->execute([$weekId]);
        return $stmt->rowCount();
    }

    /**
     * Aggregates ingredients from meal plan rows into a deduplicated list
     * ready for bulk INSERT.  Case-insensitive deduplication; repeated
     * ingredients increment the quantity counter (number of portions).
     *
     * Designed for forward-compatibility with M5: when the AI generator
     * produces structured {name, quantity, unit} data, this method can be
     * extended without changing the public interface.
     *
     * @param  array $mealPlanRows  Rows from meal_plans; each has 'ingredients' (JSON string).
     * @return array<array{name: string, quantity: float|null, unit: null, category: null}>
     */
    private static function aggregateIngredients(array $mealPlanRows): array
    {
        $aggregated = [];

        foreach ($mealPlanRows as $row) {
            $json = $row['ingredients'] ?? null;
            if (!$json) {
                continue;
            }

            $ingredients = json_decode($json, true);
            if (!is_array($ingredients)) {
                continue;
            }

            foreach ($ingredients as $ingredient) {
                if (!is_string($ingredient) || trim($ingredient) === '') {
                    continue;
                }

                $key = mb_strtolower(trim($ingredient));

                if (isset($aggregated[$key])) {
                    $aggregated[$key]['quantity'] = ($aggregated[$key]['quantity'] ?? 1) + 1;
                } else {
                    $aggregated[$key] = [
                        'name'     => trim($ingredient),
                        'quantity' => 1.0,
                        'unit'     => null,
                        'category' => null,
                    ];
                }
            }
        }

        return array_values($aggregated);
    }
}
