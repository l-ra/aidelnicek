#!/usr/bin/env php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/vendor/autoload.php';

use Aidelnicek\Database;
use Aidelnicek\GenerationJobProjector;

Database::init($projectRoot);
Database::get();

$pollMs = max(200, (int) (getenv('PROJECTOR_POLL_INTERVAL_MS') ?: 1000));
$batchSize = max(1, (int) (getenv('PROJECTOR_BATCH_SIZE') ?: 5));
$recoverEveryLoops = max(5, (int) (getenv('PROJECTOR_RECOVER_EVERY_LOOPS') ?: 30));

$loop = 0;
while (true) {
    $loop++;

    if ($loop % $recoverEveryLoops === 0) {
        $recovered = GenerationJobProjector::recoverStaleProcessing(300);
        if ($recovered > 0) {
            error_log("generation-projector: recovered stale jobs={$recovered}");
        }
    }

    $processed = GenerationJobProjector::processPendingJobs($batchSize);
    if ($processed <= 0) {
        usleep($pollMs * 1000);
        continue;
    }

    // Keep loop responsive during bursts.
    usleep(100 * 1000);
}
