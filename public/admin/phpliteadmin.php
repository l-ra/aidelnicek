<?php

declare(strict_types=1);

// ── Auth guard ────────────────────────────────────────────────────────────────
// This file is a real PHP file served directly by Apache (bypassing the router).
// It bootstraps the application's auth layer and gates access to admin-only users.

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/vendor/autoload.php';

use Aidelnicek\Auth;
use Aidelnicek\Database;
use Aidelnicek\User;

Database::init($projectRoot);
Auth::init(); // starts session, processes remember-me cookie

$currentUser = Auth::getCurrentUser();
if ($currentUser === null || !User::isAdmin((int) $currentUser['id'])) {
    header('Location: /login');
    exit;
}

// Intercept phpLiteAdmin's logout to prevent session_destroy() from destroying
// the application session. Redirect the user to the app home page instead.
if (isset($_GET['logout'])) {
    header('Location: /');
    exit;
}

// ── phpLiteAdmin configuration ────────────────────────────────────────────────
// Empty password → phpLiteAdmin auto-authorises any request.
// Authentication is already enforced above by the application's own auth layer.
$password    = '';
$directory   = $projectRoot . '/data/';
$cookie_name = 'aidelnicek_pla';

// ── Include phpLiteAdmin ──────────────────────────────────────────────────────
// phpLiteAdmin calls session_start() internally. Because the session is already
// active (started by Auth::init()), PHP returns false but preserves all session
// data. phpLiteAdmin's own session keys (prefixed with COOKIENAME) coexist with
// the application's keys (user_id, last_activity) without collision.
include $projectRoot . '/tools/phpliteadmin.php';
