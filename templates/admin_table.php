<?php

declare(strict_types=1);

use Aidelnicek\Auth;
use Aidelnicek\Csrf;
use Aidelnicek\Database;
use Aidelnicek\User;

$user = Auth::requireLogin();
if (!User::isAdmin((int) $user['id'])) {
    header('Location: /');
    exit;
}

$db = Database::get();

// Načtení seznamu tabulek
$tableList = $db->query(
    "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

$perPage    = 25;
$table      = $_GET['table'] ?? '';
$page       = max(1, (int) ($_GET['page'] ?? 1));
$columns    = [];
$rows       = [];
$totalRows  = 0;
$totalPages = 1;
$error      = null;
$success    = $_GET['success'] ?? null;

// Bezpečnostní whitelist — název tabulky musí existovat v databázi
if ($table !== '' && !in_array($table, $tableList, true)) {
    $table = '';
    $error = 'Tabulka neexistuje.';
}

if ($table !== '') {
    $qt = '"' . str_replace('"', '""', $table) . '"';

    // Sloupce
    foreach ($db->query("PRAGMA table_info({$qt})") as $col) {
        $columns[] = $col;
    }

    // Celkový počet řádků
    $totalRows  = (int) $db->query("SELECT COUNT(*) FROM {$qt}")->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    // Data stránky
    $rows = $db->query("SELECT rowid AS __rowid, * FROM {$qt} LIMIT {$perPage} OFFSET {$offset}")->fetchAll();
}

$pageTitle   = 'Prohlížeč tabulek';
$currentUser = Auth::getCurrentUser();
ob_start();
?>
<div class="admin-table-browser">
    <div class="admin-page-header">
        <h1>Prohlížeč tabulek</h1>
        <a href="/admin" class="btn btn-secondary btn-sm">← Zpět na administraci</a>
    </div>

    <?php if ($error): ?>
        <p class="alert alert-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <?php if ($success === 'deleted'): ?>
        <p class="alert alert-success">Záznam byl smazán.</p>
    <?php endif; ?>
    <?php if ($success === 'updated'): ?>
        <p class="alert alert-success">Záznam byl upraven.</p>
    <?php endif; ?>

    <form method="get" action="/admin/table" class="table-select-form">
        <div class="form-row form-row--inline">
            <div class="form-group">
                <label for="table-select">Tabulka</label>
                <select id="table-select" name="table" onchange="this.form.submit()">
                    <option value="">— Vyberte tabulku —</option>
                    <?php foreach ($tableList as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>" <?= $t === $table ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Zobrazit</button>
        </div>
    </form>

    <?php if ($table !== '' && !empty($columns)): ?>
        <div class="table-meta">
            Tabulka <strong><?= htmlspecialchars($table) ?></strong>
            &mdash; celkem <?= $totalRows ?> řádků
            (stránka <?= $page ?> / <?= $totalPages ?>)
        </div>

        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <th><?= htmlspecialchars($col['name']) ?></th>
                        <?php endforeach; ?>
                        <th class="col-actions">Akce</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="<?= count($columns) + 1 ?>" class="text-muted text-center">Žádné záznamy</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $rowId  = $row['__rowid'];
                        $rowData = [];
                        foreach ($columns as $col) {
                            $rowData[$col['name']] = $row[$col['name']] ?? null;
                        }
                        ?>
                        <tr data-rowid="<?= htmlspecialchars((string) $rowId) ?>"
                            data-row="<?= htmlspecialchars(json_encode($rowData, JSON_UNESCAPED_UNICODE)) ?>">
                            <?php foreach ($columns as $col): ?>
                                <td class="cell-value"><?= htmlspecialchars((string) ($row[$col['name']] ?? '')) ?></td>
                            <?php endforeach; ?>
                            <td class="col-actions">
                                <button type="button"
                                        class="btn btn-sm btn-secondary edit-row-btn"
                                        data-rowid="<?= htmlspecialchars((string) $rowId) ?>"
                                        data-table="<?= htmlspecialchars($table) ?>">
                                    Upravit
                                </button>
                                <form method="post" action="/admin/table/delete" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="table" value="<?= htmlspecialchars($table) ?>">
                                    <input type="hidden" name="rowid" value="<?= htmlspecialchars((string) $rowId) ?>">
                                    <input type="hidden" name="page" value="<?= $page ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('Opravdu smazat tento záznam?')">
                                        Smazat
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Stránkování">
                <?php if ($page > 1): ?>
                    <a href="?table=<?= urlencode($table) ?>&page=<?= $page - 1 ?>" class="btn btn-sm btn-secondary">&larr; Předchozí</a>
                <?php endif; ?>
                <span class="pagination__info"><?= $page ?> / <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="?table=<?= urlencode($table) ?>&page=<?= $page + 1 ?>" class="btn btn-sm btn-secondary">Další &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php elseif ($table !== ''): ?>
        <p class="text-muted">Tabulka neobsahuje žádné sloupce.</p>
    <?php endif; ?>
</div>

<!-- Modální okno pro editaci záznamu -->
<div id="edit-modal" class="modal" hidden aria-modal="true" role="dialog" aria-labelledby="edit-modal-title">
    <div class="modal-backdrop" id="edit-modal-backdrop"></div>
    <div class="modal-content">
        <h2 id="edit-modal-title">Upravit záznam</h2>
        <form id="edit-form" method="post" action="/admin/table/update">
            <?= Csrf::field() ?>
            <input type="hidden" name="table" value="<?= htmlspecialchars($table) ?>">
            <input type="hidden" name="rowid" id="edit-rowid" value="">
            <input type="hidden" name="page" value="<?= $page ?>">
            <div id="edit-fields" class="edit-fields"></div>
            <div class="modal-actions">
                <button type="submit" class="btn btn-primary">Uložit</button>
                <button type="button" class="btn btn-secondary" id="close-modal-btn">Zrušit</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal      = document.getElementById('edit-modal');
    var backdrop   = document.getElementById('edit-modal-backdrop');
    var closeBtn   = document.getElementById('close-modal-btn');
    var editFields = document.getElementById('edit-fields');
    var editRowid  = document.getElementById('edit-rowid');

    function openModal(rowid, rowData) {
        editRowid.value = rowid;
        editFields.innerHTML = '';

        Object.keys(rowData).forEach(function (col) {
            var group = document.createElement('div');
            group.className = 'form-group';

            var label = document.createElement('label');
            label.textContent = col;
            label.setAttribute('for', 'edit-field-' + col);

            var val = rowData[col];
            var input;
            if (val !== null && String(val).length > 80) {
                input = document.createElement('textarea');
                input.rows = 4;
                input.value = val !== null ? String(val) : '';
            } else {
                input = document.createElement('input');
                input.type  = 'text';
                input.value = val !== null ? String(val) : '';
            }
            input.id   = 'edit-field-' + col;
            input.name = 'field_' + col;

            group.appendChild(label);
            group.appendChild(input);
            editFields.appendChild(group);
        });

        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        var firstInput = editFields.querySelector('input, textarea');
        if (firstInput) firstInput.focus();
    }

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.edit-row-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tr      = this.closest('tr');
            var rowid   = tr.getAttribute('data-rowid');
            var rowData = JSON.parse(tr.getAttribute('data-row'));
            openModal(rowid, rowData);
        });
    });

    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);
    document.addEventListener('keydown', function (e) {
        if (!modal.hidden && e.key === 'Escape') closeModal();
    });
}());
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
