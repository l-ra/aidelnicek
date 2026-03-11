<?php

declare(strict_types=1);

use Aidelnicek\Auth;
use Aidelnicek\User;

$user = Auth::requireLogin();
$genderOptions = User::getGenderOptions();
$bodyTypeOptions = User::getBodyTypeOptions();

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'name' => trim($_POST['name'] ?? ''),
        'gender' => $_POST['gender'] ?? null,
        'age' => $_POST['age'] ?? null,
        'body_type' => $_POST['body_type'] ?? null,
        'dietary_notes' => trim($_POST['dietary_notes'] ?? '') ?: null,
    ];
    $errors = User::validateProfile($data);
    if (empty($errors)) {
        User::update((int) $user['id'], $data);
        $user = User::findById((int) $user['id']);
        $success = true;
    }
}

$pageTitle = 'Profil';
ob_start();
?>
<section class="profile-form">
    <h1>Můj profil</h1>
    <?php if ($success): ?>
        <p class="alert alert-success">Profil byl úspěšně uložen.</p>
    <?php endif; ?>
    <form method="post" action="/profile">
        <div class="form-group">
            <label for="name">Jméno</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
            <?php if (!empty($errors['name'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['name']) ?></span>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label for="email">E-mail</label>
            <input type="email" id="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" disabled>
            <small class="form-help">E-mail nelze měnit.</small>
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
        <div class="form-group">
            <label for="dietary_notes">Dietní omezení / alergie</label>
            <textarea id="dietary_notes" name="dietary_notes" rows="3" placeholder="např. bezlepková dieta, alergie na ořechy"><?= htmlspecialchars($user['dietary_notes'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Uložit profil</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
