<?php
$pageTitle = 'Administrace';
$currentUser = \Aidelnicek\Auth::getCurrentUser();
ob_start();
?>
<div class="admin-dashboard">
    <h1>Administrace</h1>

    <div class="admin-cards">
        <div class="admin-card">
            <h2>Prohlížeč tabulek</h2>
            <p>Zobrazení, úprava a mazání záznamů v tabulkách databáze se stránkováním.</p>
            <a href="/admin/table" class="btn btn-primary">Otevřít prohlížeč</a>
        </div>

        <div class="admin-card">
            <h2>SQL konzole</h2>
            <p>Spouštění libovolných SQL příkazů přímo nad databází. Historie příkazů se ukládá v prohlížeči.</p>
            <a href="/admin/sql" class="btn btn-primary">Otevřít konzoli</a>
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
