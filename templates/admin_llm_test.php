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

$pageTitle   = 'Test LLM';
$currentUser = Auth::getCurrentUser();

$defaultSystem = '';
$systemFile    = dirname(__DIR__) . '/prompts/system.txt';
if (is_readable($systemFile)) {
    $defaultSystem = file_get_contents($systemFile);
}

ob_start();
?>
<div class="llm-test-page">
    <div class="admin-page-header">
        <h1>Test LLM komunikace</h1>
        <a href="<?= Url::hu('/admin') ?>" class="btn btn-secondary btn-sm">← Zpět na administraci</a>
    </div>

    <div class="llm-test-layout">
        <div class="llm-test-form-wrap">
            <div class="admin-card">
                <h2>Parametry požadavku</h2>

                <div class="form-group">
                    <label for="llm-system">Systémový prompt</label>
                    <textarea id="llm-system" class="llm-textarea llm-textarea--system" rows="6"
                              placeholder="Popis role a chování modelu…"><?= htmlspecialchars($defaultSystem) ?></textarea>
                </div>

                <div class="form-group">
                    <label for="llm-user">Uživatelský prompt <span class="required-mark">*</span></label>
                    <textarea id="llm-user" class="llm-textarea llm-textarea--user" rows="5"
                              placeholder="Zadejte prompt, který chcete odeslat na LLM…" required></textarea>
                </div>

                <div class="llm-options-row">
                    <div class="form-group">
                        <label for="llm-temperature">Teplota</label>
                        <input type="number" id="llm-temperature" class="form-control llm-number-input"
                               value="0.7" min="0" max="2" step="0.1">
                    </div>
                    <div class="form-group">
                        <label for="llm-max-tokens">Max. tokenů</label>
                        <input type="number" id="llm-max-tokens" class="form-control llm-number-input"
                               value="1024" min="64" max="32000" step="64">
                    </div>
                </div>

                <div class="llm-test-toolbar">
                    <button id="llm-send-btn" class="btn btn-primary">Odeslat na LLM</button>
                    <button id="llm-clear-btn" class="btn btn-secondary">Vymazat</button>
                    <span id="llm-status" class="sql-status" aria-live="polite"></span>
                </div>
            </div>
        </div>

        <div class="llm-test-response-wrap" id="llm-response-wrap" hidden>
            <div class="admin-card">
                <div class="llm-response-header">
                    <h2>Odpověď modelu</h2>
                    <span id="llm-response-meta" class="llm-response-meta"></span>
                </div>
                <pre id="llm-response-text" class="llm-response-text"></pre>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var csrfMeta  = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    var systemEl   = document.getElementById('llm-system');
    var userEl     = document.getElementById('llm-user');
    var tempEl     = document.getElementById('llm-temperature');
    var tokensEl   = document.getElementById('llm-max-tokens');
    var sendBtn    = document.getElementById('llm-send-btn');
    var clearBtn   = document.getElementById('llm-clear-btn');
    var statusEl   = document.getElementById('llm-status');
    var responseWrap = document.getElementById('llm-response-wrap');
    var responseMeta = document.getElementById('llm-response-meta');
    var responseText = document.getElementById('llm-response-text');

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function sendPrompt() {
        var userPrompt = userEl.value.trim();
        if (!userPrompt) {
            statusEl.textContent = 'Uživatelský prompt nesmí být prázdný.';
            statusEl.className   = 'sql-status sql-status--error';
            userEl.focus();
            return;
        }

        sendBtn.disabled     = true;
        statusEl.textContent = 'Odesílám…';
        statusEl.className   = 'sql-status';
        responseWrap.hidden  = true;

        var fd = new FormData();
        fd.append('csrf_token',    csrfToken);
        fd.append('system_prompt', systemEl.value);
        fd.append('user_prompt',   userPrompt);
        fd.append('temperature',   tempEl.value);
        fd.append('max_tokens',    tokensEl.value);

        fetch(<?= json_encode(Url::u('/admin/llm-test'), JSON_UNESCAPED_SLASHES) ?>, {
            method:  'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body:    fd,
        })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function (data) {
            sendBtn.disabled = false;

            if (!data.ok) {
                statusEl.textContent = 'Chyba.';
                statusEl.className   = 'sql-status sql-status--error';
                responseWrap.hidden  = false;
                responseMeta.innerHTML = '<span class="sql-error">Chyba: ' + escHtml(data.error) + '</span>';
                responseText.textContent = '';
                return;
            }

            statusEl.textContent = 'Hotovo.';
            statusEl.className   = 'sql-status sql-status--ok';
            responseWrap.hidden  = false;

            var metaParts = [];
            if (data.provider) metaParts.push('Provider: ' + escHtml(data.provider));
            if (data.model)    metaParts.push('Model: ' + escHtml(data.model));
            responseMeta.innerHTML = metaParts.join(' &nbsp;|&nbsp; ');

            responseText.textContent = data.response || '(prázdná odpověď)';
        })
        .catch(function (err) {
            sendBtn.disabled     = false;
            statusEl.textContent = 'Chyba spojení.';
            statusEl.className   = 'sql-status sql-status--error';
            responseWrap.hidden  = false;
            responseMeta.innerHTML = '<span class="sql-error">Chyba: ' + escHtml(err.message) + '</span>';
            responseText.textContent = '';
        });
    }

    sendBtn.addEventListener('click', sendPrompt);

    userEl.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            sendPrompt();
        }
    });

    clearBtn.addEventListener('click', function () {
        userEl.value         = '';
        statusEl.textContent = '';
        statusEl.className   = 'sql-status';
        responseWrap.hidden  = true;
        userEl.focus();
    });
}());
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
