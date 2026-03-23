<?php

declare(strict_types=1);

use Aidelnicek\Auth;
use Aidelnicek\Csrf;
use Aidelnicek\User;
use Aidelnicek\Url;

$user = Auth::requireLogin();
if (!User::isAdmin((int) $user['id'])) {
    header('Location: ' . Url::u('/'));
    exit;
}

$pageTitle   = 'SQL konzole';
$currentUser = Auth::getCurrentUser();
ob_start();
?>
<div class="sql-console">
    <div class="admin-page-header">
        <h1>SQL konzole</h1>
        <a href="<?= Url::hu('/admin') ?>" class="btn btn-secondary btn-sm">← Zpět na administraci</a>
    </div>

    <div class="sql-layout">
        <div class="sql-main">
            <div class="form-group">
                <label for="sql-input">SQL příkaz</label>
                <textarea id="sql-input" class="sql-textarea" rows="6"
                          placeholder="SELECT * FROM users LIMIT 10;"></textarea>
            </div>
            <div class="sql-toolbar">
                <button id="sql-run-btn" class="btn btn-primary">Spustit</button>
                <button id="sql-clear-btn" class="btn btn-secondary">Vymazat</button>
                <span id="sql-status" class="sql-status" aria-live="polite"></span>
            </div>

            <div id="sql-results" class="sql-results" hidden>
                <div id="sql-results-meta" class="sql-results-meta"></div>
                <div id="sql-results-table-wrap" class="table-container"></div>
            </div>
        </div>

        <aside class="sql-sidebar">
            <div class="sql-history-header">
                <h2>Historie příkazů</h2>
                <button id="sql-history-clear" class="btn btn-sm btn-secondary">Vymazat historii</button>
            </div>
            <ul id="sql-history-list" class="sql-history-list" aria-label="Historie SQL příkazů">
                <li class="sql-history-empty">Zatím žádná historie.</li>
            </ul>
        </aside>
    </div>
</div>

<script>
(function () {
    var HISTORY_KEY  = 'admin_sql_history';
    var MAX_HISTORY  = 50;
    var csrfMeta     = document.querySelector('meta[name="csrf-token"]');
    var csrfToken    = csrfMeta ? csrfMeta.getAttribute('content') : '';

    var textarea     = document.getElementById('sql-input');
    var runBtn       = document.getElementById('sql-run-btn');
    var clearBtn     = document.getElementById('sql-clear-btn');
    var statusEl     = document.getElementById('sql-status');
    var resultsBox   = document.getElementById('sql-results');
    var resultsMeta  = document.getElementById('sql-results-meta');
    var resultsTable = document.getElementById('sql-results-table-wrap');
    var historyList  = document.getElementById('sql-history-list');
    var histClearBtn = document.getElementById('sql-history-clear');

    // ── Načtení a uložení historie ────────────────────────────────────────────

    function loadHistory() {
        try {
            return JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
        } catch (e) {
            return [];
        }
    }

    function saveToHistory(sql) {
        var hist = loadHistory().filter(function (s) { return s !== sql; });
        hist.unshift(sql);
        if (hist.length > MAX_HISTORY) hist = hist.slice(0, MAX_HISTORY);
        localStorage.setItem(HISTORY_KEY, JSON.stringify(hist));
        renderHistory();
    }

    function renderHistory() {
        var hist = loadHistory();
        historyList.innerHTML = '';
        if (hist.length === 0) {
            historyList.innerHTML = '<li class="sql-history-empty">Zatím žádná historie.</li>';
            return;
        }
        hist.forEach(function (sql) {
            var li  = document.createElement('li');
            li.className = 'sql-history-item';
            var btn = document.createElement('button');
            btn.type      = 'button';
            btn.className = 'sql-history-btn';
            btn.title     = sql;
            btn.textContent = sql.length > 120 ? sql.slice(0, 120) + '…' : sql;
            btn.addEventListener('click', function () {
                textarea.value = sql;
                textarea.focus();
            });
            li.appendChild(btn);
            historyList.appendChild(li);
        });
    }

    // ── Zobrazení výsledků ────────────────────────────────────────────────────

    function showResults(data) {
        resultsBox.hidden = false;

        if (!data.ok) {
            resultsMeta.innerHTML = '<span class="sql-error">Chyba: ' + escHtml(data.error) + '</span>';
            resultsTable.innerHTML = '';
            return;
        }

        if (data.rows && data.rows.length > 0) {
            var cols = Object.keys(data.rows[0]);
            resultsMeta.innerHTML = '<span class="sql-success">Vráceno ' + data.rows.length + ' řádků.</span>';

            var table = document.createElement('table');
            table.className = 'data-table';
            var thead = document.createElement('thead');
            var htr   = document.createElement('tr');
            cols.forEach(function (c) {
                var th = document.createElement('th');
                th.textContent = c;
                htr.appendChild(th);
            });
            thead.appendChild(htr);
            table.appendChild(thead);

            var tbody = document.createElement('tbody');
            data.rows.forEach(function (row) {
                var tr = document.createElement('tr');
                cols.forEach(function (c) {
                    var td = document.createElement('td');
                    td.textContent = row[c] !== null ? String(row[c]) : 'NULL';
                    if (row[c] === null) td.classList.add('text-muted');
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            resultsTable.innerHTML = '';
            resultsTable.appendChild(table);
        } else {
            var affected = data.affected !== undefined ? data.affected : 0;
            resultsMeta.innerHTML = '<span class="sql-success">Příkaz proveden. Ovlivněno řádků: ' + affected + '.</span>';
            resultsTable.innerHTML = '';
        }
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Spuštění SQL ─────────────────────────────────────────────────────────

    function runSql() {
        var sql = textarea.value.trim();
        if (!sql) return;

        runBtn.disabled = true;
        statusEl.textContent = 'Spouštím…';
        statusEl.className   = 'sql-status';

        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('sql', sql);

        fetch(<?= json_encode(Url::u('/admin/sql'), JSON_UNESCAPED_SLASHES) ?>, {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:    fd,
        })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function (data) {
            runBtn.disabled    = false;
            statusEl.textContent = data.ok ? 'Hotovo.' : 'Chyba.';
            statusEl.className   = 'sql-status ' + (data.ok ? 'sql-status--ok' : 'sql-status--error');
            showResults(data);
            if (data.ok) saveToHistory(sql);
        })
        .catch(function (err) {
            runBtn.disabled    = false;
            statusEl.textContent = 'Chyba spojení.';
            statusEl.className   = 'sql-status sql-status--error';
            resultsBox.hidden    = false;
            resultsMeta.innerHTML = '<span class="sql-error">Chyba: ' + escHtml(err.message) + '</span>';
            resultsTable.innerHTML = '';
        });
    }

    runBtn.addEventListener('click', runSql);

    textarea.addEventListener('keydown', function (e) {
        // Ctrl+Enter nebo Cmd+Enter spustí příkaz
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            runSql();
        }
    });

    clearBtn.addEventListener('click', function () {
        textarea.value = '';
        resultsBox.hidden = true;
        statusEl.textContent = '';
        textarea.focus();
    });

    histClearBtn.addEventListener('click', function () {
        if (confirm('Opravdu vymazat celou historii?')) {
            localStorage.removeItem(HISTORY_KEY);
            renderHistory();
        }
    });

    // Počáteční render historie
    renderHistory();
}());
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
