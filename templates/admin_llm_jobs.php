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

$pageTitle   = 'LLM joby a projekce';
$currentUser = Auth::getCurrentUser();

$highlightJobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;

ob_start();
?>
<div class="llm-jobs-page">
    <div class="admin-page-header">
        <h1>LLM joby — projekce do databáze</h1>
        <a href="<?= Url::hu('/admin') ?>" class="btn btn-secondary btn-sm">← Zpět na administraci</a>
    </div>

    <p class="llm-jobs-intro">
        Zde můžete ručně dokončit projekci jídelníčku do databáze u jobů, kde LLM skončilo úspěšně,
        ale zápis do tabulek selhal, uvízl ve stavu <em>processing</em>, nebo zůstal ve stavu <em>pending</em>
        (např. neběží projector daemon).
    </p>

    <div class="admin-card llm-jobs-filters">
        <div class="llm-jobs-form-row">
            <div class="form-group">
                <label for="jobs-projection-filter">Filtr projekce</label>
                <select id="jobs-projection-filter" class="form-control">
                    <option value="all">Všechny stavy</option>
                    <option value="error" selected>Chyba projekce</option>
                    <option value="pending">Čeká na projekci</option>
                    <option value="processing">Probíhá (zaseklé)</option>
                    <option value="done">Dokončeno</option>
                </select>
            </div>
            <div class="llm-jobs-btn-wrap">
                <button id="jobs-reload-btn" type="button" class="btn btn-primary">Obnovit seznam</button>
                <span id="jobs-list-status" class="sql-status" aria-live="polite"></span>
            </div>
        </div>
    </div>

    <div class="admin-card">
        <div class="table-container">
            <table class="data-table llm-jobs-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Uživatel</th>
                        <th>Týden</th>
                        <th>LLM stav</th>
                        <th>Projekce</th>
                        <th>Vytvořeno</th>
                        <th>Akce</th>
                    </tr>
                </thead>
                <tbody id="jobs-tbody">
                    <tr><td colspan="7">Načítám…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="jobs-projection-panel" class="admin-card llm-jobs-projection-panel" hidden>
        <h2>Interaktivní projekce — job <span id="panel-job-id">—</span></h2>
        <pre id="jobs-projection-log" class="llm-jobs-log" aria-live="polite"></pre>
        <p id="jobs-projection-result" class="sql-status"></p>
    </div>
</div>

<style>
.llm-jobs-page { max-width: 1100px; }
.llm-jobs-intro { color: var(--color-muted, #666); margin-bottom: 1.25rem; }
.llm-jobs-form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: flex-end;
}
.llm-jobs-btn-wrap { display: flex; align-items: center; gap: 0.75rem; }
.llm-jobs-table .status-badge {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    font-size: 0.85em;
    font-weight: 600;
}
.status-badge--error { background: #fde8e8; color: #b42318; }
.status-badge--pending { background: #fff4e5; color: #b54708; }
.status-badge--processing { background: #e8f4fd; color: #175cd3; }
.status-badge--done { background: #e6f4ea; color: #137333; }
.llm-jobs-projection-panel { margin-top: 1.5rem; }
.llm-jobs-log {
    min-height: 120px;
    max-height: 240px;
    overflow-y: auto;
    background: var(--color-bg, #f8f9fa);
    border-radius: 6px;
    padding: 1rem;
    font-size: 0.85rem;
    white-space: pre-wrap;
    margin: 0 0 1rem;
}
.llm-jobs-row--highlight { background: #fff8e6; }
.llm-jobs-error-hint {
    display: block;
    font-size: 0.8rem;
    color: var(--color-muted, #666);
    margin-top: 0.25rem;
    max-width: 280px;
    word-break: break-word;
}
</style>

<script>
(function () {
    var csrfMeta  = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    var filterSel    = document.getElementById('jobs-projection-filter');
    var reloadBtn    = document.getElementById('jobs-reload-btn');
    var listStatus   = document.getElementById('jobs-list-status');
    var tbody        = document.getElementById('jobs-tbody');
    var panel        = document.getElementById('jobs-projection-panel');
    var panelJobId   = document.getElementById('panel-job-id');
    var projectionLog = document.getElementById('jobs-projection-log');
    var projectionResult = document.getElementById('jobs-projection-result');

    var highlightJobId = <?= (int) $highlightJobId ?>;

    var dataUrl   = <?= json_encode(Url::u('/admin/llm-jobs/data'), JSON_UNESCAPED_SLASHES) ?>;
    var retryUrl  = <?= json_encode(Url::u('/admin/llm-jobs/retry-projection'), JSON_UNESCAPED_SLASHES) ?>;
    var generateUrl = <?= json_encode(Url::u('/admin/llm-generate'), JSON_UNESCAPED_SLASHES) ?>;

    function setListStatus(text, type) {
        listStatus.textContent = text;
        listStatus.className = 'sql-status' + (type ? ' sql-status--' + type : '');
    }

    function badgeClass(status) {
        if (status === 'error') return 'status-badge--error';
        if (status === 'pending') return 'status-badge--pending';
        if (status === 'processing') return 'status-badge--processing';
        if (status === 'done') return 'status-badge--done';
        return '';
    }

    function canRetry(job) {
        return job.status === 'done'
            && ['pending', 'processing', 'error'].indexOf(job.projection_status) >= 0;
    }

    function appendLog(line) {
        projectionLog.textContent += line + '\n';
        projectionLog.scrollTop = projectionLog.scrollHeight;
    }

    function loadJobs() {
        setListStatus('Načítám…', '');
        var url = dataUrl + '?limit=80&projection_status=' + encodeURIComponent(filterSel.value);

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.ok) {
                    setListStatus('Chyba: ' + (data.error || 'neznámá'), 'error');
                    tbody.innerHTML = '<tr><td colspan="7">Nepodařilo se načíst joby.</td></tr>';
                    return;
                }

                var jobs = data.jobs || [];
                setListStatus('Nalezeno: ' + jobs.length, 'ok');

                if (jobs.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7">Žádné joby pro zvolený filtr.</td></tr>';
                    return;
                }

                var html = '';
                jobs.forEach(function (job) {
                    var weekLabel = (job.week_number && job.year)
                        ? job.week_number + '/' + job.year
                        : (job.week_id || '—');
                    var retryBtn = canRetry(job)
                        ? '<button type="button" class="btn btn-primary btn-sm jobs-retry-btn" data-job-id="' + job.id + '">Spustit projekci</button>'
                        : '<span class="text-muted">—</span>';
                    var errHint = job.projection_error_message
                        ? '<span class="llm-jobs-error-hint" title="' + escapeAttr(job.projection_error_message) + '">' + escapeHtml(truncate(job.projection_error_message, 80)) + '</span>'
                        : '';

                    var rowClass = (highlightJobId && job.id === highlightJobId) ? ' class="llm-jobs-row--highlight"' : '';

                    html += '<tr' + rowClass + '>'
                        + '<td>' + job.id + '</td>'
                        + '<td>' + escapeHtml(job.user_name || ('#' + job.user_id)) + '</td>'
                        + '<td>' + escapeHtml(String(weekLabel)) + '</td>'
                        + '<td><span class="status-badge ' + badgeClass(job.status) + '">' + escapeHtml(job.status) + '</span></td>'
                        + '<td><span class="status-badge ' + badgeClass(job.projection_status) + '">' + escapeHtml(job.projection_status || '—') + '</span>' + errHint + '</td>'
                        + '<td>' + escapeHtml(formatDate(job.created_at)) + '</td>'
                        + '<td>' + retryBtn + '</td>'
                        + '</tr>';
                });
                tbody.innerHTML = html;

                tbody.querySelectorAll('.jobs-retry-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        runProjection(parseInt(btn.getAttribute('data-job-id'), 10));
                    });
                });

                if (highlightJobId) {
                    var autoBtn = tbody.querySelector('.jobs-retry-btn[data-job-id="' + highlightJobId + '"]');
                    if (autoBtn) {
                        autoBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            })
            .catch(function (err) {
                setListStatus('Chyba spojení: ' + err.message, 'error');
            });
    }

    function runProjection(jobId) {
        panel.hidden = false;
        panelJobId.textContent = String(jobId);
        projectionLog.textContent = '';
        projectionResult.textContent = '';
        projectionResult.className = 'sql-status';

        appendLog('[' + timestamp() + '] Příprava projekce job #' + jobId + '…');
        appendLog('[' + timestamp() + '] Kontrola výstupu LLM a stavu jobu…');

        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('job_id', String(jobId));
        fd.append('cleanup', '1');

        appendLog('[' + timestamp() + '] Spouštím projekci (může trvat několik sekund)…');

        fetch(retryUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.ok) {
                appendLog('[' + timestamp() + '] ✓ Projekce dokončena (stav: ' + (data.projection_status || 'done') + ').');
                projectionResult.textContent = 'Projekce úspěšně dokončena.';
                projectionResult.className = 'sql-status sql-status--ok';
                loadJobs();
            } else {
                appendLog('[' + timestamp() + '] ✗ Chyba: ' + (data.error || 'neznámá chyba'));
                if (data.projection_status) {
                    appendLog('[' + timestamp() + '] Stav projekce: ' + data.projection_status);
                }
                projectionResult.textContent = data.error || 'Projekce selhala.';
                projectionResult.className = 'sql-status sql-status--error';
                loadJobs();
            }
        })
        .catch(function (err) {
            appendLog('[' + timestamp() + '] ✗ Síťová chyba: ' + err.message);
            projectionResult.textContent = 'Chyba spojení: ' + err.message;
            projectionResult.className = 'sql-status sql-status--error';
        });
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function escapeAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    function truncate(s, n) {
        s = String(s);
        return s.length > n ? s.slice(0, n) + '…' : s;
    }

    function formatDate(s) {
        if (!s) return '—';
        return String(s).replace('T', ' ').slice(0, 19);
    }

    function timestamp() {
        return new Date().toLocaleTimeString('cs-CZ');
    }

    reloadBtn.addEventListener('click', loadJobs);
    filterSel.addEventListener('change', loadJobs);

    if (highlightJobId > 0) {
        filterSel.value = 'all';
    }
    loadJobs();
}());
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
