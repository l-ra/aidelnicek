<?php

declare(strict_types=1);

use Aidelnicek\Auth;
use Aidelnicek\Csrf;
use Aidelnicek\MealPlan;
use Aidelnicek\User;

$user = Auth::requireLogin();
if (!User::isAdmin((int) $user['id'])) {
    header('Location: /');
    exit;
}

$pageTitle   = 'Generování jídelníčku';
$currentUser = Auth::getCurrentUser();

$db    = \Aidelnicek\Database::get();
$users = $db->query('SELECT id, name, email FROM users ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// Current ISO week defaults (can be overridden by ?week=&year=)
$currentWeekNum = isset($_GET['week']) ? (int) $_GET['week'] : (int) date('W');
$currentYear    = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
if ($currentWeekNum < 1 || $currentWeekNum > 53) {
    $currentWeekNum = (int) date('W');
}
if ($currentYear < 2024 || $currentYear > 2030) {
    $currentYear = (int) date('Y');
}

// Build week options for dropdown: current, next, previous + a few more
$weekOptions = [];
$now = new DateTimeImmutable();
$hasRequestedWeek = false;
for ($offset = -2; $offset <= 4; $offset++) {
    $dt = $now->modify($offset . ' weeks');
    $wn = (int) $dt->format('W');
    $yr = (int) $dt->format('Y');
    if ($wn === $currentWeekNum && $yr === $currentYear) {
        $hasRequestedWeek = true;
    }
    $mon = $dt->setISODate($yr, $wn, 1);
    $sun = $mon->modify('+6 days');
    $label = $offset === 0 ? 'Tento týden' : ($offset === 1 ? 'Příští týden' : ($offset === -1 ? 'Předchozí týden' : "Týden {$wn}/{$yr}"));
    $weekOptions[] = [
        'week' => $wn,
        'year' => $yr,
        'label' => $label . ' (' . $mon->format('j.n.') . '–' . $sun->format('j.n.') . ' ' . $yr . ')',
    ];
}
// If URL params specify a week not in the list, add it
if (!$hasRequestedWeek) {
    try {
        $mon = (new DateTimeImmutable())->setISODate($currentYear, $currentWeekNum, 1);
        $sun = $mon->modify('+6 days');
        array_unshift($weekOptions, [
            'week' => $currentWeekNum,
            'year' => $currentYear,
            'label' => "Týden {$currentWeekNum}/{$currentYear} (" . $mon->format('j.n.') . '–' . $sun->format('j.n.') . ' ' . $currentYear . ')',
        ]);
    } catch (\Throwable $e) {
        // Invalid week/year, ignore
    }
}

ob_start();
?>
<div class="llm-generate-page">
    <div class="admin-page-header">
        <h1>Generování jídelníčku přes AI</h1>
        <a href="/admin" class="btn btn-secondary btn-sm">← Zpět na administraci</a>
    </div>

    <div class="llm-generate-layout">

        <!-- ── Parametry ───────────────────────────────────────────────── -->
        <div class="llm-generate-form-wrap">
            <div class="admin-card llm-generate-params-card">
                <h2>Parametry generování</h2>

                <div class="form-group">
                    <label for="gen-week-select">Výběr týdne</label>
                    <select id="gen-week-select" class="form-control">
                        <?php foreach ($weekOptions as $opt): ?>
                            <option value="<?= $opt['week'] ?>-<?= $opt['year'] ?>"
                                    <?= $opt['week'] === $currentWeekNum && $opt['year'] === $currentYear ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt['label']) ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="custom">— Vlastní týden —</option>
                    </select>
                </div>
                <div id="gen-week-custom-wrap" class="form-row--inline" hidden>
                    <div class="form-group">
                        <label for="gen-week">Týden (ISO)</label>
                        <input type="number" id="gen-week" class="form-control"
                               value="<?= $currentWeekNum ?>" min="1" max="53">
                    </div>
                    <div class="form-group">
                        <label for="gen-year">Rok</label>
                        <input type="number" id="gen-year" class="form-control"
                               value="<?= $currentYear ?>" min="2024" max="2030">
                    </div>
                </div>

                <div class="form-group">
                    <label for="gen-mode">Režim generování</label>
                    <select id="gen-mode" class="form-control">
                        <option value="single" selected>Individuálně pro vybraného uživatele</option>
                        <option value="shared_all">Společně pro všechny (stejná jídla, různé porce)</option>
                    </select>
                    <small id="gen-mode-help" class="form-help">
                        Vygenerují se jídla pouze pro vybraného uživatele.
                    </small>
                </div>

                <div class="form-group">
                    <label for="gen-user" id="gen-user-label">Uživatel</label>
                    <select id="gen-user" class="form-control">
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>">
                                <?= htmlspecialchars($u['name']) ?>
                                (<?= htmlspecialchars($u['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group-checkbox" style="margin-top:0.5rem">
                    <label>
                        <input type="checkbox" id="gen-force" checked>
                        Přepsat stávající jídelníček
                    </label>
                </div>

                <div class="llm-test-toolbar" style="margin-top:1rem">
                    <button id="gen-start-btn" class="btn btn-primary">▶ Spustit generování</button>
                    <span id="gen-status" class="sql-status" aria-live="polite"></span>
                </div>
            </div>

            <!-- ── Info o posledním jobu ─────────────────────────────── -->
            <div id="gen-job-info" class="admin-card" hidden>
                <h2>Průběh jobu</h2>
                <dl class="info-list">
                    <dt>Job ID</dt>      <dd id="info-job-id">—</dd>
                    <dt>Stav</dt>        <dd id="info-status">—</dd>
                    <dt>Čas běhu</dt>    <dd id="info-elapsed">—</dd>
                    <dt>Chunků</dt>      <dd id="info-chunks">0</dd>
                </dl>
                <div id="gen-done-actions" hidden style="margin-top:1rem">
                    <a id="gen-plan-link" href="/plan/week" class="btn btn-primary">
                        Zobrazit vygenerovaný jídelníček →
                    </a>
                </div>
            </div>
        </div>

        <!-- ── Streaming výstup ─────────────────────────────────────── -->
        <div class="llm-generate-output-wrap" id="gen-output-wrap" hidden>
            <div class="admin-card llm-generate-output-card">
                <div class="llm-response-header">
                    <h2>Výstup modelu (streaming)</h2>
                    <button id="gen-copy-btn" class="btn btn-secondary btn-sm">Kopírovat</button>
                </div>
                <pre id="gen-output" class="llm-response-text llm-generate-output"></pre>
            </div>
        </div>

    </div><!-- .llm-generate-layout -->
</div><!-- .llm-generate-page -->

<style>
.llm-generate-page { max-width: 1200px; }
.llm-generate-params-card {
    overflow: visible;
    min-height: auto;
}
.llm-generate-layout {
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 1.5rem;
    align-items: start;
}
@media (max-width: 800px) {
    .llm-generate-layout { grid-template-columns: 1fr; }
}
.llm-generate-form-wrap { display: flex; flex-direction: column; gap: 1rem; }
.llm-generate-output-card { height: 100%; }
.llm-generate-output {
    min-height: 300px;
    max-height: 70vh;
    overflow-y: auto;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 0.8rem;
    line-height: 1.5;
    background: var(--color-bg, #f8f9fa);
    border-radius: 6px;
    padding: 1rem;
}
.info-list { display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 1rem; }
.info-list dt { font-weight: 600; color: var(--color-muted, #666); }
.form-help { display: block; color: var(--color-muted, #666); margin-top: 0.35rem; }
</style>

<script>
(function () {
    var csrfMeta  = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    var startBtn     = document.getElementById('gen-start-btn');
    var statusEl     = document.getElementById('gen-status');
    var jobInfoCard  = document.getElementById('gen-job-info');
    var outputWrap   = document.getElementById('gen-output-wrap');
    var outputEl     = document.getElementById('gen-output');
    var copyBtn      = document.getElementById('gen-copy-btn');
    var doneActions  = document.getElementById('gen-done-actions');
    var planLink     = document.getElementById('gen-plan-link');

    var infoJobId    = document.getElementById('info-job-id');
    var infoStatus   = document.getElementById('info-status');
    var infoElapsed  = document.getElementById('info-elapsed');
    var infoChunks   = document.getElementById('info-chunks');

    var modeSel      = document.getElementById('gen-mode');
    var modeHelp     = document.getElementById('gen-mode-help');
    var userLabel   = document.getElementById('gen-user-label');
    var userSel     = document.getElementById('gen-user');
    var weekSelect  = document.getElementById('gen-week-select');
    var weekCustom  = document.getElementById('gen-week-custom-wrap');
    var weekInput   = document.getElementById('gen-week');
    var yearInput   = document.getElementById('gen-year');
    var forceChk    = document.getElementById('gen-force');

    function syncWeekFromSelect() {
        var val = weekSelect.value;
        if (val === 'custom') {
            weekCustom.hidden = false;
        } else {
            weekCustom.hidden = true;
            var parts = val.split('-');
            if (parts.length === 2) {
                weekInput.value = parts[0];
                yearInput.value = parts[1];
            }
        }
    }
    weekSelect.addEventListener('change', syncWeekFromSelect);
    syncWeekFromSelect();

    var activeEs   = null;
    var startedAt  = null;
    var elapsedInt = null;

    function setStatus(text, type) {
        statusEl.textContent = text;
        statusEl.className   = 'sql-status' + (type ? ' sql-status--' + type : '');
    }

    function startElapsedTimer() {
        startedAt = Date.now();
        elapsedInt = setInterval(function () {
            var sec = Math.round((Date.now() - startedAt) / 1000);
            infoElapsed.textContent = sec + ' s';
        }, 1000);
    }

    function stopElapsedTimer() {
        clearInterval(elapsedInt);
    }

    function syncGenerationModeUi() {
        if (modeSel.value === 'shared_all') {
            userLabel.textContent = 'Referenční uživatel';
            modeHelp.textContent = 'Model vytvoří jednu společnou sadu jídel pro všechny uživatele a upraví jen velikosti porcí.';
        } else {
            userLabel.textContent = 'Uživatel';
            modeHelp.textContent = 'Vygenerují se jídla pouze pro vybraného uživatele.';
        }
    }

    function startGeneration() {
        if (activeEs) {
            activeEs.close();
            activeEs = null;
        }

        var mode   = modeSel.value;
        var userId = parseInt(userSel.value, 10);
        var week   = parseInt(weekInput.value, 10);
        var year   = parseInt(yearInput.value, 10);
        var force  = forceChk.checked ? '1' : '0';

        if (!userId || !week || !year) {
            setStatus('Vyplňte všechna pole.', 'error');
            return;
        }

        startBtn.disabled = true;
        setStatus(mode === 'shared_all' ? 'Načítám společné prompty…' : 'Načítám prompty…', '');
        outputEl.textContent = '';
        outputWrap.hidden    = true;
        doneActions.hidden   = true;
        jobInfoCard.hidden   = false;
        infoJobId.textContent   = '…';
        infoStatus.textContent  = 'pending';
        infoChunks.textContent  = '0';
        infoElapsed.textContent = '0 s';

        // Step 1: create the job via POST, getting job_id back
        var fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('user_id',    userId);
        fd.append('week_id',    '');     // resolved below
        fd.append('force',      force);
        fd.append('generation_mode', mode);

        // Resolve week_id from week + year via a hidden approach:
        // We send week_num + year and let PHP resolve the week_id.
        // To do that cleanly, we send week_num and year instead of week_id.
        fd.set('week_id',   '0');
        fd.append('week_number', week);
        fd.append('year',        year);

        fetch('/admin/llm-generate', {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:    fd,
        })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function (data) {
            if (!data.ok) {
                setStatus('Chyba: ' + data.error, 'error');
                startBtn.disabled = false;
                return;
            }

            var jobId = data.job_id;
            infoJobId.textContent = jobId;
            setStatus(
                mode === 'shared_all'
                    ? 'Generuji společný jídelníček pro všechny…'
                    : 'Generuji…',
                ''
            );
            outputWrap.hidden = false;
            startElapsedTimer();

            // Step 2: open SSE stream
            var es = new EventSource('/admin/llm-stream?job_id=' + jobId);
            activeEs = es;

            es.onmessage = function (evt) {
                var msg = JSON.parse(evt.data);

                if (msg.type === 'chunk') {
                    outputEl.textContent += msg.text;
                    outputEl.scrollTop = outputEl.scrollHeight;
                    infoChunks.textContent = msg.count;
                    infoStatus.textContent = 'running';
                }

                if (msg.type === 'done') {
                    es.close();
                    activeEs = null;
                    stopElapsedTimer();
                    startBtn.disabled = false;

                    if (msg.status === 'done') {
                        infoStatus.textContent = '✓ hotovo';
                        setStatus('Generování dokončeno.', 'ok');
                        // Build plan link for the right week/year
                        planLink.href = '/plan/week?week=' + week + '&year=' + year;
                        doneActions.hidden = false;
                    } else {
                        infoStatus.textContent = '✗ chyba';
                        setStatus('Chyba: ' + (msg.error || 'neznámá chyba'), 'error');
                    }
                }

                if (msg.type === 'error') {
                    es.close();
                    activeEs = null;
                    stopElapsedTimer();
                    startBtn.disabled = false;
                    infoStatus.textContent = '✗ chyba';
                    setStatus('Chyba SSE: ' + msg.error, 'error');
                }
            };

            es.onerror = function () {
                if (es.readyState === EventSource.CLOSED) {
                    return; // normal close after 'done'
                }
                es.close();
                activeEs = null;
                stopElapsedTimer();
                startBtn.disabled = false;
                setStatus('Spojení přerušeno.', 'error');
            };
        })
        .catch(function (err) {
            setStatus('Chyba spojení: ' + err.message, 'error');
            startBtn.disabled = false;
        });
    }

    startBtn.addEventListener('click', startGeneration);
    modeSel.addEventListener('change', syncGenerationModeUi);
    syncGenerationModeUi();

    copyBtn.addEventListener('click', function () {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(outputEl.textContent);
        }
    });
}());
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
