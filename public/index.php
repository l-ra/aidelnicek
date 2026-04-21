<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

use Aidelnicek\AdminTableHelper;
use Aidelnicek\ApplicationDataExport;
use Aidelnicek\Auth;
use Aidelnicek\Csrf;
use Aidelnicek\Database;
use Aidelnicek\EmailChange;
use Aidelnicek\Invite;
use Aidelnicek\LlmEnv;
use Aidelnicek\Mailer;
use Aidelnicek\MealGenerator;
use Aidelnicek\MealHistory;
use Aidelnicek\MealPlan;
use Aidelnicek\MealRecipe;
use Aidelnicek\PlanShare;
use Aidelnicek\GenerationJobService;
use Aidelnicek\Router;
use Aidelnicek\ShoppingList;
use Aidelnicek\ShoppingListExport;
use Aidelnicek\Tenant;
use Aidelnicek\TenantContext;
use Aidelnicek\Url;
use Aidelnicek\User;

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$segments    = array_values(array_filter(explode('/', trim($requestPath, '/')), static fn ($s) => $s !== ''));
$firstSeg    = $segments[0] ?? '';
$tenantSlug  = null;
$urlBasePath = '';

if ($firstSeg !== '' && Tenant::isValidSlug($firstSeg) && Tenant::tenantExists($projectRoot, $firstSeg)) {
    $tenantSlug = $firstSeg;
    TenantContext::initFromSlug($tenantSlug);
    $urlBasePath = '/' . $tenantSlug;
    Auth::configureTenantSession();
    Database::init($projectRoot, $tenantSlug);
} elseif ($firstSeg === '') {
    TenantContext::reset();
    Database::init($projectRoot, null);
} else {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>404</title></head><body>'
        . '<h1>404</h1><p>Domácnost neexistuje nebo neplatná adresa.</p></body></html>';
    exit;
}

if ($tenantSlug === null) {
    require $projectRoot . '/templates/landing.php';
    exit;
}

Auth::init();

$router = new Router($projectRoot, $urlBasePath);

$requireCsrf = function (string $redirectOnFail, callable $handler): callable {
    return function () use ($redirectOnFail, $handler) {
        $token = $_POST['csrf_token'] ?? '';
        if (!Csrf::validate($token)) {
            header('Location: ' . Url::tenantLocation($redirectOnFail));
            exit;
        }
        $handler();
    };
};

$router->get('/', function () use ($projectRoot) {
    $user = Auth::getCurrentUser();
    if ($user === null) {
        header('Location: ' . Url::u('/login'));
        exit;
    }
    require $projectRoot . '/templates/dashboard.php';
});

$router->get('/login', function () use ($projectRoot) {
    if (Auth::isLoggedIn()) {
        header('Location: ' . Url::u('/'));
        exit;
    }
    require $projectRoot . '/templates/login.php';
});

$router->post('/login', $requireCsrf('/login?error=csrf', function () {
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $rememberMe = !empty($_POST['remember_me'] ?? '');
    if ($email === '' || $password === '') {
        header('Location: ' . Url::u('/login?error=missing'));
        exit;
    }
    $user = User::verifyPassword($email, $password);
    if ($user === null) {
        header('Location: ' . Url::u('/login?error=invalid'));
        exit;
    }
    Auth::login((int) $user['id'], $rememberMe);
    header('Location: ' . Url::u('/'));
    exit;
}));

$router->get('/register', function () use ($projectRoot) {
    if (Auth::isLoggedIn()) {
        header('Location: ' . Url::u('/'));
        exit;
    }
    $invite = Invite::validateToken($_GET['invite'] ?? '');
    if ($invite === null) {
        header('Location: ' . Url::u('/login?error=invite_required'));
        exit;
    }
    require $projectRoot . '/templates/register.php';
});

$router->post('/register', $requireCsrf('/register?error=csrf', function () use ($projectRoot) {
    if (Auth::isLoggedIn()) {
        header('Location: ' . Url::u('/'));
        exit;
    }

    $inviteToken = $_POST['invite_token'] ?? '';
    $invite      = Invite::validateToken($inviteToken);
    if ($invite === null) {
        header('Location: ' . Url::u('/login?error=invite_required'));
        exit;
    }

    $data = [
        'name'          => trim($_POST['name'] ?? ''),
        'email'         => mb_strtolower($invite['email']),
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
    header('Location: ' . Url::u('/'));
    exit;
}));

$router->get('/profile', function () use ($projectRoot) {
    Auth::requireLogin();
    $user = Auth::getCurrentUser();
    $success = ($_GET['success'] ?? '') === '1';
    $passwordSuccess = ($_GET['password_saved'] ?? '') === '1';
    $emailChanged = ($_GET['email_changed'] ?? '') === '1';
    $emailChangeSent = ($_GET['email_change_sent'] ?? '') === '1';
    $emailChangeCancelled = ($_GET['email_change_cancelled'] ?? '') === '1';
    $emailChangeMailError = match ($_GET['email_change_err'] ?? '') {
        'not_configured' => 'Odeslání e-mailu není na serveru nakonfigurováno. Kontaktujte správce.',
        'send_failed'    => 'Odeslání ověřovacího e-mailu se nezdařilo. Zkuste to prosím znovu později.',
        default          => null,
    };
    $pendingEmailChange = $user !== null ? EmailChange::getPendingForUser((int) $user['id']) : null;
    $emailChangeForm = $_SESSION['profile_email_change_form'] ?? null;
    unset($_SESSION['profile_email_change_form']);
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
        header('Location: ' . Url::u('/profile?success=1'));
        exit;
    }
    $user = array_merge($user, $data);
    $success = false;
    $passwordSuccess = false;
    $passwordErrors = [];
    require $projectRoot . '/templates/profile.php';
}));

$router->post('/profile/email-request', $requireCsrf('/profile?error=csrf', function () {
    $user = Auth::requireLogin();
    $userId = (int) $user['id'];
    $currentNorm = mb_strtolower(trim((string) ($user['email'] ?? '')));

    $start = EmailChange::startRequest(
        $userId,
        $currentNorm,
        (string) ($_POST['new_email'] ?? ''),
        (string) ($_POST['password'] ?? '')
    );

    if (!empty($start['errors'])) {
        $_SESSION['profile_email_change_form'] = [
            'new_email' => trim((string) ($_POST['new_email'] ?? '')),
            'errors'    => $start['errors'],
        ];
        header('Location: ' . Url::u('/profile'));
        exit;
    }

    if (!Mailer::isConfigured()) {
        EmailChange::cancelPendingForUser($userId);
        header('Location: ' . Url::u('/profile?email_change_err=not_configured'));
        exit;
    }

    $requestId   = (int) ($start['request_id'] ?? 0);
    $expiresUnix = (int) ($start['expires_unix'] ?? 0);
    if ($requestId <= 0 || $expiresUnix <= 0) {
        EmailChange::cancelPendingForUser($userId);
        header('Location: ' . Url::u('/profile?email_change_err=send_failed'));
        exit;
    }

    $db = Database::get();
    $stmt = $db->prepare(
        'SELECT new_email, old_email FROM email_change_requests WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$requestId, $userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        header('Location: ' . Url::u('/profile?email_change_err=send_failed'));
        exit;
    }

    $newEmail = (string) $row['new_email'];
    $oldEmail = (string) $row['old_email'];
    $token    = EmailChange::buildSignedToken($requestId, $userId, $newEmail, $expiresUnix);
    $confirmPath = Url::u('/profile/confirm-email?token=' . rawurlencode($token));
    $confirmUrl  = EmailChange::absoluteBaseUrl() . $confirmPath;

    $newBody = "Dobrý den,\n\n"
        . "na účtu v Aidelníčku byla požádána změna e-mailové adresy na tuto schránku.\n\n"
        . "Pro dokončení změny otevřete v prohlížeči tento odkaz:\n"
        . $confirmUrl . "\n\n"
        . "Na stránce potvrďte tlačítkem — otevření náhledu v poštovním klientovi nestačí.\n\n"
        . "Pokud jste o změnu nežádali, tento e-mail ignorujte.\n";

    $adminDefault = EmailChange::defaultAdminEmail();
    try {
        Mailer::sendPlain($newEmail, 'Aidelníček — potvrzení nové e-mailové adresy', $newBody);

        if ($oldEmail !== '' && mb_strtolower($oldEmail) !== mb_strtolower($adminDefault)) {
            $oldBody = "Dobrý den,\n\n"
                . "na vašem účtu v Aidelníčku byla zahájena změna e-mailové adresy.\n"
                . 'Nová adresa (po potvrzení): ' . $newEmail . "\n\n"
                . "Pokud jste o změnu nežádali, co nejdříve kontaktujte správce domácnosti.\n";
            Mailer::sendPlain($oldEmail, 'Aidelníček — oznámení o změně e-mailu', $oldBody);
        }
    } catch (\Throwable $e) {
        EmailChange::cancelPendingForUser($userId);
        header('Location: ' . Url::u('/profile?email_change_err=send_failed'));
        exit;
    }

    header('Location: ' . Url::u('/profile?email_change_sent=1'));
    exit;
}));

$router->post('/profile/email-cancel', $requireCsrf('/profile?error=csrf', function () {
    $user = Auth::requireLogin();
    EmailChange::cancelPendingForUser((int) $user['id']);
    header('Location: ' . Url::u('/profile?email_change_cancelled=1'));
    exit;
}));

$router->get('/profile/confirm-email', function () use ($projectRoot) {
    $token = (string) ($_GET['token'] ?? '');
    $linkState = EmailChange::confirmLinkState($token);
    $confirmError = match ($_GET['err'] ?? '') {
        'invalid', 'mismatch' => 'Odkaz je neplatný nebo nekompletní.',
        'expired'             => 'Platnost odkazu vypršela. Požádejte o nový v profilu.',
        'consumed'            => 'Tato žádost už byla vyřízena.',
        'taken'               => 'Novou adresu mezitím používá jiný účet. Zvolte prosím jiný e-mail.',
        default               => null,
    };
    require $projectRoot . '/templates/profile_confirm_email.php';
});

$router->post('/profile/confirm-email', function () {
    $token = (string) ($_POST['token'] ?? '');
    $back = $token !== ''
        ? '/profile/confirm-email?token=' . rawurlencode($token) . '&error=csrf'
        : '/profile/confirm-email?err=invalid';
    if (!Csrf::validate((string) ($_POST['csrf_token'] ?? ''))) {
        header('Location: ' . Url::u($back));
        exit;
    }

    if ($token === '') {
        header('Location: ' . Url::u('/profile/confirm-email?err=invalid'));
        exit;
    }

    $result = EmailChange::applyConfirmedChange($token);
    if ($result === 'ok') {
        if (Auth::isLoggedIn()) {
            header('Location: ' . Url::u('/profile?email_changed=1'));
        } else {
            header('Location: ' . Url::u('/login?email_changed=1'));
        }
        exit;
    }

    header(
        'Location: ' . Url::u('/profile/confirm-email?token=' . rawurlencode($token) . '&err=' . rawurlencode($result))
    );
    exit;
});

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
        header('Location: ' . Url::u('/profile?password_saved=1'));
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
    header('Location: ' . Url::u('/login'));
    exit;
}));

// ── Admin sekce ───────────────────────────────────────────────────────────────

$router->get('/admin', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: ' . Url::u('/'));
        exit;
    }
    require $projectRoot . '/templates/admin.php';
});

$router->get('/admin/data-export.json.gz', function () {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: ' . Url::u('/'));
        exit;
    }

    $tenantSlug = TenantContext::requireSlug();
    $db         = Database::get();
    $gz         = ApplicationDataExport::exportToGzipJson($db, $tenantSlug);

    $safeSlug = preg_replace('/[^a-z0-9_-]+/i', '-', $tenantSlug) ?: 'tenant';
    $filename = 'aidelnicek-export-' . $safeSlug . '-' . gmdate('Ymd-His') . 'Z.json.gz';

    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) strlen($gz));
    header('Cache-Control: no-store');
    echo $gz;
    exit;
});

$router->post('/admin/data-import', $requireCsrf('/admin?import_error=csrf', function () {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: ' . Url::u('/'));
        exit;
    }

    if (empty($_FILES['import_file']['tmp_name']) || !is_uploaded_file((string) $_FILES['import_file']['tmp_name'])) {
        header('Location: ' . Url::u('/admin?import_error=no_file'));
        exit;
    }

    $path = (string) $_FILES['import_file']['tmp_name'];
    $raw  = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        header('Location: ' . Url::u('/admin?import_error=read_failed'));
        exit;
    }

    $db     = Database::get();
    $result = ApplicationDataExport::importFromGzipJson($db, $raw);
    if (($result['ok'] ?? false) !== true) {
        $msg = isset($result['error']) ? (string) $result['error'] : 'Neznámá chyba importu.';
        header('Location: ' . Url::u('/admin?import_error=' . rawurlencode($msg)));
        exit;
    }

    $rows = (int) ($result['rows_imported'] ?? 0);
    $tbls = (int) ($result['tables_imported'] ?? 0);
    header('Location: ' . Url::u('/admin?import_ok=1&import_rows=' . $rows . '&import_tables=' . $tbls));
    exit;
}));

$router->post('/admin/mail-test', $requireCsrf('/admin?email_test=csrf', function () {
    $adminUser = Auth::requireLogin();
    if (!User::isAdmin((int) $adminUser['id'])) {
        header('Location: ' . Url::u('/'));
        exit;
    }

    if (!Mailer::isConfigured()) {
        header('Location: ' . Url::u('/admin?email_test_error=' . rawurlencode('E-mail není nakonfigurován.')));
        exit;
    }

    $to = trim((string) ($adminUser['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        header('Location: ' . Url::u('/admin?email_test_error=' . rawurlencode('Účet administrátora nemá platnou e-mailovou adresu.')));
        exit;
    }

    try {
        $subject = 'Aidelníček — test SMTP';
        $body = "Toto je testovací zpráva z administrace Aidelníček.\n\n"
            . 'Čas odeslání: ' . date('c') . "\n";
        Mailer::sendPlain($to, $subject, $body);
        header('Location: ' . Url::u('/admin?email_test=ok'));
    } catch (\Throwable $e) {
        header('Location: ' . Url::u('/admin?email_test_error=' . rawurlencode($e->getMessage())));
    }
    exit;
}));

$router->post('/admin/user-password-reset', $requireCsrf('/admin?password_error=csrf', function () {
    $adminUser = Auth::requireLogin();
    if (!User::isAdmin((int) $adminUser['id'])) {
        header('Location: ' . Url::u('/'));
        exit;
    }

    $targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    if ($targetUserId <= 0) {
        header('Location: ' . Url::u('/admin?password_error=missing_user'));
        exit;
    }

    $targetUser = User::findById($targetUserId);
    if ($targetUser === null) {
        header('Location: ' . Url::u('/admin?password_error=invalid_user'));
        exit;
    }

    $passwordData = [
        'new_password' => $_POST['new_password'] ?? '',
        'new_password_confirm' => $_POST['new_password_confirm'] ?? '',
    ];
    $passwordErrors = User::validateAdminPasswordReset($passwordData);
    if (!empty($passwordErrors)) {
        $errorCode = array_key_first($passwordErrors);
        header('Location: ' . Url::u('/admin?password_error=' . urlencode((string) $errorCode) . '&user_id=' . $targetUserId));
        exit;
    }

    User::updatePassword($targetUserId, $passwordData['new_password']);
    header('Location: ' . Url::u('/admin?success=password_reset&user_id=' . $targetUserId));
    exit;
}));

$router->get('/admin/table', function () use ($projectRoot) {
    require $projectRoot . '/templates/admin_table.php';
});

$router->post('/admin/table/delete', $requireCsrf('/admin/table', function () {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: ' . Url::u('/'));
        exit;
    }

    $db        = Database::get();
    $tableList = AdminTableHelper::listTables($db);

    $table = $_POST['table'] ?? '';
    $rowid = isset($_POST['rowid']) ? (int) $_POST['rowid'] : 0;
    $page  = isset($_POST['page'])  ? (int) $_POST['page']  : 1;

    if ($table === '' || !in_array($table, $tableList, true) || $rowid <= 0) {
        header('Location: ' . Url::u('/admin/table?table=' . urlencode($table) . '&error=invalid'));
        exit;
    }

    $qt = '"' . str_replace('"', '""', $table) . '"';
    $pk = AdminTableHelper::primaryKeyColumn($db, $table);
    if ($pk === null) {
        header('Location: ' . Url::u('/admin/table?table=' . urlencode($table) . '&error=invalid'));
        exit;
    }
    $qpk = '"' . str_replace('"', '""', $pk) . '"';
    $db->prepare("DELETE FROM {$qt} WHERE {$qpk} = ?")->execute([$rowid]);

    header('Location: ' . Url::u('/admin/table?table=' . urlencode($table) . '&page=' . $page . '&success=deleted'));
    exit;
}));

$router->post('/admin/table/clear', $requireCsrf('/admin/table', function () {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: ' . Url::u('/'));
        exit;
    }

    $db        = Database::get();
    $tableList = AdminTableHelper::listTables($db);

    $table = $_POST['table'] ?? '';
    if ($table === '' || !in_array($table, $tableList, true)) {
        header('Location: ' . Url::u('/admin/table?table=' . urlencode($table) . '&error=invalid'));
        exit;
    }

    $qt = '"' . str_replace('"', '""', $table) . '"';
    $db->exec("DELETE FROM {$qt}");

    header('Location: ' . Url::u('/admin/table?table=' . urlencode($table) . '&success=cleared'));
    exit;
}));

$router->post('/admin/table/update', $requireCsrf('/admin/table', function () {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: ' . Url::u('/'));
        exit;
    }

    $db        = Database::get();
    $tableList = AdminTableHelper::listTables($db);

    $table = $_POST['table'] ?? '';
    $rowid = isset($_POST['rowid']) ? (int) $_POST['rowid'] : 0;
    $page  = isset($_POST['page'])  ? (int) $_POST['page']  : 1;

    if ($table === '' || !in_array($table, $tableList, true) || $rowid <= 0) {
        header('Location: ' . Url::u('/admin/table?table=' . urlencode($table) . '&error=invalid'));
        exit;
    }

    $qt = '"' . str_replace('"', '""', $table) . '"';

    $pk = AdminTableHelper::primaryKeyColumn($db, $table);
    if ($pk === null) {
        header('Location: ' . Url::u('/admin/table?table=' . urlencode($table) . '&error=invalid'));
        exit;
    }
    $qpk = '"' . str_replace('"', '""', $pk) . '"';

    // Načtení platných sloupců — whitelist pro SET klauzuli
    $validColumns = [];
    foreach (AdminTableHelper::listColumnsMeta($db, $table) as $col) {
        $validColumns[] = $col['name'];
    }

    $setClauses = [];
    $values     = [];
    foreach ($_POST as $key => $value) {
        if (!str_starts_with($key, 'field_')) {
            continue;
        }
        $colName = substr($key, 6);
        if (!in_array($colName, $validColumns, true) || $colName === $pk) {
            continue;
        }
        $qc = '"' . str_replace('"', '""', $colName) . '"';
        $setClauses[] = "{$qc} = ?";
        $values[]     = $value === '' ? null : $value;
    }

    if (!empty($setClauses)) {
        $values[] = $rowid;
        $sql = "UPDATE {$qt} SET " . implode(', ', $setClauses) . " WHERE {$qpk} = ?";
        $db->prepare($sql)->execute($values);
    }

    header('Location: ' . Url::u('/admin/table?table=' . urlencode($table) . '&page=' . $page . '&success=updated'));
    exit;
}));

$router->get('/admin/sql', function () use ($projectRoot) {
    require $projectRoot . '/templates/admin_sql.php';
});

$router->post('/admin/seed-demo', $requireCsrf('/admin?error=csrf', function () {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: ' . Url::u('/'));
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

    header('Location: ' . Url::u('/admin?success=seeded'));
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
        header('Location: ' . Url::u('/'));
        exit;
    }
    $llmDefaultMaxTokens = LlmEnv::maxCompletionTokens();
    $llmMaxTokensCap     = LlmEnv::MAX_COMPLETION_TOKENS_CAP;
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
    $defaultMax   = LlmEnv::maxCompletionTokens();
    $maxTokens    = isset($_POST['max_tokens']) ? (int) $_POST['max_tokens'] : $defaultMax;

    if ($userPrompt === '') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Uživatelský prompt nesmí být prázdný.']);
        exit;
    }

    header('Content-Type: application/json');
    try {
        $model     = getenv('OPENAI_MODEL') ?: 'gpt-4o';
        $jobId = GenerationJobService::startJob([
            'user_id'               => (int) $user['id'],
            'week_id'               => 0,
            'job_type'              => 'llm_test',
            'mode'                  => 'sync',
            'system_prompt'         => $systemPrompt,
            'user_prompt'           => $userPrompt,
            'model'                 => $model,
            'temperature'           => max(0.0, min(2.0, $temperature)),
            'max_completion_tokens' => max(64, min(LlmEnv::MAX_COMPLETION_TOKENS_CAP, $maxTokens)),
            'input_payload'         => ['source' => 'admin_llm_test'],
        ]);

        if ($jobId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'LLM job se nepodařilo spustit.']);
            exit;
        }

        if (!GenerationJobService::waitForCompletion($jobId, 120, false)) {
            echo json_encode(['ok' => false, 'error' => "LLM job #{$jobId} nedokončen."]);
            exit;
        }

        $output = GenerationJobService::getOutput($jobId);
        if ($output === null) {
            echo json_encode(['ok' => false, 'error' => "LLM job #{$jobId} nemá output."]);
            exit;
        }

        echo json_encode([
            'ok'       => true,
            'response' => (string) ($output['raw_text'] ?? ''),
            'model'    => (string) ($output['model'] ?? $model),
            'provider' => (string) ($output['provider'] ?? 'openai'),
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
});

$router->get('/admin/llm-logs', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: ' . Url::u('/'));
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

    $date = trim((string) ($_POST['log_date'] ?? ''));
    if ($date === '') {
        $filename = (string) ($_POST['filename'] ?? '');
        if (preg_match('/^llm_(\d{4}-\d{2}-\d{2})\.db$/', $filename, $m)) {
            $date = $m[1];
        }
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Neplatné datum logu.']);
        exit;
    }

    header('Content-Type: application/json');
    try {
        if (!Database::isPostgres()) {
            $path = Database::getTenantDataDir() . '/llm_' . $date . '.db';
            if (!file_exists($path)) {
                echo json_encode(['ok' => false, 'error' => 'Soubor neexistuje.']);
                exit;
            }
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $rows = $pdo->query(
                'SELECT id, request_at, provider, model, user_id, prompt_system, prompt_user,
                        response_text, tokens_in, tokens_out, duration_ms, status, error_message
                 FROM llm_log ORDER BY id DESC'
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = Database::fetchLlmLogRowsForDate($date);
        }
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
        header('Location: ' . Url::u('/'));
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
                Database::buildInsertOrIgnore('weeks', 'week_number, year', '?, ?', 'week_number, year')
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

    $lastSeq        = 0;
    $maxWaitSec     = 600;
    $startTime      = time();
    $keepaliveTick  = 0;

    while (!connection_aborted() && (time() - $startTime) < $maxWaitSec) {
        $stmt = $db->prepare(
            'SELECT status, chunk_count, error_message, job_type, projection_status, projection_error_message
             FROM generation_jobs
             WHERE id = ?'
        );
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if ($job === false) {
            echo "data: " . json_encode(['type' => 'error', 'error' => 'Job nenalezen']) . "\n\n";
            flush();
            break;
        }

        $chunkStmt = $db->prepare(
            'SELECT seq_no, chunk_text
             FROM generation_job_chunks
             WHERE job_id = ? AND seq_no > ?
             ORDER BY seq_no ASC
             LIMIT 200'
        );
        $chunkStmt->execute([$jobId, $lastSeq]);
        $chunks = $chunkStmt->fetchAll();
        if (!empty($chunks)) {
            foreach ($chunks as $chunkRow) {
                $text = (string) ($chunkRow['chunk_text'] ?? '');
                $seq  = (int) ($chunkRow['seq_no'] ?? 0);
                if ($text === '' || $seq <= $lastSeq) {
                    continue;
                }
                echo "data: " . json_encode(
                    ['type' => 'chunk', 'text' => $text, 'count' => $seq],
                    JSON_UNESCAPED_UNICODE
                ) . "\n\n";
                flush();
                $lastSeq = $seq;
            }
        }

        if ($job['status'] === 'done' && $job['job_type'] === 'mealplan_generate' && $job['projection_status'] === 'pending') {
            \Aidelnicek\GenerationJobProjector::projectJob($jobId);
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();
        }

        if (
            $job['status'] === 'error'
            || ($job['status'] === 'done' && $job['projection_status'] === 'error')
            || (
                $job['status'] === 'done'
                && ($job['job_type'] !== 'mealplan_generate' || $job['projection_status'] === 'done')
            )
        ) {
            $doneStatus = ($job['status'] === 'error' || $job['projection_status'] === 'error') ? 'error' : 'done';
            $errorText = $doneStatus === 'error'
                ? ((string) ($job['projection_error_message'] ?: $job['error_message'] ?: 'Neznámá chyba'))
                : null;
            echo "data: " . json_encode(
                ['type' => 'done', 'status' => $doneStatus, 'error' => $errorText],
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

$router->get('/llm/jobs-running-count', function () {
    Auth::requireLogin();

    header('Content-Type: application/json');

    try {
        $stmt = Database::get()->query(
            "SELECT COUNT(*) AS cnt
             FROM generation_jobs
             WHERE status IN ('pending', 'running')
                OR (job_type = 'mealplan_generate' AND status = 'done' AND projection_status IN ('pending', 'processing'))"
        );
        $row   = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        $count = $row !== false ? (int) ($row['cnt'] ?? 0) : 0;

        echo json_encode(['ok' => true, 'count' => $count]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'count' => 0]);
    }

    exit;
});

// ── M3: Jídelníček ────────────────────────────────────────────────────────────

$router->get('/plan', function () {
    Auth::requireLogin();
    $week     = MealPlan::getOrCreateCurrentWeek();
    $todayIso = (int) date('N');
    header('Location: ' . Url::u(Url::planDayPath($todayIso, $week)));
    exit;
});

$router->get('/share/plan', function () use ($projectRoot) {
    $token = trim($_GET['token'] ?? '');
    $share = PlanShare::validateShareToken($token);

    if ($share === null || !in_array($share['scope'], ['week', 'day'], true)) {
        http_response_code(403);
        $pageTitle = 'Sdílený jídelníček';
        $content   = '<section class="error-page"><h1>Odkaz už není platný</h1><p>Odkaz pro sdílení jídelníčku je neplatný nebo vypršel.</p></section>';
        $sharedPage = true;
        require $projectRoot . '/templates/layout.php';
        exit;
    }

    $userId = (int) $share['user_id'];
    $weekId = (int) ($share['week_id'] ?? 0);
    $week   = MealPlan::getWeekById($weekId);
    if ($week === null || !MealPlan::hasPlansForWeek($userId, $weekId)) {
        http_response_code(404);
        $pageTitle = 'Sdílený jídelníček';
        $content   = '<section class="error-page"><h1>Jídelníček nenalezen</h1><p>Požadovaný sdílený jídelníček nebyl nalezen.</p></section>';
        $sharedPage = true;
        require $projectRoot . '/templates/layout.php';
        exit;
    }

    MealPlan::ensureSingleChosenPerSlot($userId, $weekId);

    $shareExpiresAt = (int) $share['expires'];
    $shareExpiresLabel = date('j. n. Y H:i', $shareExpiresAt);
    if ($share['scope'] === 'day') {
        $sharedWeekUrl = PlanShare::getSignedPlanUrl(
            $userId,
            $weekId,
            null,
            PlanShare::getDefaultValidityHours(),
            $shareExpiresAt
        );
        $day      = (int) ($share['day'] ?? 1);
        $dayPlan  = MealPlan::getChosenDayPlan($userId, $weekId, $day);
        $pageTitle = 'Sdílený denní jídelníček';
        $sharedPage = true;
        require $projectRoot . '/templates/shared_day_plan.php';
        exit;
    }

    $weekPlan    = MealPlan::getChosenWeekPlan($userId, $weekId);
    $pageTitle   = 'Sdílený týdenní jídelníček';
    $sharedPage  = true;
    require $projectRoot . '/templates/shared_week_plan.php';
    exit;
});

$router->get('/share/recipe', function () use ($projectRoot) {
    $token = trim($_GET['token'] ?? '');
    $share = PlanShare::validateShareToken($token);

    if ($share === null || $share['scope'] !== 'recipe') {
        http_response_code(403);
        $pageTitle = 'Sdílený recept';
        $content   = '<section class="error-page"><h1>Odkaz už není platný</h1><p>Odkaz pro sdílení receptu je neplatný nebo vypršel.</p></section>';
        $sharedPage = true;
        require $projectRoot . '/templates/layout.php';
        exit;
    }

    $userId = (int) $share['user_id'];
    $planId = (int) ($share['plan_id'] ?? 0);
    $weekId = (int) ($share['week_id'] ?? 0);
    $day    = (int) ($share['day'] ?? 1);

    $plan = MealPlan::getPlanByIdForUser($userId, $planId);
    if ($plan === null || (int) ($plan['week_id'] ?? 0) !== $weekId || (int) ($plan['day_of_week'] ?? 0) !== $day) {
        http_response_code(404);
        $pageTitle = 'Sdílený recept';
        $content   = '<section class="error-page"><h1>Recept nenalezen</h1><p>Požadovaný sdílený recept nebyl nalezen.</p></section>';
        $sharedPage = true;
        require $projectRoot . '/templates/layout.php';
        exit;
    }

    $recipe = MealRecipe::getRecipeForSharedView($userId, $planId);
    if ($recipe === null) {
        http_response_code(404);
        $pageTitle = 'Sdílený recept';
        $content   = '<section class="error-page"><h1>Recept nenalezen</h1><p>Recept pro toto jídlo zatím není k dispozici.</p></section>';
        $sharedPage = true;
        require $projectRoot . '/templates/layout.php';
        exit;
    }

    $week = MealPlan::getWeekById($weekId);
    $shareExpiresAt = (int) $share['expires'];
    $sharedPlanBackUrl = $week !== null
        ? PlanShare::getSignedPlanUrl($userId, $weekId, $day, PlanShare::getDefaultValidityHours(), $shareExpiresAt)
        : Url::u('/share/plan?token=' . urlencode($token));

    $pageTitle = (string) $recipe['meal_name'];
    $recipeBackUrl = $sharedPlanBackUrl;
    $sharedPage = true;
    ob_start();
    require $projectRoot . '/templates/recipe_view.php';
    $content = ob_get_clean();
    require $projectRoot . '/templates/layout.php';
    exit;
});

$router->get('/plan/day', function () use ($projectRoot) {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $todayIso = (int) date('N'); // 1=Mon … 7=Sun

    $day = isset($_GET['day']) ? (int) $_GET['day'] : $todayIso;
    $day = max(1, min(7, $day));

    $reqWeek = isset($_GET['week']) ? (int) $_GET['week'] : null;
    $reqYear = isset($_GET['year']) ? (int) $_GET['year'] : null;
    $week    = MealPlan::resolveWeekFromRequest($reqWeek, $reqYear);
    $weekId  = (int) $week['id'];

    parse_str($_SERVER['QUERY_STRING'] ?? '', $planDayQueryParams);
    $canonicalOk = isset($planDayQueryParams['day'], $planDayQueryParams['week'], $planDayQueryParams['year'])
        && (int) $planDayQueryParams['day'] === $day
        && (int) $planDayQueryParams['week'] === (int) $week['week_number']
        && (int) $planDayQueryParams['year'] === (int) $week['year'];
    if (!$canonicalOk) {
        header('Location: ' . Url::u('/plan/day?' . Url::planDayQuery($day, $week)));
        exit;
    }

    MealPlan::ensureSingleChosenPerSlot($userId, $weekId);
    $dayPlan = MealPlan::getDayPlan($userId, $weekId, $day);
    $weekPlan = MealPlan::getWeekPlan($userId, $weekId);
    $householdSelections = MealPlan::getHouseholdSelectionsForDay($userId, $weekId, $day);

    $shareSignedUrl = PlanShare::toAbsoluteUrl(PlanShare::getSignedPlanUrl($userId, $weekId, $day));
    $shareValidityHours = PlanShare::getDefaultValidityHours();
    $currentUser = $user;
    require $projectRoot . '/templates/day_plan.php';
});

$router->get('/plan/day/meal', function () use ($projectRoot) {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $todayIso = (int) date('N');

    $day      = isset($_GET['day']) ? (int) $_GET['day'] : $todayIso;
    $day      = max(1, min(7, $day));
    $mealType = isset($_GET['meal_type']) ? (string) $_GET['meal_type'] : '';

    $reqWeek = isset($_GET['week']) ? (int) $_GET['week'] : null;
    $reqYear = isset($_GET['year']) ? (int) $_GET['year'] : null;
    $week    = MealPlan::resolveWeekFromRequest($reqWeek, $reqYear);
    $weekId  = (int) $week['id'];

    $validMealTypes = ['breakfast', 'snack_am', 'lunch', 'snack_pm', 'dinner'];
    if (!in_array($mealType, $validMealTypes, true)) {
        header('Location: ' . Url::u(Url::planDayPath($day, $week)));
        exit;
    }

    parse_str($_SERVER['QUERY_STRING'] ?? '', $mealQueryParams);
    $canonicalMealOk = isset($mealQueryParams['day'], $mealQueryParams['meal_type'], $mealQueryParams['week'], $mealQueryParams['year'])
        && (int) $mealQueryParams['day'] === $day
        && (string) $mealQueryParams['meal_type'] === $mealType
        && (int) $mealQueryParams['week'] === (int) $week['week_number']
        && (int) $mealQueryParams['year'] === (int) $week['year'];
    if (!$canonicalMealOk) {
        header('Location: ' . Url::u(Url::planDayMealPath($day, $mealType, $week)));
        exit;
    }

    MealPlan::ensureSingleChosenPerSlot($userId, $weekId);
    $dayPlan = MealPlan::getDayPlan($userId, $weekId, $day);
    $householdSelections = MealPlan::getHouseholdSelectionsForDay($userId, $weekId, $day);
    $slotDetail = MealPlan::getHouseholdSlotDetail($weekId, $day, $mealType);

    $slot = $dayPlan[$mealType] ?? ['alt1' => null, 'alt2' => null];
    $alt1 = $slot['alt1'];
    $alt2 = $slot['alt2'];
    $chosenAltNum = null;
    foreach ([1 => $alt1, 2 => $alt2] as $candidateAltNum => $candidateAlt) {
        if ($candidateAlt !== null && (int) ($candidateAlt['is_chosen'] ?? 0) === 1) {
            $chosenAltNum = $candidateAltNum;
            break;
        }
    }
    if ($chosenAltNum === null) {
        $chosenAltNum = $alt1 !== null ? 1 : ($alt2 !== null ? 2 : null);
    }
    $chosenAlt = $chosenAltNum !== null ? ($chosenAltNum === 1 ? $alt1 : $alt2) : ($alt1 ?? $alt2);
    $otherAlt  = ($chosenAltNum === 1 ? $alt2 : $alt1);

    $weekStart = (new DateTimeImmutable())->setISODate((int) $week['year'], (int) $week['week_number'], 1);
    $dayDate   = $weekStart->modify('+' . ($day - 1) . ' days');
    $currentRedirect = Url::planDayPath($day, $week);

    $currentUser = $user;
    require $projectRoot . '/templates/meal_detail.php';
});

$router->post('/plan/swap', $requireCsrf('/plan/day', function () use ($projectRoot) {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $weekId   = isset($_POST['week_id']) ? (int) $_POST['week_id'] : 0;
    $dayA     = isset($_POST['day_a']) ? (int) $_POST['day_a'] : 0;
    $dayB     = isset($_POST['day_b']) ? (int) $_POST['day_b'] : 0;
    $mealType = isset($_POST['meal_type']) ? (string) $_POST['meal_type'] : '';
    $redirectTo = $_POST['redirect_to'] ?? '/plan/day';
    $isAjax   = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    $redirectTo = preg_replace('/[^a-zA-Z0-9\/?=&_-]/', '', $redirectTo) ?: '/plan/day';

    $validMealTypes = ['breakfast', 'snack_am', 'lunch', 'snack_pm', 'dinner'];
    if ($weekId <= 0 || $dayA < 1 || $dayA > 7 || $dayB < 1 || $dayB > 7
        || $dayA === $dayB || !in_array($mealType, $validMealTypes, true)) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'invalid params']);
            exit;
        }
        header('Location: ' . Url::tenantLocation($redirectTo));
        exit;
    }

    $swapScope = isset($_POST['swap_scope']) ? (string) $_POST['swap_scope'] : 'household';
    $ok = ($swapScope === 'user_only')
        ? MealPlan::swapSlots($userId, $weekId, $dayA, $dayB, $mealType)
        : MealPlan::swapSlotsForHousehold($userId, $weekId, $dayA, $dayB, $mealType);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok]);
        exit;
    }

    header('Location: ' . Url::tenantLocation($redirectTo));
    exit;
}));

$router->get('/plan/week', function () use ($projectRoot) {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $currentWeek = MealPlan::getOrCreateCurrentWeek();

    $requestedWeek = isset($_GET['week']) ? (int) $_GET['week'] : null;
    $requestedYear = isset($_GET['year']) ? (int) $_GET['year'] : null;

    if ($requestedWeek !== null && $requestedYear !== null) {
        $week = MealPlan::getOrCreateWeekByNumberAndYear($requestedWeek, $requestedYear);
    } else {
        $week = $currentWeek;
    }

    $weekId = (int) $week['id'];

    MealPlan::ensureSingleChosenPerSlot($userId, $weekId);
    $weekPlan = MealPlan::getWeekPlan($userId, $weekId);
    $todayIso = (int) date('N');
    $shareSignedUrl = PlanShare::toAbsoluteUrl(PlanShare::getSignedPlanUrl($userId, $weekId));
    $shareValidityHours = PlanShare::getDefaultValidityHours();

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
        header('Location: ' . Url::tenantLocation($redirectTo));
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

    header('Location: ' . Url::tenantLocation($redirectTo));
    exit;
}));

$router->post('/plan/choose-household', $requireCsrf('/plan/day', function () use ($projectRoot) {
    Auth::requireLogin();

    $planId     = isset($_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;
    $redirectTo = $_POST['redirect_to'] ?? '/plan/day';
    $isAjax     = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    if ($planId <= 0) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'invalid plan_id']);
            exit;
        }
        header('Location: ' . Url::tenantLocation($redirectTo));
        exit;
    }

    $ok = MealPlan::chooseAlternativeForHousehold($planId);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok]);
        exit;
    }

    header('Location: ' . Url::tenantLocation($redirectTo));
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
        header('Location: ' . Url::tenantLocation($redirectTo));
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

    header('Location: ' . Url::tenantLocation($redirectTo));
    exit;
}));

$router->post('/plan/recipe', $requireCsrf('/plan/day', function () {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $planId = isset($_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;

    header('Content-Type: application/json');

    if ($planId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'invalid plan_id']);
        exit;
    }

    $result = MealRecipe::startOrFetchForPlan($userId, $planId);
    if ($result === null) {
        echo json_encode(['ok' => false, 'error' => 'Recept se nepodařilo načíst.']);
        exit;
    }

    if (($result['status'] ?? '') === 'error') {
        echo json_encode([
            'ok' => false,
            'error' => (string) ($result['error'] ?? 'Generování receptu selhalo.'),
        ]);
        exit;
    }

    echo json_encode(array_merge(['ok' => true], $result), JSON_UNESCAPED_UNICODE);
    exit;
}));

$router->get('/plan/recipe-status', function () {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $planId = isset($_GET['plan_id']) ? (int) $_GET['plan_id'] : 0;
    $jobId  = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;

    header('Content-Type: application/json');

    if ($planId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'invalid plan_id']);
        exit;
    }

    $result = MealRecipe::getStatusForPlan($userId, $planId, $jobId > 0 ? $jobId : null);
    if ($result === null) {
        echo json_encode(['ok' => false, 'error' => 'Recept se nepodařilo načíst.']);
        exit;
    }

    if (($result['status'] ?? '') === 'error') {
        echo json_encode([
            'ok' => false,
            'error' => (string) ($result['error'] ?? 'Generování receptu selhalo.'),
        ]);
        exit;
    }

    echo json_encode(array_merge(['ok' => true], $result), JSON_UNESCAPED_UNICODE);
    exit;
});

$router->get('/plan/recipe/view', function () use ($projectRoot) {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $planId = isset($_GET['plan_id']) ? (int) $_GET['plan_id'] : 0;
    if ($planId <= 0) {
        $week     = MealPlan::getOrCreateCurrentWeek();
        $todayIso = (int) date('N');
        header('Location: ' . Url::u(Url::planDayPath($todayIso, $week)));
        exit;
    }

    $recipe = MealRecipe::getRecipeForView($userId, $planId);
    if ($recipe === null) {
        $pageTitle = 'Recept nenalezen';
        $backToPlan = MealRecipe::planDayBackPathForPlanId($userId, $planId);
        $content  = '<section class="error-page"><h1>Recept nenalezen</h1><p>Recept pro toto jídlo nebyl nalezen nebo ještě nebyl vygenerován.</p><a href="'
            . htmlspecialchars($backToPlan) . '" class="btn">Zpět na jídelníček</a></section>';
        require $projectRoot . '/templates/layout.php';
        exit;
    }

    $pageTitle = htmlspecialchars($recipe['meal_name']);
    $recipeBackUrl = MealRecipe::planDayBackPathForPlanId($userId, $planId);
    ob_start();
    require $projectRoot . '/templates/recipe_view.php';
    $content = ob_get_clean();
    require $projectRoot . '/templates/layout.php';
    exit;
});

// ── M5: Přegenerování jídelníčku (pouze admin / plánovač) ─────────────────────

$router->post('/plan/regenerate', $requireCsrf('/plan/week', function () {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: ' . Url::u('/plan/week'));
        exit;
    }

    $userId = (int) $user['id'];
    $weekId = isset($_POST['week_id']) ? (int) $_POST['week_id'] : 0;
    $week   = $weekId > 0 ? MealPlan::getWeekById($weekId) : null;

    if ($week === null) {
        $week   = MealPlan::getOrCreateCurrentWeek();
        $weekId = (int) $week['id'];
    }

    // Spustí generování přes LLM worker (fire-and-forget — uživatel nemusí čekat)
    MealGenerator::startGenerationJob($userId, (int) $week['id'], true);

    header('Location: ' . Url::u('/plan/week?week=' . (int) $week['week_number'] . '&year=' . (int) $week['year'] . '&generating=1'));
    exit;
}));

// ── M4: Nákupní seznam ────────────────────────────────────────────────────────

$router->get('/shopping/export', function () {
    $format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'csv';
    if (!in_array($format, ['csv', 'json'], true)) {
        $format = 'csv';
    }

    $weekId = null;
    $token  = trim($_GET['token'] ?? '');

    if ($token !== '') {
        $valid = ShoppingListExport::validateExportToken($token);
        if ($valid === null) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Neplatný nebo prošlý odkaz ke stažení.';
            exit;
        }
        $weekId = $valid['week_id'];
    } else {
        Auth::requireLogin();
        $week   = MealPlan::getOrCreateCurrentWeek();
        $weekId = (int) $week['id'];
    }

    $items = ShoppingListExport::getExportData($weekId);

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="nakupni-seznam.json"');
        echo ShoppingListExport::formatJson($items);
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="nakupni-seznam.csv"');
        echo ShoppingListExport::formatCsv($items);
    }
    exit;
});

$router->get('/shopping', function () use ($projectRoot) {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $week   = MealPlan::getOrCreateCurrentWeek();
    $weekId = (int) $week['id'];

    // Auto-generate shopping list if no auto-generated items exist yet
    ShoppingList::generateFromMealPlans($weekId);

    $baseUrl            = Url::absoluteBaseUrl();
    $exportSignedUrlCsv  = $baseUrl . ShoppingListExport::getSignedExportUrl($weekId, 'csv');
    $exportSignedUrlJson = $baseUrl . ShoppingListExport::getSignedExportUrl($weekId, 'json');

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
        header('Location: ' . Url::tenantLocation($redirectTo));
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

    header('Location: ' . Url::tenantLocation($redirectTo));
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
        header('Location: ' . Url::u('/shopping?error=name'));
        exit;
    }

    $id = ShoppingList::addItem($userId, $weekId, $name, $quantity, $unit, $category);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    header('Location: ' . Url::u('/shopping'));
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
        header('Location: ' . Url::tenantLocation($redirectTo));
        exit;
    }

    $ok = ShoppingList::removeItem($userId, $itemId);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok]);
        exit;
    }

    header('Location: ' . Url::tenantLocation($redirectTo));
    exit;
}));

$router->post('/shopping/clear', $requireCsrf('/shopping', function () {
    Auth::requireLogin();

    $week   = MealPlan::getOrCreateCurrentWeek();
    $weekId = (int) $week['id'];

    ShoppingList::clearPurchased($weekId);

    header('Location: ' . Url::u('/shopping'));
    exit;
}));

$router->post('/shopping/regenerate', $requireCsrf('/shopping', function () {
    $user   = Auth::requireLogin();
    $userId = (int) $user['id'];

    $week   = MealPlan::getOrCreateCurrentWeek();
    $weekId = (int) $week['id'];

    ShoppingList::generateFromMealPlans($weekId, true);

    header('Location: ' . Url::u('/shopping'));
    exit;
}));

// ── Pozvánky uživatelů (admin) ────────────────────────────────────────────────

$router->get('/admin/invite', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: ' . Url::u('/'));
        exit;
    }
    require $projectRoot . '/templates/admin_invite.php';
});

$router->post('/admin/invite', $requireCsrf('/admin/invite?error=csrf', function () use ($projectRoot) {
    $user = Auth::requireLogin();
    if (!User::isAdmin((int) $user['id'])) {
        header('Location: ' . Url::u('/'));
        exit;
    }

    $email        = mb_strtolower(trim($_POST['email'] ?? ''));
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
