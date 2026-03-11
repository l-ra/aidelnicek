<?php
$pageTitle = 'Administrace';
$currentUser = \Aidelnicek\Auth::getCurrentUser();
ob_start();
?>
<div class="admin-dashboard">
    <h1>Administrace</h1>

    <div class="admin-cards">
        <div class="admin-card">
            <h2>Správa databáze</h2>
            <p>Prohlížení a editace SQLite databáze aplikace prostřednictvím phpLiteAdmin.</p>
            <a href="/admin/phpliteadmin.php" class="btn btn-primary" target="_blank">
                Otevřít phpLiteAdmin
            </a>
        </div>

        <div class="admin-card">
            <h2>Informace o systému</h2>
            <dl class="info-list">
                <dt>PHP verze</dt>
                <dd><?= htmlspecialchars(PHP_VERSION) ?></dd>
                <dt>Databáze</dt>
                <dd><?= htmlspecialchars(realpath(\Aidelnicek\Database::getPath())) ?></dd>
                <dt>Přihlášený admin</dt>
                <dd><?= htmlspecialchars($currentUser['name'] ?? '—') ?></dd>
            </dl>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
