<?php

declare(strict_types=1);

use Aidelnicek\Auth;
use Aidelnicek\Csrf;
use Aidelnicek\Database;
use Aidelnicek\User;
use Aidelnicek\Url;

$user = Auth::requireLogin();
if (!User::isAdmin((int) $user['id'])) {
    header('Location: ' . Url::u('/'));
    exit;
}

$pageTitle   = 'LLM logy';
$currentUser = Auth::getCurrentUser();

// Collect available log files from tenant data/
$logFiles = [];
$dataDir  = Database::getTenantDataDir();
if (is_dir($dataDir)) {
    foreach (glob($dataDir . '/llm_*.db') as $path) {
        $basename = basename($path);
        if (preg_match('/^llm_\d{4}-\d{2}-\d{2}\.db$/', $basename)) {
            $logFiles[] = $basename;
        }
    }
    rsort($logFiles); // nejnovější první
}

ob_start();
?>
<div class="llm-logs-page">
    <div class="admin-page-header">
        <h1>LLM komunikační logy</h1>
        <a href="<?= Url::hu('/admin') ?>" class="btn btn-secondary btn-sm">← Zpět na administraci</a>
    </div>

    <?php if (empty($logFiles)): ?>
        <div class="alert alert-info llm-logs-empty">
            Zatím nebyly nalezeny žádné log soubory. Logy se vytváří automaticky při prvním volání LLM.
        </div>
    <?php else: ?>
        <div class="admin-card llm-logs-selector">
            <h2>Výběr log souboru</h2>
            <div class="llm-logs-form-row">
                <div class="form-group">
                    <label for="llm-log-file">Datum logu</label>
                    <select id="llm-log-file" class="form-control">
                        <?php foreach ($logFiles as $f): ?>
                            <?php
                            // Extract date from filename: llm_YYYY-MM-DD.db → YYYY-MM-DD
                            $date = preg_replace('/^llm_(.+)\.db$/', '$1', $f);
                            ?>
                            <option value="<?= htmlspecialchars($f) ?>">
                                <?= htmlspecialchars($date) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="llm-logs-btn-wrap">
                    <button id="llm-logs-load-btn" class="btn btn-primary">Zobrazit logy</button>
                    <span id="llm-logs-status" class="sql-status" aria-live="polite"></span>
                </div>
            </div>
        </div>

        <div id="llm-logs-results" class="llm-logs-results" hidden>
            <div class="llm-logs-results-header">
                <h2 id="llm-logs-results-title">Záznamy</h2>
                <span id="llm-logs-results-count" class="llm-logs-count"></span>
            </div>
            <p class="llm-logs-hint">Kliknutím na řádek zobrazíte plný obsah promptů a odpovědi.</p>
            <div class="table-container">
                <table class="data-table llm-logs-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Datum a čas</th>
                            <th>Provider / Model</th>
                            <th>User ID</th>
                            <th>Systémový prompt</th>
                            <th>Uživatelský prompt</th>
                            <th>Odpověď</th>
                            <th>Tokeny (in/out)</th>
                            <th>Trvání (ms)</th>
                            <th>Status</th>
                            <th>Chyba</th>
                        </tr>
                    </thead>
                    <tbody id="llm-logs-tbody"></tbody>
                </table>
            </div>

            <div id="llm-logs-detail" class="llm-logs-detail" hidden>
                <div class="llm-logs-detail-header">
                    <h3>Detail záznamu <span id="llm-logs-detail-id" class="llm-logs-detail-id"></span></h3>
                    <button id="llm-logs-detail-close" class="btn btn-secondary btn-sm" type="button">Zavřít ✕</button>
                </div>
                <div class="llm-logs-detail-sections">
                    <div class="llm-logs-detail-section" id="llm-logs-detail-system-wrap">
                        <h4>Systémový prompt</h4>
                        <pre class="llm-logs-detail-code"><code id="llm-logs-detail-system"></code></pre>
                    </div>
                    <div class="llm-logs-detail-section" id="llm-logs-detail-user-wrap">
                        <h4>Uživatelský prompt</h4>
                        <pre class="llm-logs-detail-code"><code id="llm-logs-detail-user"></code></pre>
                    </div>
                    <div class="llm-logs-detail-section" id="llm-logs-detail-response-wrap">
                        <h4>Odpověď</h4>
                        <pre class="llm-logs-detail-code"><code id="llm-logs-detail-response"></code></pre>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($logFiles)): ?>
<script>
(function () {
    var csrfMeta  = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    var fileSelect   = document.getElementById('llm-log-file');
    var loadBtn      = document.getElementById('llm-logs-load-btn');
    var statusEl     = document.getElementById('llm-logs-status');
    var resultsBox   = document.getElementById('llm-logs-results');
    var resultsTitle = document.getElementById('llm-logs-results-title');
    var resultsCount = document.getElementById('llm-logs-results-count');
    var tbody        = document.getElementById('llm-logs-tbody');

    var detailBox      = document.getElementById('llm-logs-detail');
    var detailIdEl     = document.getElementById('llm-logs-detail-id');
    var detailSystem   = document.getElementById('llm-logs-detail-system');
    var detailUser     = document.getElementById('llm-logs-detail-user');
    var detailResponse = document.getElementById('llm-logs-detail-response');
    var detailClose    = document.getElementById('llm-logs-detail-close');

    var TRUNCATE_LEN = 120;
    var activeRow = null;

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function truncate(str, len) {
        if (!str) return '';
        return str.length > len ? str.slice(0, len) + '…' : str;
    }

    function makeTd(text, isNull, extraClass) {
        var td = document.createElement('td');
        if (isNull || text === null || text === undefined) {
            td.textContent = '–';
            td.classList.add('text-muted');
        } else {
            td.textContent = text;
        }
        if (extraClass) td.classList.add(extraClass);
        return td;
    }

    function makePreviewTd(fullText) {
        var td = document.createElement('td');
        td.classList.add('llm-cell-preview');
        if (!fullText) {
            td.textContent = '–';
            td.classList.add('text-muted');
        } else {
            td.textContent = truncate(fullText, TRUNCATE_LEN);
        }
        return td;
    }

    function hideDetail() {
        if (activeRow) {
            activeRow.classList.remove('llm-log-row--active');
            activeRow = null;
        }
        detailBox.hidden = true;
    }

    function showDetail(row, trEl) {
        if (activeRow === trEl) {
            hideDetail();
            return;
        }
        if (activeRow) activeRow.classList.remove('llm-log-row--active');
        activeRow = trEl;
        trEl.classList.add('llm-log-row--active');

        detailIdEl.textContent     = '#' + row.id;
        detailSystem.textContent   = row.prompt_system  || '(prázdný)';
        detailUser.textContent     = row.prompt_user    || '(prázdný)';
        detailResponse.textContent = row.response_text  || '(prázdná)';

        detailBox.hidden = false;
        detailBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    detailClose.addEventListener('click', hideDetail);

    function renderRows(rows) {
        hideDetail();
        tbody.innerHTML = '';
        if (rows.length === 0) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 11;
            td.textContent = 'Soubor neobsahuje žádné záznamy.';
            td.classList.add('text-muted');
            tr.appendChild(td);
            tbody.appendChild(tr);
            return;
        }

        rows.forEach(function (row) {
            var tr = document.createElement('tr');
            tr.className = 'llm-log-row';

            tr.appendChild(makeTd(row.id));
            tr.appendChild(makeTd(row.request_at));

            // Provider / model combined cell
            var tdPm = document.createElement('td');
            tdPm.innerHTML = escHtml(row.provider || '–') + '<br><small class="text-muted">' + escHtml(row.model || '–') + '</small>';
            tr.appendChild(tdPm);

            tr.appendChild(makeTd(row.user_id, row.user_id === null));
            tr.appendChild(makePreviewTd(row.prompt_system));
            tr.appendChild(makePreviewTd(row.prompt_user));
            tr.appendChild(makePreviewTd(row.response_text));

            // Tokens
            var tokensVal = (row.tokens_in !== null || row.tokens_out !== null)
                ? (row.tokens_in ?? '?') + ' / ' + (row.tokens_out ?? '?')
                : null;
            tr.appendChild(makeTd(tokensVal, tokensVal === null));

            tr.appendChild(makeTd(row.duration_ms, row.duration_ms === null));

            // Status with color badge
            var tdStatus = document.createElement('td');
            var badge = document.createElement('span');
            badge.textContent = row.status || '–';
            badge.className = 'llm-status-badge llm-status-badge--' + (row.status === 'ok' ? 'ok' : 'error');
            tdStatus.appendChild(badge);
            tr.appendChild(tdStatus);

            tr.appendChild(makeTd(row.error_message, !row.error_message));

            tr.addEventListener('click', function () { showDetail(row, tr); });
            tbody.appendChild(tr);
        });
    }

    function loadLogs() {
        var filename = fileSelect.value;
        if (!filename) return;

        loadBtn.disabled     = true;
        statusEl.textContent = 'Načítám…';
        statusEl.className   = 'sql-status';
        resultsBox.hidden    = true;

        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('filename',   filename);

        fetch(<?= json_encode(Url::u('/admin/llm-logs/data'), JSON_UNESCAPED_SLASHES) ?>, {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:    fd,
        })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function (data) {
            loadBtn.disabled = false;

            if (!data.ok) {
                statusEl.textContent = 'Chyba.';
                statusEl.className   = 'sql-status sql-status--error';
                resultsBox.hidden    = false;
                tbody.innerHTML      = '<tr><td colspan="11" class="sql-error">Chyba: ' + escHtml(data.error) + '</td></tr>';
                return;
            }

            statusEl.textContent = 'Načteno.';
            statusEl.className   = 'sql-status sql-status--ok';
            resultsBox.hidden    = false;

            // Extract date from filename
            var dateMatch = filename.match(/^llm_(.+)\.db$/);
            var dateStr   = dateMatch ? dateMatch[1] : filename;
            resultsTitle.textContent = 'Záznamy — ' + dateStr;
            resultsCount.textContent = data.rows.length + ' záznamů';

            renderRows(data.rows);
        })
        .catch(function (err) {
            loadBtn.disabled     = false;
            statusEl.textContent = 'Chyba spojení.';
            statusEl.className   = 'sql-status sql-status--error';
            resultsBox.hidden    = false;
            tbody.innerHTML      = '<tr><td colspan="11" class="sql-error">Chyba: ' + escHtml(err.message) + '</td></tr>';
        });
    }

    loadBtn.addEventListener('click', loadLogs);
}());
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
