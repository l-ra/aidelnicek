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
            <button class="nav-toggle" aria-label="Otevřít menu" aria-expanded="false" aria-controls="main-nav">
                <span class="nav-toggle__bar"></span>
                <span class="nav-toggle__bar"></span>
                <span class="nav-toggle__bar"></span>
            </button>
            <nav class="main-nav" id="main-nav">
                <a href="/">Dashboard</a>
                <?php if ($currentUser): ?>
                    <a href="/plan">Jídelníček</a>
                    <a href="/shopping">Nákupní seznam</a>
                    <?php if (!empty($currentUser['is_admin'])): ?>
                        <a href="/admin" class="nav-admin">Administrace</a>
                    <?php endif; ?>
                    <span
                        class="nav-llm-jobs"
                        id="llm-jobs-indicator"
                        data-poll-url="/llm/jobs-running-count"
                        aria-live="polite"
                        aria-label="Rozpracované LLM joby: 0"
                        title="Rozpracované LLM joby: 0"
                    >
                        <span class="nav-llm-jobs__icon" aria-hidden="true">&#129302;</span>
                        <span class="nav-llm-jobs__count">0</span>
                    </span>
                    <span class="nav-user"><?= htmlspecialchars($currentUser['name']) ?></span>
                    <a href="/profile">Profil</a>
                    <form method="post" action="/logout" class="nav-logout">
                        <?= \Aidelnicek\Csrf::field() ?>
                        <button type="submit" class="btn-link">Odhlásit</button>
                    </form>
                <?php else: ?>
                    <a href="/login">Přihlásit</a>
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
