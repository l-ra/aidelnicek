<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

use Aidelnicek\Auth;
use Aidelnicek\Database;
use Aidelnicek\Router;
use Aidelnicek\User;

Database::init($projectRoot);
Auth::init();

$router = new Router($projectRoot);

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

$router->post('/login', function () {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($email === '' || $password === '') {
        header('Location: /login?error=missing');
        exit;
    }
    $user = User::verifyPassword($email, $password);
    if ($user === null) {
        header('Location: /login?error=invalid');
        exit;
    }
    Auth::login((int) $user['id']);
    header('Location: /');
    exit;
});

$router->get('/register', function () use ($projectRoot) {
    if (Auth::isLoggedIn()) {
        header('Location: /');
        exit;
    }
    require $projectRoot . '/templates/register.php';
});

$router->post('/register', function () use ($projectRoot) {
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
});

$router->get('/profile', function () use ($projectRoot) {
    Auth::requireLogin();
    require $projectRoot . '/templates/profile.php';
});

$router->post('/profile', function () use ($projectRoot) {
    Auth::requireLogin();
    require $projectRoot . '/templates/profile.php';
});

$router->post('/logout', function () {
    Auth::logout();
    header('Location: /login');
    exit;
});

$router->dispatch();
