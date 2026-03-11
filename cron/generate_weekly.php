<?php

declare(strict_types=1);

/**
 * Cron job pro generování jídelníčků přes AI.
 *
 * Spouštěn každou neděli v 23:00 přes K8s CronJob (implementován v M7).
 * Vygeneruje jídelníčky pro všechny uživatele na nadcházející ISO týden.
 * Při selhání AI generování pro konkrétního uživatele padne zpět na demo data.
 *
 * Výstup: stdout (zachytává K8s jako logy podu)
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/vendor/autoload.php';

use Aidelnicek\Database;
use Aidelnicek\MealPlan;
use Aidelnicek\MealGenerator;

Database::init($projectRoot);

$db       = Database::get();
$nextWeek = MealPlan::getOrCreateNextWeek();

echo date('Y-m-d H:i:s') . " — Spouštím generování pro týden "
    . "{$nextWeek['week_number']}/{$nextWeek['year']}\n";

$users = $db->query("SELECT id, name FROM users")->fetchAll(\PDO::FETCH_ASSOC);

if (empty($users)) {
    echo "Žádní uživatelé nenalezeni.\n";
    exit(0);
}

$ok       = 0;
$fallback = 0;
$skipped  = 0;

foreach ($users as $user) {
    $check = $db->prepare('SELECT COUNT(*) FROM meal_plans WHERE user_id = ? AND week_id = ?');
    $check->execute([(int) $user['id'], (int) $nextWeek['id']]);

    if ((int) $check->fetchColumn() > 0) {
        $skipped++;
        continue;
    }

    $success = MealGenerator::generateWeek((int) $user['id'], (int) $nextWeek['id']);

    if ($success) {
        echo "  OK:       {$user['name']} (id={$user['id']})\n";
        $ok++;
    } else {
        echo "  FALLBACK: {$user['name']} (id={$user['id']}) — použita demo data\n";
        $fallback++;
    }
}

echo date('Y-m-d H:i:s') . " — Hotovo: {$ok} OK, {$fallback} fallback, {$skipped} přeskočeno\n";
