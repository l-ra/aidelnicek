<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

use Aidelnicek\Auth;
use Aidelnicek\Csrf;
use Aidelnicek\Database;
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
    require $projectRoot . '/templates/register.php';
});

$router->post('/register', $requireCsrf('/register?error=csrf', function () use ($projectRoot) {
    if (Auth::isLoggedIn()) {
        header('Location: /');
        exit;
    }
    $data = [
        'name' => trim($_POST['name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'gender' => $_POST['gender'] ?? null,
        'age' => $_POST['age'] ?? null,
        'body_type' => $_POST['body_type'] ?? null,
        'dietary_notes' => trim($_POST['dietary_notes'] ?? '') ?: null,
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

    $weekId  = isset($_POST['week_id']) ? (int) $_POST['week_id'] : 0;
    $week    = $weekId > 0 ? MealPlan::getWeekById($weekId) : null;

    if ($week === null) {
        $week   = MealPlan::getOrCreateCurrentWeek();
        $weekId = (int) $week['id'];
    }

    MealGenerator::generateWeek($userId, (int) $week['id'], true);

    header('Location: /plan/week?week=' . (int) $week['week_number'] . '&year=' . (int) $week['year']);
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

$router->dispatch();
