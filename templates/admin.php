<?php
use Aidelnicek\ApplicationDataExport;
use Aidelnicek\Url;

$pageTitle = 'Administrace';
$currentUser = \Aidelnicek\Auth::getCurrentUser();

$db    = \Aidelnicek\Database::get();
$users = $db->query('SELECT id, name, email FROM users ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$exportSchemaFingerprint = ApplicationDataExport::schemaFingerprint($db);

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

$mailStatus = \Aidelnicek\Mailer::getAdminStatus();
$emailTestOk = ($_GET['email_test'] ?? '') === 'ok';
$emailTestErr = trim((string) ($_GET['email_test_error'] ?? ''));
$emailTestCsrf = ($_GET['email_test'] ?? '') === 'csrf';

$importOk = ($_GET['import_ok'] ?? '') === '1';
$importRows = isset($_GET['import_rows']) ? (int) $_GET['import_rows'] : 0;
$importTables = isset($_GET['import_tables']) ? (int) $_GET['import_tables'] : 0;
$importErrRaw = isset($_GET['import_error']) ? (string) $_GET['import_error'] : '';
$importErrCsrf = $importErrRaw === 'csrf';
$importErrNoFile = $importErrRaw === 'no_file';
$importErrRead = $importErrRaw === 'read_failed';
$importErrMessage = '';
if ($importErrRaw !== '' && !$importErrCsrf && !$importErrNoFile && !$importErrRead) {
    $importErrMessage = $importErrRaw;
}

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

    <?php if ($emailTestOk): ?>
        <div class="alert alert-success">Testovací e-mail byl odeslán na adresu <?= htmlspecialchars((string) ($currentUser['email'] ?? '')) ?>.</div>
    <?php elseif ($emailTestCsrf): ?>
        <div class="alert alert-error">Neplatný bezpečnostní token (test e-mailu). Zkuste to znovu.</div>
    <?php elseif ($emailTestErr !== ''): ?>
        <div class="alert alert-error">Odeslání testovacího e-mailu selhalo: <?= htmlspecialchars($emailTestErr) ?></div>
    <?php endif; ?>

    <?php if ($importOk): ?>
        <div class="alert alert-success">
            Import dokončen. Zpracováno tabulek: <?= (int) $importTables ?>, vloženo řádků celkem: <?= (int) $importRows ?>.
        </div>
    <?php elseif ($importErrCsrf): ?>
        <div class="alert alert-error">Neplatný bezpečnostní token (import). Zkuste to znovu.</div>
    <?php elseif ($importErrNoFile): ?>
        <div class="alert alert-error">Nebyl vybrán soubor k importu.</div>
    <?php elseif ($importErrRead): ?>
        <div class="alert alert-error">Soubor se nepodařilo přečíst.</div>
    <?php elseif ($importErrMessage !== ''): ?>
        <div class="alert alert-error">Import se nezdařil: <?= htmlspecialchars($importErrMessage) ?></div>
    <?php endif; ?>

    <?php if (!$mailStatus['configured']): ?>
        <div class="alert alert-error" role="status">
            Odesílání e-mailů není k dispozici: v prostředí nejsou nastaveny všechny proměnné
            MAILER_HOST, MAILER_PORT, MAILER_LOGIN, MAILER_PASSWORD.
            <?php if (!empty($mailStatus['missing'])): ?>
                Chybí: <?= htmlspecialchars(implode(', ', $mailStatus['missing'])) ?>.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="admin-cards">
        <div class="admin-card">
            <h2>Prohlížeč tabulek</h2>
            <p>Zobrazení, úprava a mazání záznamů v tabulkách databáze se stránkováním.</p>
            <a href="<?= Url::hu('/admin/table') ?>" class="btn btn-primary">Otevřít prohlížeč</a>
        </div>

        <div class="admin-card">
            <h2>Export / import dat (SQLite)</h2>
            <p>
                Stáhne nebo nahraje <strong>veškerá data této domácnosti</strong> z hlavní databáze.
                Soubory LLM logů (<code>llm_*.db</code>) nejsou součástí exportu.
            </p>
            <dl class="info-list" style="margin-top:0.75rem">
                <dt>Verze formátu exportu</dt>
                <dd><?= (int) ApplicationDataExport::EXPORT_FORMAT_VERSION ?></dd>
                <dt>Otisk schématu (cíl importu musí sedět)</dt>
                <dd style="word-break:break-all;font-family:ui-monospace,monospace;font-size:0.9em"><?= htmlspecialchars($exportSchemaFingerprint) ?></dd>
            </dl>
            <p style="margin-top:1rem">
                <a href="<?= Url::hu('/admin/data-export.json.gz') ?>" class="btn btn-primary">Stáhnout export (.json.gz)</a>
            </p>
            <hr style="margin:1.25rem 0;border:none;border-top:1px solid var(--border-color, #ddd)">
            <p><strong>Import</strong> načte gzip export, smaže obsah všech tabulek v této databázi a vloží data z exportu.
                Funguje jen při <strong>shodné verzi formátu</strong> a <strong>shodném otisku schématu</strong> jako výše.</p>
            <form method="post" action="<?= Url::hu('/admin/data-import') ?>" enctype="multipart/form-data"
                  onsubmit="return confirm('Tímto přepíšete všechna data v databázi této domácnosti. Pokračovat?');">
                <?= \Aidelnicek\Csrf::field() ?>
                <div class="form-group">
                    <label for="import-file">Soubor exportu (.json.gz)</label>
                    <input id="import-file" type="file" name="import_file" accept=".gz,application/gzip" required>
                </div>
                <button type="submit" class="btn btn-secondary">Importovat a přepsat data</button>
            </form>
        </div>

        <div class="admin-card">
            <h2>SQL konzole</h2>
            <p>Spouštění libovolných SQL příkazů přímo nad databází. Historie příkazů se ukládá v prohlížeči.</p>
            <a href="<?= Url::hu('/admin/sql') ?>" class="btn btn-primary">Otevřít konzoli</a>
        </div>

        <div class="admin-card" hidden>
            <h2>Generování demo dat</h2>
            <p>Vygeneruje ukázkový jídelníček pro aktuální týden. Pokud uživatel již data má, nic se nepřepíše.</p>
            <form method="post" action="<?= Url::hu('/admin/seed-demo') ?>">
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
            <a href="<?= Url::hu('/admin/llm-generate') ?>" class="btn btn-primary">Spustit generování</a>
        </div>

        <div class="admin-card">
            <h2>Test LLM</h2>
            <p>Odešlete vlastní prompt na nakonfigurovaný jazykový model a zobrazte vygenerovanou odpověď.</p>
            <a href="<?= Url::hu('/admin/llm-test') ?>" class="btn btn-primary">Otevřít test</a>
        </div>

        <div class="admin-card">
            <h2>LLM komunikační logy</h2>
            <p>Prohlížejte záznamy komunikace s LLM uložené v denních log souborech.</p>
            <a href="<?= Url::hu('/admin/llm-logs') ?>" class="btn btn-primary">Zobrazit logy</a>
        </div>

        <div class="admin-card">
            <h2>Pozvat uživatele</h2>
            <p>Vygenerujte zvací odkaz s omezenou platností pro konkrétní e-mailovou adresu. Odkaz umožní registraci pouze s daným e-mailem.</p>
            <a href="<?= Url::hu('/admin/invite') ?>" class="btn btn-primary">Generovat pozvánku</a>
        </div>

        <div class="admin-card">
            <h2>Reset hesla uživatele</h2>
            <p>Nastavte nové heslo vybranému uživateli. Uživatel se pak přihlásí tímto heslem.</p>
            <form method="post" action="<?= Url::hu('/admin/user-password-reset') ?>">
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
            <h2>Odesílání e-mailů (SMTP)</h2>
            <?php if ($mailStatus['configured']): ?>
                <p>
                    SMTP je nakonfigurováno
                    <?php if ($mailStatus['host'] !== null && $mailStatus['port'] !== null): ?>
                        (<?= htmlspecialchars($mailStatus['host']) ?>:<?= (int) $mailStatus['port'] ?>).
                    <?php else: ?>
                        .
                    <?php endif; ?>
                </p>
                <p>Odešle testovací zprávu na e-mail přihlášeného administrátora.</p>
                <form method="post" action="<?= Url::hu('/admin/mail-test') ?>">
                    <?= \Aidelnicek\Csrf::field() ?>
                    <button type="submit" class="btn btn-secondary">Odeslat testovací e-mail</button>
                </form>
            <?php else: ?>
                <p>Nastavte v prostředí proměnné MAILER_HOST, MAILER_PORT, MAILER_LOGIN a MAILER_PASSWORD. Bez nich nelze e-maily odesílat.</p>
            <?php endif; ?>
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
