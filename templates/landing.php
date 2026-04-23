<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aidelnicek — výběr domácnosti</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/img/brand/ajidelnicek-transp-logo.png" type="image/png">
    <link rel="apple-touch-icon" href="/img/brand/ajidelnicek-transp-logo.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <main class="site-main">
        <div class="container" style="max-width: 32rem; margin: 3rem auto;">
            <h1 class="landing-title">
                <img
                    class="landing-title__img"
                    src="/img/brand/ajidelnicek-transp.png"
                    alt="Aidelnicek"
                    width="1867"
                    height="439"
                    decoding="async"
                >
            </h1>
            <p id="landing-status" class="text-muted">Přesměrovávám…</p>
            <p id="landing-manual" hidden>
                <label for="tenant-input">Zadejte identifikátor domácnosti (např. <code>dplusk</code>):</label><br>
                <input type="text" id="tenant-input" autocomplete="off" style="margin-top: 0.5rem; width: 100%; max-width: 20rem;">
                <button type="button" id="tenant-go" class="btn btn-primary" style="margin-top: 0.75rem;">Pokračovat</button>
            </p>
        </div>
    </main>
    <script>
(function () {
    var STORAGE_KEY = 'aidelnicek_tenant';
    var statusEl = document.getElementById('landing-status');
    var manualEl = document.getElementById('landing-manual');
    var inputEl = document.getElementById('tenant-input');
    var goBtn = document.getElementById('tenant-go');

    function slugify(v) {
        return String(v || '').trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
    }

    function go(slug) {
        var s = slugify(slug);
        if (!s) return;
        window.location.href = '/' + s + '/';
    }

    try {
        var saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            go(saved);
            return;
        }
    } catch (e) {}

    if (statusEl) statusEl.textContent = 'Vyberte domácnost nebo se přihlaste přes odkaz s identifikátorem v adrese.';
    if (manualEl) manualEl.hidden = false;

    if (goBtn && inputEl) {
        goBtn.addEventListener('click', function () { go(inputEl.value); });
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') go(inputEl.value);
        });
    }
})();
    </script>
</body>
</html>
