<?php
$pageTitle = 'Administrace';
$currentUser = \Aidelnicek\Auth::getCurrentUser();

$db    = \Aidelnicek\Database::get();
$users = $db->query('SELECT id, name, email FROM users ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$successCode = $_GET['success'] ?? '';
$seeded = $successCode === 'seeded';
$passwordResetSuccess = $successCode === 'password_reset';

$csrfErr = ($_GET['error'] ?? '') === 'csrf' || ($_GET['password_error'] ?? '') === 'csrf';
$passwordErrorCode = $_GET['password_error'] ?? '';
$selectedResetUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$passwordErrorMessages = [
    'missing_user' => 'Vyberte uživatele, kterému chcete heslo resetovat.',
    'invalid_user' => 'Vybraný uživatel neexistuje.',
    'new_password_required' => 'Nové heslo je povinné.',
    'new_password_too_short' => 'Nové heslo musí mít alespoň 8 znaků.',
    'new_password_confirm' => 'Zadaná hesla se neshodují.',
];
$passwordErrorMessage = $passwordErrorMessages[$passwordErrorCode] ?? null;

ob_start();
?>
<div class="admin-dashboard">
    <h1>Administrace</h1>

    <?php if ($seeded): ?>
        <div class="alert alert-success">Demo data byla úspěšně vygenerována.</div>
    <?php elseif ($passwordResetSuccess): ?>
        <div class="alert alert-success">Heslo uživatele bylo úspěšně změněno.</div>
    <?php elseif ($passwordErrorMessage !== null): ?>
        <div class="alert alert-error"><?= htmlspecialchars($passwordErrorMessage) ?></div>
    <?php elseif ($csrfErr): ?>
        <div class="alert alert-error">Neplatný bezpečnostní token. Zkuste to znovu.</div>
    <?php endif; ?>

    <div class="admin-cards">
        <div class="admin-card">
            <h2>Prohlížeč tabulek</h2>
            <p>Zobrazení, úprava a mazání záznamů v tabulkách databáze se stránkováním.</p>
            <a href="/admin/table" class="btn btn-primary">Otevřít prohlížeč</a>
        </div>

        <div class="admin-card">
            <h2>SQL konzole</h2>
            <p>Spouštění libovolných SQL příkazů přímo nad databází. Historie příkazů se ukládá v prohlížeči.</p>
            <a href="/admin/sql" class="btn btn-primary">Otevřít konzoli</a>
        </div>

        <div class="admin-card" hidden>
            <h2>Generování demo dat</h2>
            <p>Vygeneruje ukázkový jídelníček pro aktuální týden. Pokud uživatel již data má, nic se nepřepíše.</p>
            <form method="post" action="/admin/seed-demo">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\Aidelnicek\Csrf::generate()) ?>">
                <div class="form-group">
                    <label for="seed-user">Uživatel</label>
                    <select id="seed-user" name="user_id" class="form-control">
                        <option value="">— Všichni uživatelé —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>">
                                <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary" style="margin-top:0.75rem">
                    Vygenerovat demo data
                </button>
            </form>
        </div>

        <div class="admin-card">
            <h2>Generování jídelníčku (AI streaming)</h2>
            <p>Spusťte generování jídelníčku přes Python LLM worker a sledujte odpověď modelu v reálném čase.</p>
            <a href="/admin/llm-generate" class="btn btn-primary">Spustit generování</a>
        </div>

        <div class="admin-card">
            <h2>Test LLM</h2>
            <p>Odešlete vlastní prompt na nakonfigurovaný jazykový model a zobrazte vygenerovanou odpověď.</p>
            <a href="/admin/llm-test" class="btn btn-primary">Otevřít test</a>
        </div>

        <div class="admin-card">
            <h2>LLM komunikační logy</h2>
            <p>Prohlížejte záznamy komunikace s LLM uložené v denních log souborech.</p>
            <a href="/admin/llm-logs" class="btn btn-primary">Zobrazit logy</a>
        </div>

        <div class="admin-card">
            <h2>Pozvat uživatele</h2>
            <p>Vygenerujte zvací odkaz s omezenou platností pro konkrétní e-mailovou adresu. Odkaz umožní registraci pouze s daným e-mailem.</p>
            <a href="/admin/invite" class="btn btn-primary">Generovat pozvánku</a>
        </div>

        <div class="admin-card">
            <h2>Reset hesla uživatele</h2>
            <p>Nastavte nové heslo vybranému uživateli. Uživatel se pak přihlásí tímto heslem.</p>
            <form method="post" action="/admin/user-password-reset">
                <?= \Aidelnicek\Csrf::field() ?>
                <div class="form-group">
                    <label for="reset-user-id">Uživatel</label>
                    <select id="reset-user-id" name="user_id" class="form-control" required>
                        <option value="">— Vyberte uživatele —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= $selectedResetUserId === (int) $u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="admin-new-password">Nové heslo</label>
                    <input id="admin-new-password" name="new_password" type="password" minlength="8" required>
                    <small class="form-help">Minimálně 8 znaků.</small>
                </div>
                <div class="form-group">
                    <label for="admin-new-password-confirm">Nové heslo znovu</label>
                    <input id="admin-new-password-confirm" name="new_password_confirm" type="password" minlength="8" required>
                </div>
                <button type="submit" class="btn btn-secondary">Nastavit nové heslo</button>
            </form>
        </div>

        <div class="admin-card">
            <h2>Informace o systému</h2>
            <dl class="info-list">
                <dt>PHP verze</dt>
                <dd><?= htmlspecialchars(PHP_VERSION) ?></dd>
                <dt>Databáze</dt>
                <dd><?= htmlspecialchars(realpath(\Aidelnicek\Database::getPath())) ?></dd>
                <dt>Přihlášený admin</dt>
                <dd><?= htmlspecialchars($currentUser['name'] ?? '—') ?></dd>
            </dl>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
