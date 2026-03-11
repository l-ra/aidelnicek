<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Aidelnicek';
}
$currentUser = \Aidelnicek\Auth::getCurrentUser();
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Aidelnicek</title>
    <link rel="stylesheet" href="/css/style.css">
    <meta name="csrf-token" content="<?= htmlspecialchars(\Aidelnicek\Csrf::generate()) ?>">
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a href="/" class="logo">Aidelnicek</a>
            <nav class="main-nav">
                <a href="/">Dashboard</a>
                <?php if ($currentUser): ?>
                    <a href="/plan">Jídelníček</a>
                    <span class="nav-user"><?= htmlspecialchars($currentUser['name']) ?></span>
                    <a href="/profile">Profil</a>
                    <form method="post" action="/logout" class="nav-logout">
                        <?= \Aidelnicek\Csrf::field() ?>
                        <button type="submit" class="btn-link">Odhlásit</button>
                    </form>
                <?php else: ?>
                    <a href="/login">Přihlásit</a>
                    <a href="/register">Registrace</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
    <main class="site-main">
        <div class="container">
            <?= $content ?? '' ?>
        </div>
    </main>
    <footer class="site-footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> Aidelnicek — Zdravé stravování pro domácnost</p>
        </div>
    </footer>
    <script src="/js/app.js"></script>
</body>
</html>
