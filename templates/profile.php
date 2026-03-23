<?php

declare(strict_types=1);

use Aidelnicek\Auth;
use Aidelnicek\User;
use Aidelnicek\Url;

$user = Auth::requireLogin();
$genderOptions = User::getGenderOptions();
$bodyTypeOptions = User::getBodyTypeOptions();

$success = $success ?? false;
$passwordSuccess = $passwordSuccess ?? false;
$errors = $errors ?? [];
$passwordErrors = $passwordErrors ?? [];

$emailChanged = $emailChanged ?? false;
$emailChangeSent = $emailChangeSent ?? false;
$emailChangeCancelled = $emailChangeCancelled ?? false;
$emailChangeMailError = $emailChangeMailError ?? null;
$pendingEmailChange = $pendingEmailChange ?? null;
$emailChangeForm = $emailChangeForm ?? null;

$csrfError = ($_GET['error'] ?? '') === 'csrf';

$pageTitle = 'Profil';
ob_start();
?>
<section class="profile-form">
    <h1>Můj profil</h1>
    <?php if ($csrfError): ?>
        <p class="alert alert-error">Reload stránky a zkuste znovu.</p>
    <?php endif; ?>
    <?php if ($success): ?>
        <p class="alert alert-success">Profil byl úspěšně uložen.</p>
    <?php endif; ?>
    <?php if ($passwordSuccess): ?>
        <p class="alert alert-success">Heslo bylo úspěšně změněno.</p>
    <?php endif; ?>
    <?php if ($emailChanged): ?>
        <p class="alert alert-success">E-mailová adresa byla změněna.</p>
    <?php endif; ?>
    <?php if ($emailChangeSent): ?>
        <p class="alert alert-success">Na novou adresu jsme odeslali e-mail s odkazem k potvrzení. Dokončete změnu v prohlížeči (náhled v poště nestačí).</p>
    <?php endif; ?>
    <?php if ($emailChangeCancelled): ?>
        <p class="alert alert-success">Čekající změna e-mailu byla zrušena.</p>
    <?php endif; ?>
    <?php if ($emailChangeMailError !== null): ?>
        <p class="alert alert-error"><?= htmlspecialchars($emailChangeMailError) ?></p>
    <?php endif; ?>

    <div class="form-group">
        <label for="email">E-mail</label>
        <input type="email" id="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" readonly autocomplete="username">
        <?php if ($pendingEmailChange !== null): ?>
            <p class="form-help">Čeká na potvrzení nové adresy: <strong><?= htmlspecialchars($pendingEmailChange['new_email']) ?></strong>
                (platné do <?= htmlspecialchars($pendingEmailChange['expires_at']) ?>).</p>
            <form method="post" action="<?= Url::hu('/profile/email-cancel') ?>" class="inline-form" style="margin-top:0.5rem;">
                <?= \Aidelnicek\Csrf::field() ?>
                <button type="submit" class="btn btn-secondary">Zrušit čekající změnu</button>
            </form>
        <?php else: ?>
            <small class="form-help">Změnu e-mailu potvrdíte odkazem z nové schránky.</small>
        <?php endif; ?>
    </div>

    <?php if ($pendingEmailChange === null): ?>
    <h2>Změna e-mailu</h2>
    <form method="post" action="<?= Url::hu('/profile/email-request') ?>" class="email-change-form">
        <?= \Aidelnicek\Csrf::field() ?>
        <div class="form-group">
            <label for="new_email">Nový e-mail</label>
            <input type="email" id="new_email" name="new_email" required autocomplete="email"
                value="<?= htmlspecialchars($emailChangeForm['new_email'] ?? '') ?>">
            <?php if (!empty($emailChangeForm['errors']['new_email'])): ?>
                <span class="form-error"><?= htmlspecialchars($emailChangeForm['errors']['new_email']) ?></span>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label for="email_change_password">Heslo (ověření)</label>
            <div class="password-toggle">
                <input type="password" id="email_change_password" name="password" required autocomplete="current-password">
                <button type="button" class="password-toggle-btn" aria-label="Zobrazit heslo">👁</button>
            </div>
            <?php if (!empty($emailChangeForm['errors']['password'])): ?>
                <span class="form-error"><?= htmlspecialchars($emailChangeForm['errors']['password']) ?></span>
            <?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary">Odeslat ověřovací odkaz</button>
    </form>
    <?php endif; ?>

    <form method="post" action="<?= Url::hu('/profile') ?>">
        <?= \Aidelnicek\Csrf::field() ?>
        <div class="form-group">
            <label for="name">Jméno</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
            <?php if (!empty($errors['name'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['name']) ?></span>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label for="gender">Pohlaví</label>
            <select id="gender" name="gender">
                <option value="">— Nevybráno —</option>
                <?php foreach ($genderOptions as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= ($user['gender'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="age">Věk</label>
            <input type="number" id="age" name="age" min="1" max="150" value="<?= htmlspecialchars((string) ($user['age'] ?? '')) ?>" placeholder="např. 35">
            <?php if (!empty($errors['age'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['age']) ?></span>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label for="body_type">Postava</label>
            <select id="body_type" name="body_type">
                <option value="">— Nevybráno —</option>
                <?php foreach ($bodyTypeOptions as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= ($user['body_type'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group form-row">
            <div>
                <label for="height">Výška (cm)</label>
                <input type="number" id="height" name="height" min="50" max="250" value="<?= htmlspecialchars((string) ($user['height'] ?? '')) ?>" placeholder="např. 175">
                <?php if (!empty($errors['height'])): ?>
                    <span class="form-error"><?= htmlspecialchars($errors['height']) ?></span>
                <?php endif; ?>
            </div>
            <div>
                <label for="weight">Váha (kg)</label>
                <input type="number" id="weight" name="weight" min="20" max="500" step="0.1" value="<?= htmlspecialchars((string) ($user['weight'] ?? '')) ?>" placeholder="např. 70">
                <?php if (!empty($errors['weight'])): ?>
                    <span class="form-error"><?= htmlspecialchars($errors['weight']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="form-group">
            <label for="dietary_notes">Dietní omezení / alergie</label>
            <textarea id="dietary_notes" name="dietary_notes" rows="3" placeholder="např. bezlepková dieta, alergie na ořechy"><?= htmlspecialchars($user['dietary_notes'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <label for="diet_goal">Cíl jídelníčku</label>
            <textarea id="diet_goal" name="diet_goal" rows="3" placeholder="např. zhubnout 5 kg za 3 měsíce, udržet váhu, nabrat svalovou hmotu"><?= htmlspecialchars($user['diet_goal'] ?? '') ?></textarea>
            <small class="form-help">Slovní popis vašeho cíle — LLM ho použije při generování jídelníčku.</small>
        </div>
        <button type="submit" class="btn btn-primary">Uložit profil</button>
    </form>

    <h2>Změna hesla</h2>
    <form method="post" action="<?= Url::hu('/profile-password') ?>" class="password-change-form">
        <?= \Aidelnicek\Csrf::field() ?>
        <div class="form-group">
            <label for="current_password">Aktuální heslo</label>
            <div class="password-toggle">
                <input type="password" id="current_password" name="current_password">
                <button type="button" class="password-toggle-btn" aria-label="Zobrazit heslo">👁</button>
            </div>
            <?php if (!empty($passwordErrors['current_password'])): ?>
                <span class="form-error"><?= htmlspecialchars($passwordErrors['current_password']) ?></span>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label for="new_password">Nové heslo</label>
            <div class="password-toggle">
                <input type="password" id="new_password" name="new_password">
                <button type="button" class="password-toggle-btn" aria-label="Zobrazit heslo">👁</button>
            </div>
            <small class="form-help">Minimálně 8 znaků</small>
            <?php if (!empty($passwordErrors['new_password'])): ?>
                <span class="form-error"><?= htmlspecialchars($passwordErrors['new_password']) ?></span>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label for="new_password_confirm">Nové heslo znovu</label>
            <div class="password-toggle">
                <input type="password" id="new_password_confirm" name="new_password_confirm">
                <button type="button" class="password-toggle-btn" aria-label="Zobrazit heslo">👁</button>
            </div>
            <?php if (!empty($passwordErrors['new_password_confirm'])): ?>
                <span class="form-error"><?= htmlspecialchars($passwordErrors['new_password_confirm']) ?></span>
            <?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary">Změnit heslo</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
