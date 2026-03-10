<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

use Aidelnicek\Database;
use Aidelnicek\Router;

Database::init($projectRoot);

$router = new Router($projectRoot);

$router->get('/', function () use ($projectRoot) {
    require $projectRoot . '/templates/dashboard.php';
});

$router->get('/login', function () use ($projectRoot) {
    require $projectRoot . '/templates/login.php';
});

$router->get('/register', function () use ($projectRoot) {
    require $projectRoot . '/templates/register.php';
});

$router->dispatch();
