<?php

declare(strict_types=1);

use Aidelnicek\Csrf;
use Aidelnicek\Url;

$token = (string) ($_GET['token'] ?? '');
$linkState = $linkState ?? ['status' => 'invalid'];
$confirmError = $confirmError ?? null;
$csrfError = ($_GET['error'] ?? '') === 'csrf';

$pageTitle = 'Potvrzení e-mailu';
ob_start();
?>
<section class="profile-form auth-form">
    <h1>Potvrzení nové e-mailové adresy</h1>
    <?php if ($csrfError): ?>
        <p class="alert alert-error">Relace vypršela nebo je neplatný token formuláře. Obnovte stránku a zkuste znovu.</p>
    <?php endif; ?>
    <?php if ($confirmError !== null): ?>
        <p class="alert alert-error"><?= htmlspecialchars($confirmError) ?></p>
    <?php endif; ?>

    <?php if ($linkState['status'] === 'ok' && $token !== ''): ?>
        <p>Potvrzujete změnu e-mailu na adresu <strong><?= htmlspecialchars($linkState['new_email'] ?? '') ?></strong>.</p>
        <p class="form-help">Odkaz z e-mailu sám o sobě změnu nedokončí — potvrďte ji tlačítkem níže (náhled v poštovním klientovi nestačí).</p>
        <form method="post" action="<?= Url::hu('/profile/confirm-email') ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-primary">Potvrdit změnu e-mailu</button>
        </form>
    <?php elseif ($linkState['status'] === 'invalid'): ?>
        <p>Odkaz je neplatný nebo poškozený.</p>
    <?php elseif ($linkState['status'] === 'expired'): ?>
        <p>Platnost odkazu vypršela. V profilu můžete požádat o nový.</p>
        <p><a href="<?= Url::hu('/profile') ?>" class="btn btn-primary">Zpět do profilu</a></p>
    <?php elseif ($linkState['status'] === 'consumed'): ?>
        <p>Tato žádost už byla vyřízena.</p>
        <p><a href="<?= Url::hu('/login') ?>" class="btn btn-primary">Přihlásit se</a></p>
    <?php elseif ($linkState['status'] === 'mismatch'): ?>
        <p>Odkaz neodpovídá uložené žádosti. Požádejte v profilu o nový.</p>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
