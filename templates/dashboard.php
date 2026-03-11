<?php
$pageTitle = 'Dashboard';
$user = \Aidelnicek\Auth::getCurrentUser();
$csrfError = ($_GET['error'] ?? '') === 'csrf';
ob_start();
?>
<section class="dashboard">
    <?php if ($csrfError): ?>
        <p class="alert alert-error">Reload stránky a zkuste znovu.</p>
    <?php endif; ?>
    <h1>Vítejte, <?= htmlspecialchars($user['name']) ?>!</h1>
    <p class="lead">Aplikace pro zdravé stravování a týdenní jídelníčky.</p>
    <p>Jídelníčky budou dostupné v pozdějších milnících. Prozatím můžete upravit svůj <a href="/profile">profil</a>.</p>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
