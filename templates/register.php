<?php

declare(strict_types=1);

use Aidelnicek\User;

$genderOptions = User::getGenderOptions();
$bodyTypeOptions = User::getBodyTypeOptions();
$errors = $errors ?? [];
$data = $data ?? [];
$pageTitle = 'Registrace';
ob_start();
?>
<section class="auth-form">
    <h1>Registrace</h1>
    <?php if (!empty($errors)): ?>
        <ul class="alert alert-error">
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <form method="post" action="/register">
        <div class="form-group">
            <label for="name">Jméno</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($data['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($data['email'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="password">Heslo</label>
            <input type="password" id="password" name="password" required>
            <small class="form-help">Minimálně 8 znaků</small>
            <?php if (!empty($errors['password'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['password']) ?></span>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label for="gender">Pohlaví</label>
            <select id="gender" name="gender">
                <option value="">— Nevybráno —</option>
                <?php foreach ($genderOptions as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= ($data['gender'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="age">Věk</label>
            <input type="number" id="age" name="age" min="1" max="150" value="<?= htmlspecialchars((string) ($data['age'] ?? '')) ?>" placeholder="např. 35">
        </div>
        <div class="form-group">
            <label for="body_type">Postava</label>
            <select id="body_type" name="body_type">
                <option value="">— Nevybráno —</option>
                <?php foreach ($bodyTypeOptions as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= ($data['body_type'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="dietary_notes">Dietní omezení / alergie (volitelně)</label>
            <textarea id="dietary_notes" name="dietary_notes" rows="2" placeholder="např. bezlepková dieta"><?= htmlspecialchars($data['dietary_notes'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Registrovat se</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
