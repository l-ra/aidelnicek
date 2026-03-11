<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

use Aidelnicek\Auth;
use Aidelnicek\Csrf;
use Aidelnicek\Database;
use Aidelnicek\Router;
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
        'name' => trim($_POST['name'] ?? ''),
        'gender' => $_POST['gender'] ?? null,
        'age' => $_POST['age'] ?? null,
        'body_type' => $_POST['body_type'] ?? null,
        'dietary_notes' => trim($_POST['dietary_notes'] ?? '') ?: null,
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

$router->dispatch();
