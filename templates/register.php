<?php

declare(strict_types=1);

use Aidelnicek\User;
use Aidelnicek\Invite;

$genderOptions   = User::getGenderOptions();
$bodyTypeOptions = User::getBodyTypeOptions();
$errors          = $errors ?? [];
$csrfError       = ($_GET['error'] ?? '') === 'csrf';
$pageTitle       = 'Registrace';

// $invite is set by the route handler (GET) or POST handler on validation failure
/** @var array{email: string, expires: int, nonce: string}|null $invite */
$inviteEmail = $invite['email'] ?? '';
$inviteToken = $_GET['invite'] ?? ($_POST['invite_token'] ?? '');

ob_start();
?>
<section class="auth-form auth-form--wide">
    <h1>Registrace</h1>
    <?php if ($csrfError): ?>
        <p class="alert alert-error">Reload stránky a zkuste znovu.</p>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <ul class="alert alert-error">
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($inviteEmail !== ''): ?>
        <p class="alert alert-info">
            Registrace pro e-mail: <strong><?= htmlspecialchars($inviteEmail) ?></strong>
        </p>
    <?php endif; ?>

    <form method="post" action="/register">
        <?= \Aidelnicek\Csrf::field() ?>
        <input type="hidden" name="invite_token" value="<?= htmlspecialchars($inviteToken) ?>">

        <h2 class="form-section-title">Základní údaje</h2>

        <div class="form-group">
            <label for="name">Jméno <span class="required-mark">*</span></label>
            <input type="text" id="name" name="name"
                   value="<?= htmlspecialchars($data['name'] ?? '') ?>" required>
            <?php if (!empty($errors['name'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['name']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="email">E-mail</label>
            <input type="email" id="email"
                   value="<?= htmlspecialchars($inviteEmail) ?>"
                   disabled>
            <small class="form-help">E-mail je předvyplněn z pozvánky a nelze ho změnit.</small>
        </div>

        <div class="form-group">
            <label for="password">Heslo <span class="required-mark">*</span></label>
            <div class="password-toggle">
                <input type="password" id="password" name="password" required>
                <button type="button" class="password-toggle-btn" aria-label="Zobrazit heslo">👁</button>
            </div>
            <small class="form-help">Minimálně 8 znaků</small>
            <?php if (!empty($errors['password'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['password']) ?></span>
            <?php endif; ?>
        </div>

        <h2 class="form-section-title">Profil pro generování jídelníčku</h2>

        <div class="form-group">
            <label for="gender">Pohlaví</label>
            <select id="gender" name="gender">
                <option value="">— Nevybráno —</option>
                <?php foreach ($genderOptions as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>"
                        <?= ($data['gender'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="age">Věk</label>
            <input type="number" id="age" name="age" min="1" max="150"
                   value="<?= htmlspecialchars((string) ($data['age'] ?? '')) ?>"
                   placeholder="např. 35">
            <?php if (!empty($errors['age'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['age']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="body_type">Postava</label>
            <select id="body_type" name="body_type">
                <option value="">— Nevybráno —</option>
                <?php foreach ($bodyTypeOptions as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>"
                        <?= ($data['body_type'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group form-row">
            <div>
                <label for="height">Výška (cm)</label>
                <input type="number" id="height" name="height" min="50" max="250"
                       value="<?= htmlspecialchars((string) ($data['height'] ?? '')) ?>"
                       placeholder="např. 175">
                <?php if (!empty($errors['height'])): ?>
                    <span class="form-error"><?= htmlspecialchars($errors['height']) ?></span>
                <?php endif; ?>
            </div>
            <div>
                <label for="weight">Váha (kg)</label>
                <input type="number" id="weight" name="weight" min="20" max="500" step="0.1"
                       value="<?= htmlspecialchars((string) ($data['weight'] ?? '')) ?>"
                       placeholder="např. 70">
                <?php if (!empty($errors['weight'])): ?>
                    <span class="form-error"><?= htmlspecialchars($errors['weight']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label for="dietary_notes">Dietní omezení / alergie</label>
            <textarea id="dietary_notes" name="dietary_notes" rows="2"
                      placeholder="např. bezlepková dieta, alergie na ořechy"><?= htmlspecialchars($data['dietary_notes'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <label for="diet_goal">Cíl jídelníčku</label>
            <textarea id="diet_goal" name="diet_goal" rows="2"
                      placeholder="např. zhubnout 5 kg za 3 měsíce, udržet váhu, nabrat svalovou hmotu"><?= htmlspecialchars($data['diet_goal'] ?? '') ?></textarea>
            <small class="form-help">Slovní popis vašeho cíle — LLM ho použije při generování jídelníčku.</small>
        </div>

        <button type="submit" class="btn btn-primary">Registrovat se</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
