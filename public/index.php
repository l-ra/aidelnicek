<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

use Aidelnicek\Auth;
use Aidelnicek\Csrf;
use Aidelnicek\Database;
use Aidelnicek\Invite;
use Aidelnicek\MealGenerator;
use Aidelnicek\MealHistory;
use Aidelnicek\MealPlan;
use Aidelnicek\Router;
use Aidelnicek\ShoppingList;
use Aidelnicek\User;

Database::init($projectRoot);
Auth::init();

$router = new Router($projectRoot);

$requireCsrf = function (string $redirectOnFail, callable $handler): callable {
    return function () use ($redirectOnFail, $handler) {
        $token = $_POST['csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            header('Location: ' . $redirectOnFail);
            exit;
        }
        $handler();
    };
};

$router->get('/', function () use ($projectRoot) {
    $user = Auth::getCurrentUser();
    if ($user === null) {
        header('Location: /login');
        exit;
    }
    require $projectRoot . '/templates/dashboard.php';
});

$router->get('/login', function () use ($projectRoot) {
    if (Auth::isLoggedIn()) {
        header('Location: /');
        exit;
    }
    require $projectRoot . '/templates/login.php';
});

$router->post('/login', $requireCsrf('/login?error=csrf', function () {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = !empty($_POST['remember_me'] ?? '');
    if ($email === '' || $password === '') {
        header('Location: /login?error=missing');
        exit;
    }
    $user = User::verifyPassword($email, $password);
    if ($user === null) {
        header('Location: /login?error=invalid');
        exit;
    }
    Auth::login((int) $user['id'], $rememberMe);
    header('Location: /');
    exit;
}));

$router->get('/register', function () use ($projectRoot) {
    if (Auth::isLoggedIn()) {
        header('Location: /');
        exit;
    }
    $invite = Invite::validateToken($_GET['invite'] ?? '');
    if ($invite === null) {
        header('Location: /login?error=invite_required');
        exit;
    }
    require $projectRoot . '/templates/register.php';
});

$router->post('/register', $requireCsrf('/register?error=csrf', function () use ($projectRoot) {
    if (Auth::isLoggedIn()) {
        header('Location: /');
        exit;
    }

    $inviteToken = $_POST['invite_token'] ?? '';
    $invite      = Invite::validateToken($inviteToken);
    if ($invite === null) {
        header('Location: /login?error=invite_required');
        exit;
    }

    $data = [
        'name'          => trim($_POST['name'] ?? ''),
        'email'         => $invite['email'],
        'password'      => $_POST['password'] ?? '',
        'gender'        => $_POST['gender'] ?? null,
        'age'           => $_POST['age'] ?? null,
        'body_type'     => $_POST['body_type'] ?? null,
        'dietary_notes' => trim($_POST['dietary_notes'] ?? '') ?: null,
        'height'        => trim($_POST['height'] ?? '') ?: null,
        'weight'        => trim($_POST['weight'] ?? '') ?: null,
        'diet_goal'     => trim($_POST['diet_goal'] ?? '') ?: null,
    ];
    $errors = User::validateRegistration($data);
    if (!empty($errors)) {
        require $projectRoot . '/templates/register.php';
        return;
    }
    User::create($data);
    $user = User::findByEmail($data['email']);
    Auth::login((int) $user['id']);
    header('Location: /');
    exit;
}));

$router->get('/profile', function () use ($projectRoot) {
    Auth::requireLogin();
    $success = ($_GET['success'] ?? '') === '1';
    $passwordSuccess = ($_GET['password_saved'] ?? '') === '1';
    require $projectRoot . '/templates/profile.php';
});

$router->post('/profile', $requireCsrf('/profile?error=csrf', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    $data = [
        'name'          => trim($_POST['name'] ?? ''),
        'gender'        => $_POST['gender'] ?? null,
        'age'           => $_POST['age'] ?? null,
        'body_type'     => $_POST['body_type'] ?? null,
        'dietary_notes' => trim($_POST['dietary_notes'] ?? '') ?: null,
        'height'        => $_POST['height'] ?? null,
        'weight'        => $_POST['weight'] ?? null,
        'diet_goal'     => trim($_POST['diet_goal'] ?? '') ?: null,
    ];
    $errors = User::validateProfile($data);
    if (empty($errors)) {
        User::update((int) $user['id'], $data);
        header('Location: /profile?success=1');
        exit;
    }
    $user = array_merge($user, $data);
    $success = false;
    $passwordSuccess = false;
    $passwordErrors = [];
    require $projectRoot . '/templates/profile.php';
}));

$router->post('/profile-password', $requireCsrf('/profile?error=csrf', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    $data = [
        'current_password' => $_POST['current_password'] ?? '',
        'new_password' => $_POST['new_password'] ?? '',
        'new_password_confirm' => $_POST['new_password_confirm'] ?? '',
    ];
    $errors = User::validatePasswordChange((int) $user['id'], $data);
    if (empty($errors)) {
        User::updatePassword((int) $user['id'], $data['new_password']);
        header('Location: /profile?password_saved=1');
        exit;
    }
    $user = User::findById((int) $user['id']);
    $success = false;
    $passwordSuccess = false;
    $passwordErrors = $errors;
    require $projectRoot . '/templates/profile.php';
}));

$router->post('/logout', $requireCsrf('/?error=csrf', function () {
    Auth::logout();
    header('Location: /login');
    exit;
}));

// ── Admin sekce ───────────────────────────────────────────────────────────────

$router->get('/admin', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: /');
        exit;
    }
    require $projectRoot . '/templates/admin.php';
});

$router->post('/admin/user-password-reset', $requireCsrf('/admin?password_error=csrf', function () {
    $adminUser = Auth::requireLogin();
    if (!User::isAdmin((int) $adminUser['id'])) {
        header('Location: /');
        exit;
    }

    $targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    if ($targetUserId <= 0) {
        header('Location: /admin?password_error=missing_user');
        exit;
    }

    $targetUser = User::findById($targetUserId);
    if ($targetUser === null) {
        header('Location: /admin?password_error=invalid_user');
        exit;
    }

    $passwordData = [
        'new_password' => $_POST['new_password'] ?? '',
        'new_password_confirm' => $_POST['new_password_confirm'] ?? '',
    ];
    $passwordErrors = User::validateAdminPasswordReset($passwordData);
    if (!empty($passwordErrors)) {
        $errorCode = array_key_first($passwordErrors);
        header('Location: /admin?password_error=' . urlencode((string) $errorCode) . '&user_id=' . $targetUserId);
        exit;
    }

    User::updatePassword($targetUserId, $passwordData['new_password']);
    header('Location: /admin?success=password_reset&user_id=' . $targetUserId);
    exit;
}));

$router->get('/admin/table', function () use ($projectRoot) {
    require $projectRoot . '/templates/admin_table.php';
});

$router->post('/admin/table/delete', $requireCsrf('/admin/table', function () {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: /');
        exit;
    }

    $db        = Database::get();
    $tableList = $db->query(
        "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    $table = $_POST['table'] ?? '';
    $rowid = isset($_POST['rowid']) ? (int) $_POST['rowid'] : 0;
    $page  = isset($_POST['page'])  ? (int) $_POST['page']  : 1;

    if ($table === '' || !in_array($table, $tableList, true) || $rowid <= 0) {
        header('Location: /admin/table?table=' . urlencode($table) . '&error=invalid');
        exit;
    }

    $qt = '"' . str_replace('"', '""', $table) . '"';
    $db->prepare("DELETE FROM {$qt} WHERE rowid = ?")->execute([$rowid]);

    header('Location: /admin/table?table=' . urlencode($table) . '&page=' . $page . '&success=deleted');
    exit;
}));

$router->post('/admin/table/clear', $requireCsrf('/admin/table', function () {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: /');
        exit;
    }

    $db        = Database::get();
    $tableList = $db->query(
        "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    $table = $_POST['table'] ?? '';
    if ($table === '' || !in_array($table, $tableList, true)) {
        header('Location: /admin/table?table=' . urlencode($table) . '&error=invalid');
        exit;
    }

    $qt = '"' . str_replace('"', '""', $table) . '"';
    $db->exec("DELETE FROM {$qt}");

    header('Location: /admin/table?table=' . urlencode($table) . '&success=cleared');
    exit;
}));

$router->post('/admin/table/update', $requireCsrf('/admin/table', function () {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: /');
        exit;
    }

    $db        = Database::get();
    $tableList = $db->query(
        "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    $table = $_POST['table'] ?? '';
    $rowid = isset($_POST['rowid']) ? (int) $_POST['rowid'] : 0;
    $page  = isset($_POST['page'])  ? (int) $_POST['page']  : 1;

    if ($table === '' || !in_array($table, $tableList, true) || $rowid <= 0) {
        header('Location: /admin/table?table=' . urlencode($table) . '&error=invalid');
        exit;
    }

    $qt = '"' . str_replace('"', '""', $table) . '"';

    // Načtení platných sloupců z PRAGMA — whitelist pro SET klauzuli
    $validColumns = [];
    foreach ($db->query("PRAGMA table_info({$qt})") as $col) {
        $validColumns[] = $col['name'];
    }

    $setClauses = [];
    $values     = [];
    foreach ($_POST as $key => $value) {
        if (!str_starts_with($key, 'field_')) {
            continue;
        }
        $colName = substr($key, 6);
        if (!in_array($colName, $validColumns, true)) {
            continue;
        }
        $qc = '"' . str_replace('"', '""', $colName) . '"';
        $setClauses[] = "{$qc} = ?";
        $values[]     = $value === '' ? null : $value;
    }

    if (!empty($setClauses)) {
        $values[] = $rowid;
        $sql = "UPDATE {$qt} SET " . implode(', ', $setClauses) . " WHERE rowid = ?";
        $db->prepare($sql)->execute($values);
    }

    header('Location: /admin/table?table=' . urlencode($table) . '&page=' . $page . '&success=updated');
    exit;
}));

$router->get('/admin/sql', function () use ($projectRoot) {
    require $projectRoot . '/templates/admin_sql.php';
});

$router->post('/admin/seed-demo', $requireCsrf('/admin?error=csrf', function () {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: /');
        exit;
    }

    $db     = Database::get();
    $userId = isset($_POST['user_id']) && $_POST['user_id'] !== ''
        ? (int) $_POST['user_id']
        : 0;

    $users = $userId > 0
        ? [['id' => $userId]]
        : $db->query('SELECT id FROM users')->fetchAll(PDO::FETCH_ASSOC);

    $week   = MealPlan::getOrCreateCurrentWeek();
    $weekId = (int) $week['id'];

    foreach ($users as $u) {
        MealPlan::seedDemoWeek((int) $u['id'], $weekId);
    }

    header('Location: /admin?success=seeded');
    exit;
}));

$router->post('/admin/sql', function () {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Přístup odepřen.']);
        exit;
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!Csrf::validate($token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Neplatný CSRF token.']);
        exit;
    }

    $sql = trim($_POST['sql'] ?? '');
    if ($sql === '') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Prázdný příkaz.']);
        exit;
    }

    header('Content-Type: application/json');
    try {
        $db   = Database::get();
        $stmt = $db->query($sql);

        if ($stmt === false) {
            echo json_encode(['ok' => false, 'error' => 'Příkaz selhal.']);
            exit;
        }

        $rows     = $stmt->fetchAll();
        $affected = $stmt->rowCount();
        echo json_encode(['ok' => true, 'rows' => $rows, 'affected' => $affected], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
});

$router->get('/admin/llm-test', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: /');
        exit;
    }
    require $projectRoot . '/templates/admin_llm_test.php';
});

$router->post('/admin/llm-test', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Přístup odepřen.']);
        exit;
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!Csrf::validate($token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Neplatný CSRF token.']);
        exit;
    }

    $systemPrompt = trim($_POST['system_prompt'] ?? '');
    $userPrompt   = trim($_POST['user_prompt']   ?? '');
    $temperature  = isset($_POST['temperature']) ? (float) $_POST['temperature'] : 0.7;
    $maxTokens    = isset($_POST['max_tokens'])  ? (int)   $_POST['max_tokens']  : 1024;

    if ($userPrompt === '') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Uživatelský prompt nesmí být prázdný.']);
        exit;
    }

    header('Content-Type: application/json');
    try {
        $workerUrl = rtrim(getenv('LLM_WORKER_URL') ?: 'http://localhost:8001', '/');
        $model     = getenv('OPENAI_MODEL') ?: 'gpt-4o';
        $payload   = json_encode([
            'system_prompt'         => $systemPrompt,
            'user_prompt'           => $userPrompt,
            'model'                 => $model,
            'temperature'           => max(0.0, min(2.0, $temperature)),
            'max_completion_tokens' => max(64, min(32000, $maxTokens)),
            'user_id'               => (int) $user['id'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init($workerUrl . '/complete');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $body     = curl_exec($ch);
        $errno    = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $httpCode < 200 || $httpCode >= 300) {
            $detail = $errno !== 0 ? curl_strerror($errno) : "HTTP {$httpCode}: " . substr((string) $body, 0, 200);
            echo json_encode(['ok' => false, 'error' => "LLM worker nedostupný: {$detail}"]);
            exit;
        }

        $workerResponse = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        echo json_encode([
            'ok'       => true,
            'response' => $workerResponse['response'] ?? '',
            'model'    => $workerResponse['model']    ?? $model,
            'provider' => $workerResponse['provider'] ?? 'openai',
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
});

$router->get('/admin/llm-logs', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: /');
        exit;
    }
    require $projectRoot . '/templates/admin_llm_logs.php';
});

$router->post('/admin/llm-logs/data', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Přístup odepřen.']);
        exit;
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!Csrf::validate($token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Neplatný CSRF token.']);
        exit;
    }

    $filename = $_POST['filename'] ?? '';
    if (!preg_match('/^llm_\d{4}-\d{2}-\d{2}\.db$/', $filename)) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Neplatný název souboru.']);
        exit;
    }

    $path = $projectRoot . '/data/' . $filename;
    if (!file_exists($path)) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Soubor neexistuje.']);
        exit;
    }

    header('Content-Type: application/json');
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rows = $pdo->query(
            'SELECT id, request_at, provider, model, user_id, prompt_system, prompt_user,
                    response_text, tokens_in, tokens_out, duration_ms, status, error_message
             FROM llm_log ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
});

// ── M7: AI generování jídelníčku přes Python LLM worker (streaming) ──────────

$router->get('/admin/llm-generate', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: /');
        exit;
    }
    require $projectRoot . '/templates/admin_generate.php';
});

$router->post('/admin/llm-generate', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Přístup odepřen.']);
        exit;
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!Csrf::validate($token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Neplatný CSRF token.']);
        exit;
    }

    header('Content-Type: application/json');

    $userId         = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $force          = !empty($_POST['force']);
    $generationMode = (string) ($_POST['generation_mode'] ?? 'single');
    $allowedModes   = ['single', 'shared_all'];
    if (!in_array($generationMode, $allowedModes, true)) {
        echo json_encode(['ok' => false, 'error' => 'Neplatný režim generování.']);
        exit;
    }

    // Accept either a direct week_id or week_number+year (template sends the latter)
    $weekId = isset($_POST['week_id']) ? (int) $_POST['week_id'] : 0;
    if ($weekId <= 0 && isset($_POST['week_number'], $_POST['year'])) {
        $weekNum  = (int) $_POST['week_number'];
        $weekYear = (int) $_POST['year'];
        if ($weekNum >= 1 && $weekNum <= 53 && $weekYear >= 2024) {
            Database::get()->prepare(
                'INSERT OR IGNORE INTO weeks (week_number, year) VALUES (?, ?)'
            )->execute([$weekNum, $weekYear]);
            $row = Database::get()->prepare('SELECT id FROM weeks WHERE week_number = ? AND year = ?');
            $row->execute([$weekNum, $weekYear]);
            $weekRow = $row->fetch();
            $weekId  = $weekRow ? (int) $weekRow['id'] : 0;
        }
    }

    if ($weekId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Chybí platný týden.']);
        exit;
    }

    if ($userId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Chybí user_id.']);
        exit;
    }

    $referenceUser = User::findById($userId);
    if ($referenceUser === null) {
        echo json_encode(['ok' => false, 'error' => 'Uživatel neexistuje.']);
        exit;
    }

    $jobId = $generationMode === 'shared_all'
        ? MealGenerator::startSharedGenerationJob($userId, $weekId, $force)
        : MealGenerator::startGenerationJob($userId, $weekId, $force);

    if ($jobId <= 0) {
        $error = $generationMode === 'shared_all'
            ? 'Společné generování pro všechny uživatele selhalo.'
            : 'Generování pro vybraného uživatele selhalo.';
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }

    echo json_encode([
        'ok'              => true,
        'job_id'          => $jobId,
        'generation_mode' => $generationMode,
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

$router->get('/admin/llm-stream', function () {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        http_response_code(403);
        exit;
    }

    $jobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;
    if ($jobId <= 0) {
        http_response_code(400);
        exit;
    }

    // Flush all output buffers before switching to SSE mode
    while (@ob_end_flush()) {
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // Disable nginx/proxy buffering
    header('Content-Encoding: none');
    header('Connection: keep-alive');

    set_time_limit(0);

    $db   = Database::get();
    $stmt = $db->prepare('SELECT id FROM generation_jobs WHERE id = ?');
    $stmt->execute([$jobId]);
    if ($stmt->fetch() === false) {
        echo "data: " . json_encode(['type' => 'error', 'error' => 'Job nenalezen']) . "\n\n";
        flush();
        exit;
    }

    $lastChunkCount = 0;
    $lastTextLen    = 0;
    $maxWaitSec     = 600;
    $startTime      = time();
    $keepaliveTick  = 0;

    while (!connection_aborted() && (time() - $startTime) < $maxWaitSec) {
        $stmt = $db->prepare(
            'SELECT status, progress_text, chunk_count, error_message FROM generation_jobs WHERE id = ?'
        );
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if ($job === false) {
            echo "data: " . json_encode(['type' => 'error', 'error' => 'Job nenalezen']) . "\n\n";
            flush();
            break;
        }

        $currentCount = (int) $job['chunk_count'];

        if ($currentCount > $lastChunkCount) {
            $fullText = (string) $job['progress_text'];
            $newText  = substr($fullText, $lastTextLen);
            if ($newText !== '') {
                echo "data: " . json_encode(
                    ['type' => 'chunk', 'text' => $newText, 'count' => $currentCount],
                    JSON_UNESCAPED_UNICODE
                ) . "\n\n";
                flush();
                $lastChunkCount = $currentCount;
                $lastTextLen    = strlen($fullText);
            }
        }

        if (in_array($job['status'], ['done', 'error'], true)) {
            echo "data: " . json_encode(
                ['type' => 'done', 'status' => $job['status'], 'error' => $job['error_message']],
                JSON_UNESCAPED_UNICODE
            ) . "\n\n";
            flush();
            break;
        }

        // SSE keepalive comment every ~10 s to prevent proxy timeout
        $keepaliveTick++;
        if ($keepaliveTick % 25 === 0) {
            echo ": keepalive\n\n";
            flush();
        }

        usleep(400_000);
    }

    exit;
});

// ── M3: Jídelníček ────────────────────────────────────────────────────────────

$router->get('/plan', function () {
    Auth::requireLogin();
    header('Location: /plan/day');
    exit;
});

$router->get('/plan/day', function () use ($projectRoot) {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $week      = MealPlan::getOrCreateCurrentWeek();
    $weekId    = (int) $week['id'];
    $todayIso  = (int) date('N'); // 1=Mon … 7=Sun

    $day = isset($_GET['day']) ? (int) $_GET['day'] : $todayIso;
    $day = max(1, min(7, $day));

    $dayPlan = MealPlan::getDayPlan($userId, $weekId, $day);

    require $projectRoot . '/templates/day_plan.php';
});

$router->get('/plan/week', function () use ($projectRoot) {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $currentWeek = MealPlan::getOrCreateCurrentWeek();

    $requestedWeek = isset($_GET['week']) ? (int) $_GET['week'] : null;
    $requestedYear = isset($_GET['year']) ? (int) $_GET['year'] : null;

    if ($requestedWeek !== null && $requestedYear !== null) {
        $stmt = \Aidelnicek\Database::get()->prepare(
            'SELECT * FROM weeks WHERE week_number = ? AND year = ?'
        );
        $stmt->execute([$requestedWeek, $requestedYear]);
        $weekRow = $stmt->fetch();
        if ($weekRow === false) {
            // Create week entry for navigation to past/future weeks
            \Aidelnicek\Database::get()->prepare(
                'INSERT OR IGNORE INTO weeks (week_number, year) VALUES (?, ?)'
            )->execute([$requestedWeek, $requestedYear]);
            $stmt->execute([$requestedWeek, $requestedYear]);
            $weekRow = $stmt->fetch();
        }
        $week = $weekRow;
    } else {
        $week = $currentWeek;
    }

    $weekId = (int) $week['id'];

    $weekPlan = MealPlan::getWeekPlan($userId, $weekId);
    $todayIso = (int) date('N');

    require $projectRoot . '/templates/week_plan.php';
});

$router->post('/plan/choose', $requireCsrf('/plan/day', function () use ($projectRoot) {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $planId     = isset($_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;
    $redirectTo = $_POST['redirect_to'] ?? '/plan/day';
    $isAjax     = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    if ($planId <= 0) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'invalid plan_id']);
            exit;
        }
        header('Location: ' . $redirectTo);
        exit;
    }

    // Fetch meal name before updating so we can record history
    $stmt = \Aidelnicek\Database::get()->prepare(
        'SELECT meal_name FROM meal_plans WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$planId, $userId]);
    $planRow = $stmt->fetch();

    $ok = MealPlan::chooseAlternative($userId, $planId);

    if ($ok && $planRow !== false) {
        MealHistory::recordChoice($userId, $planRow['meal_name']);
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok]);
        exit;
    }

    header('Location: ' . $redirectTo);
    exit;
}));

$router->post('/plan/eaten', $requireCsrf('/plan/day', function () use ($projectRoot) {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $planId     = isset($_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;
    $redirectTo = $_POST['redirect_to'] ?? '/plan/day';
    $isAjax     = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    if ($planId <= 0) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'invalid plan_id']);
            exit;
        }
        header('Location: ' . $redirectTo);
        exit;
    }

    $stmt = \Aidelnicek\Database::get()->prepare(
        'SELECT meal_name, is_eaten FROM meal_plans WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$planId, $userId]);
    $planRow = $stmt->fetch();

    $ok = MealPlan::toggleEaten($userId, $planId);

    // Record eaten only when transitioning 0→1
    if ($ok && $planRow !== false && (int) $planRow['is_eaten'] === 0) {
        MealHistory::recordEaten($userId, $planRow['meal_name']);
    }

    if ($isAjax) {
        $isEaten = MealPlan::isEaten($userId, $planId);
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'is_eaten' => $isEaten]);
        exit;
    }

    header('Location: ' . $redirectTo);
    exit;
}));

// ── M5: Přegenerování jídelníčku (podmíněno AI_REGEN_UI_ENABLED) ─────────────

$router->post('/plan/regenerate', $requireCsrf('/plan/week', function () {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $enabled = getenv('AI_REGEN_UI_ENABLED');
    if (!in_array($enabled, ['true', '1', 'yes'], true)) {
        header('Location: /plan/week');
        exit;
    }

    $weekId = isset($_POST['week_id']) ? (int) $_POST['week_id'] : 0;
    $week   = $weekId > 0 ? MealPlan::getWeekById($weekId) : null;

    if ($week === null) {
        $week   = MealPlan::getOrCreateCurrentWeek();
        $weekId = (int) $week['id'];
    }

    // Spustí generování přes LLM worker (fire-and-forget — uživatel nemusí čekat)
    MealGenerator::startGenerationJob($userId, (int) $week['id'], true);

    header('Location: /plan/week?week=' . (int) $week['week_number'] . '&year=' . (int) $week['year'] . '&generating=1');
    exit;
}));

// ── M4: Nákupní seznam ────────────────────────────────────────────────────────

$router->get('/shopping', function () use ($projectRoot) {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $week   = MealPlan::getOrCreateCurrentWeek();
    $weekId = (int) $week['id'];

    // Auto-generate shopping list if no auto-generated items exist yet
    ShoppingList::generateFromMealPlans($weekId);

    require $projectRoot . '/templates/shopping_list.php';
});

$router->post('/shopping/toggle', $requireCsrf('/shopping', function () {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $itemId     = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    $redirectTo = $_POST['redirect_to'] ?? '/shopping';
    $isAjax     = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    if ($itemId <= 0) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'invalid item_id']);
            exit;
        }
        header('Location: ' . $redirectTo);
        exit;
    }

    $ok = ShoppingList::togglePurchased($userId, $itemId);

    if ($isAjax) {
        $stmt = Database::get()->prepare(
            'SELECT is_purchased FROM shopping_list_items WHERE id = ?'
        );
        $stmt->execute([$itemId]);
        $row         = $stmt->fetch();
        $isPurchased = $row !== false ? (bool) $row['is_purchased'] : false;

        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'is_purchased' => $isPurchased]);
        exit;
    }

    header('Location: ' . $redirectTo);
    exit;
}));

$router->post('/shopping/add', $requireCsrf('/shopping', function () {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $week   = MealPlan::getOrCreateCurrentWeek();
    $weekId = (int) $week['id'];

    $name     = trim($_POST['name'] ?? '');
    $quantity = (isset($_POST['quantity']) && $_POST['quantity'] !== '')
        ? (float) $_POST['quantity']
        : null;
    $unit     = trim($_POST['unit'] ?? '') ?: null;
    $category = trim($_POST['category'] ?? '') ?: null;
    $isAjax   = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    if ($name === '') {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Název je povinný']);
            exit;
        }
        header('Location: /shopping?error=name');
        exit;
    }

    $id = ShoppingList::addItem($userId, $weekId, $name, $quantity, $unit, $category);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    header('Location: /shopping');
    exit;
}));

$router->post('/shopping/remove', $requireCsrf('/shopping', function () {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $itemId     = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    $redirectTo = $_POST['redirect_to'] ?? '/shopping';
    $isAjax     = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    if ($itemId <= 0) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'invalid item_id']);
            exit;
        }
        header('Location: ' . $redirectTo);
        exit;
    }

    $ok = ShoppingList::removeItem($userId, $itemId);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok]);
        exit;
    }

    header('Location: ' . $redirectTo);
    exit;
}));

$router->post('/shopping/clear', $requireCsrf('/shopping', function () {
    Auth::requireLogin();

    $week   = MealPlan::getOrCreateCurrentWeek();
    $weekId = (int) $week['id'];

    ShoppingList::clearPurchased($weekId);

    header('Location: /shopping');
    exit;
}));

$router->post('/shopping/regenerate', $requireCsrf('/shopping', function () {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $week   = MealPlan::getOrCreateCurrentWeek();
    $weekId = (int) $week['id'];

    ShoppingList::generateFromMealPlans($weekId, true);

    header('Location: /shopping');
    exit;
}));

// ── Pozvánky uživatelů (admin) ────────────────────────────────────────────────

$router->get('/admin/invite', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: /');
        exit;
    }
    require $projectRoot . '/templates/admin_invite.php';
});

$router->post('/admin/invite', $requireCsrf('/admin/invite?error=csrf', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: /');
        exit;
    }

    $email        = trim($_POST['email'] ?? '');
    $validityHours = isset($_POST['validity_hours']) ? max(1, (int) $_POST['validity_hours']) : 168;
    $inviteErrors  = [];

    if ($email === '') {
        $inviteErrors[] = 'E-mail je povinný.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $inviteErrors[] = 'Neplatný formát e-mailu.';
    }

    if (empty($inviteErrors)) {
        $inviteUrl = Invite::getInviteUrl($email, $validityHours);
    } else {
        $inviteUrl = null;
    }

    require $projectRoot . '/templates/admin_invite.php';
}));

$router->dispatch();
