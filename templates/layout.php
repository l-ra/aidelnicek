<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Aidelnicek';
}
$currentUser = \Aidelnicek\Auth::getCurrentUser();
$u = static fn (string $path): string => htmlspecialchars(\Aidelnicek\Url::u($path));
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
<body data-base-path="<?php
$__ts = \Aidelnicek\TenantContext::slug();
echo htmlspecialchars($__ts !== null && $__ts !== '' ? '/' . $__ts : '');
?>">
    <?php if ($currentUser && ($__ts = \Aidelnicek\TenantContext::slug()) !== null && $__ts !== ''): ?>
    <script>
    try { localStorage.setItem('aidelnicek_tenant', <?= json_encode($__ts, JSON_UNESCAPED_UNICODE) ?>); } catch (e) {}
    </script>
    <?php endif; ?>
    <header class="site-header">
        <div class="container">
            <a href="<?= $u('/') ?>" class="logo">Aidelnicek</a>
            <button class="nav-toggle" aria-label="Otevřít menu" aria-expanded="false" aria-controls="main-nav">
                <span class="nav-toggle__bar"></span>
                <span class="nav-toggle__bar"></span>
                <span class="nav-toggle__bar"></span>
            </button>
            <nav class="main-nav" id="main-nav">
                <a href="<?= $u('/') ?>">Dashboard</a>
                <?php if ($currentUser): ?>
                    <a href="<?= $u('/plan') ?>">Jídelníček</a>
                    <a href="<?= $u('/shopping') ?>">Nákupní seznam</a>
                    <?php if (!empty($currentUser['is_admin'])): ?>
                        <a href="<?= $u('/admin') ?>" class="nav-admin">Administrace</a>
                    <?php endif; ?>
                    <span
                        class="nav-llm-jobs"
                        id="llm-jobs-indicator"
                        data-poll-url="<?= $u('/llm/jobs-running-count') ?>"
                        aria-live="polite"
                        aria-label="Rozpracované LLM joby: 0"
                        title="Rozpracované LLM joby: 0"
                    >
                        <span class="nav-llm-jobs__icon" aria-hidden="true">&#129302;</span>
                        <span class="nav-llm-jobs__count">0</span>
                    </span>
                    <span class="nav-user"><?= htmlspecialchars($currentUser['name']) ?></span>
                    <a href="<?= $u('/profile') ?>">Profil</a>
                    <form method="post" action="<?= $u('/logout') ?>" class="nav-logout">
                        <?= \Aidelnicek\Csrf::field() ?>
                        <button type="submit" class="btn-link">Odhlásit</button>
                    </form>
                <?php else: ?>
                    <a href="<?= $u('/login') ?>">Přihlásit</a>
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
            <?php
            $versionInfo = \Aidelnicek\Version::get(dirname(__DIR__));
            if ($versionInfo !== null):
            ?>
            <p class="site-footer__version">verze <?= htmlspecialchars($versionInfo['version']) ?> · sestaveno <?= htmlspecialchars($versionInfo['build_date']) ?></p>
            <?php endif; ?>
        </div>
    </footer>
    <script>window.AIDELNICEK_BASE_PATH = <?= json_encode(\Aidelnicek\Url::basePath(), JSON_UNESCAPED_SLASHES) ?>;</script>
    <?php if (!empty($planDayJsDefault) && is_array($planDayJsDefault)): ?>
    <script>window.AIDELNICEK_PLAN_DAY_DEFAULT = <?= json_encode([
        'day'  => (int) ($planDayJsDefault['day'] ?? 1),
        'week' => (int) ($planDayJsDefault['week'] ?? 0),
        'year' => (int) ($planDayJsDefault['year'] ?? 0),
    ], JSON_UNESCAPED_UNICODE) ?>;</script>
    <?php endif; ?>
    <script src="/js/app.js"></script>
</body>
</html>
