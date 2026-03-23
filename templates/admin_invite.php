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

$pageTitle    = 'Pozvat uživatele';
$currentUser  = Auth::getCurrentUser();
$csrfError    = ($_GET['error'] ?? '') === 'csrf';

/** @var string|null $inviteUrl  — nastaveno POST handlerem */
/** @var array       $inviteErrors — nastaveno POST handlerem */
$inviteUrl    = $inviteUrl    ?? null;
$inviteErrors = $inviteErrors ?? [];

$email         = $_POST['email']          ?? '';
$validityHours = (int) ($_POST['validity_hours'] ?? 168);

ob_start();
?>
<div class="admin-invite-page">
    <div class="admin-page-header">
        <h1>Pozvat uživatele</h1>
        <a href="<?= Url::hu('/admin') ?>" class="btn btn-secondary btn-sm">← Zpět na administraci</a>
    </div>

    <?php if ($csrfError): ?>
        <div class="alert alert-danger">Neplatný bezpečnostní token. Zkuste to znovu.</div>
    <?php endif; ?>

    <div class="admin-cards">
        <div class="admin-card">
            <h2>Vygenerovat zvací odkaz</h2>
            <p>
                Odkaz je platný po omezenou dobu a vázaný na konkrétní e-mail.
                Umožní jednorázovou registraci pouze pro zadanou adresu.
            </p>

            <form method="post" action="<?= Url::hu('/admin/invite') ?>">
                <?= Csrf::field() ?>

                <?php if (!empty($inviteErrors)): ?>
                    <ul class="alert alert-danger">
                        <?php foreach ($inviteErrors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <div class="form-group">
                    <label for="invite-email">E-mail pozvaného uživatele <span class="required-mark">*</span></label>
                    <input type="email" id="invite-email" name="email"
                           value="<?= htmlspecialchars($email) ?>"
                           placeholder="uzivatel@example.com"
                           required class="form-control">
                </div>

                <div class="form-group">
                    <label for="invite-validity">Platnost pozvánky</label>
                    <select id="invite-validity" name="validity_hours" class="form-control">
                        <option value="24"  <?= $validityHours === 24  ? 'selected' : '' ?>>24 hodin</option>
                        <option value="72"  <?= $validityHours === 72  ? 'selected' : '' ?>>3 dny</option>
                        <option value="168" <?= $validityHours === 168 ? 'selected' : '' ?>>7 dní (výchozí)</option>
                        <option value="336" <?= $validityHours === 336 ? 'selected' : '' ?>>14 dní</option>
                        <option value="720" <?= $validityHours === 720 ? 'selected' : '' ?>>30 dní</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Vygenerovat odkaz</button>
            </form>
        </div>

        <?php if ($inviteUrl !== null): ?>
        <div class="admin-card">
            <h2>Zvací odkaz</h2>
            <p>
                Zkopírujte odkaz níže a pošlete ho pozvanému uživateli
                (<strong><?= htmlspecialchars($email) ?></strong>).
                Odkaz je platný <?= htmlspecialchars((string) $validityHours) ?> hodin.
            </p>
            <div class="invite-url-wrap">
                <?php
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $fullUrl  = $protocol . '://' . $host . $inviteUrl;
                ?>
                <input type="text" class="invite-url-input form-control"
                       id="invite-url-text"
                       value="<?= htmlspecialchars($fullUrl) ?>"
                       readonly>
                <button type="button" class="btn btn-secondary" id="invite-copy-btn">
                    Kopírovat
                </button>
            </div>
            <p class="form-help" style="margin-top: 0.5rem">
                Po vypršení platnosti nebo po úspěšné registraci odkaz přestane fungovat.
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var copyBtn = document.getElementById('invite-copy-btn');
    var urlInput = document.getElementById('invite-url-text');
    if (!copyBtn || !urlInput) return;

    copyBtn.addEventListener('click', function () {
        urlInput.select();
        urlInput.setSelectionRange(0, 99999);
        try {
            navigator.clipboard.writeText(urlInput.value).then(function () {
                copyBtn.textContent = 'Zkopírováno!';
                setTimeout(function () { copyBtn.textContent = 'Kopírovat'; }, 2000);
            });
        } catch (e) {
            document.execCommand('copy');
            copyBtn.textContent = 'Zkopírováno!';
            setTimeout(function () { copyBtn.textContent = 'Kopírovat'; }, 2000);
        }
    });
}());
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
