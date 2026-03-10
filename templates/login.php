<?php
$pageTitle = 'Přihlášení';
ob_start();
?>
<section class="auth-form">
    <h1>Přihlášení</h1>
    <form method="post" action="/login">
        <div class="form-group">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required>
        </div>
        <div class="form-group">
            <label for="password">Heslo</label>
            <input type="password" id="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary">Přihlásit se</button>
    </form>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
