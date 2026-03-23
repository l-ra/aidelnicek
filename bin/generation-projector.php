#!/usr/bin/env php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/vendor/autoload.php';

use Aidelnicek\Database;
use Aidelnicek\GenerationJobProjector;
use Aidelnicek\Tenant;
use Aidelnicek\TenantContext;

Tenant::migrateLegacyFlatFilesToDplusk($projectRoot);

$pollMs = max(200, (int) (getenv('PROJECTOR_POLL_INTERVAL_MS') ?: 1000));
$batchSize = max(1, (int) (getenv('PROJECTOR_BATCH_SIZE') ?: 5));
$recoverEveryLoops = max(5, (int) (getenv('PROJECTOR_RECOVER_EVERY_LOOPS') ?: 30));

$tenants = Tenant::listTenantSlugs($projectRoot);
if ($tenants === []) {
    error_log('generation-projector: žádní tenanti v data/ — ukončuji.');
    exit(0);
}

$loop = 0;
while (true) {
    $loop++;
    if ($loop % $recoverEveryLoops === 1) {
        $tenants = Tenant::listTenantSlugs($projectRoot);
    }
    $anyProcessed = false;

    foreach ($tenants as $slug) {
        TenantContext::initFromSlug($slug);
        Database::init($projectRoot, $slug);
        Database::get();

        if ($loop % $recoverEveryLoops === 0) {
            $recovered = GenerationJobProjector::recoverStaleProcessing(300);
            if ($recovered > 0) {
                error_log("generation-projector tenant={$slug}: recovered stale jobs={$recovered}");
            }
        }

        $processed = GenerationJobProjector::processPendingJobs($batchSize);
        if ($processed > 0) {
            $anyProcessed = true;
        }
    }

    if ($anyProcessed) {
        usleep(100 * 1000);
    } else {
        usleep($pollMs * 1000);
    }
}
