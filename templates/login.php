<?php
use Aidelnicek\Url;

$pageTitle = 'Přihlášení';
$loginError = $_GET['error'] ?? null;
$errorMessage = match ($loginError) {
    'missing'          => 'Vyplňte e-mail a heslo.',
    'invalid'          => 'Nesprávný e-mail nebo heslo.',
    'csrf'             => 'Reload stránky a zkuste znovu.',
    'invite_required'  => 'Registrace je možná pouze prostřednictvím pozvánek. Kontaktujte správce.',
    default            => null,
};
$emailChangedNotice = ($_GET['email_changed'] ?? '') === '1';
ob_start();
?>
<section class="auth-form">
    <h1>Přihlášení</h1>
    <?php if ($errorMessage): ?>
        <p class="alert alert-error"><?= htmlspecialchars($errorMessage) ?></p>
    <?php endif; ?>
    <?php if ($emailChangedNotice): ?>
        <p class="alert alert-success">E-mailová adresa byla změněna. Přihlaste se prosím novou adresou.</p>
    <?php endif; ?>
    <form method="post" action="<?= Url::hu('/login') ?>">
        <?= \Aidelnicek\Csrf::field() ?>
        <div class="form-group">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required>
        </div>
        <div class="form-group">
            <label for="password">Heslo</label>
            <div class="password-toggle">
                <input type="password" id="password" name="password" required>
                <button type="button" class="password-toggle-btn" aria-label="Zobrazit heslo">👁</button>
            </div>
        </div>
        <div class="form-group form-group-checkbox">
            <label>
                <input type="checkbox" name="remember_me" value="1">
                Pamatovat si mě
            </label>
        </div>
        <button type="submit" class="btn btn-primary">Přihlásit se</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
