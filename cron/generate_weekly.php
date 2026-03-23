<?php

declare(strict_types=1);

/**
 * Cron job pro generování jídelníčků přes AI.
 *
 * Spouštěn každou neděli v 23:00 přes K8s CronJob (implementován v M7).
 * Vygeneruje jídelníčky pro všechny uživatele na nadcházející ISO týden.
 * Používá společný LLM návrh jídel (sdílený napříč uživateli) a liší pouze porce.
 * Při selhání sdíleného AI generování padne zpět na demo data.
 *
 * V multitenant režimu se stejná logika spustí pro každého tenanta (složka v data/).
 *
 * Výstup: stdout (zachytává K8s jako logy podu)
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/vendor/autoload.php';

use Aidelnicek\Database;
use Aidelnicek\MealPlan;
use Aidelnicek\MealGenerator;
use Aidelnicek\Tenant;
use Aidelnicek\TenantContext;

$tenants = Tenant::listTenantSlugs($projectRoot);
if ($tenants === []) {
    echo date('Y-m-d H:i:s') . " — Žádní tenanti v data/.\n";
    exit(0);
}

foreach ($tenants as $slug) {
    echo date('Y-m-d H:i:s') . " — Tenant {$slug}\n";

    TenantContext::initFromSlug($slug);
    Database::init($projectRoot, $slug);

    $db       = Database::get();
    $nextWeek = MealPlan::getOrCreateNextWeek();

    echo "  Týden {$nextWeek['week_number']}/{$nextWeek['year']}\n";

    $users = $db->query("SELECT id, name FROM users")->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($users)) {
        echo "  Žádní uživatelé — přeskakuji.\n";
        continue;
    }

    $weekId = (int) $nextWeek['id'];

    $existingCountStmt = $db->prepare('SELECT COUNT(*) FROM meal_plans WHERE week_id = ?');
    $existingCountStmt->execute([$weekId]);
    if ((int) $existingCountStmt->fetchColumn() > 0) {
        echo "  Týden už má vygenerovaný jídelníček — přeskakuji.\n";
        continue;
    }

    $referenceUserId = (int) $users[0]['id'];
    $jobId = MealGenerator::startSharedGenerationJob($referenceUserId, $weekId, false);

    if ($jobId > 0 && MealGenerator::waitForJob($jobId)) {
        echo "  OK: společný LLM návrh úspěšně vygenerován.\n";
        continue;
    }

    echo "  CHYBA: společné AI generování selhalo, přecházím na demo data.\n";
    foreach ($users as $user) {
        MealPlan::seedDemoWeek((int) $user['id'], $weekId);
        echo "  FALLBACK: {$user['name']} (id={$user['id']}) — použita demo data\n";
    }
}

echo date('Y-m-d H:i:s') . " — Hotovo pro " . count($tenants) . " tenant(ů).\n";
