<?php
use Aidelnicek\Url;

$pageTitle = 'Stránka nenalezena';
$content = '<section class="error-page"><h1>404</h1><p>Stránka nebyla nalezena.</p><a href="'
    . Url::hu('/') . '" class="btn">Zpět na hlavní stránku</a></section>';
require __DIR__ . '/layout.php';
