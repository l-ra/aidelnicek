<?php
$pageTitle = 'Dashboard';
ob_start();
?>
<section class="dashboard">
    <h1>Vítejte v Aidelnicek</h1>
    <p class="lead">Aplikace pro zdravé stravování a týdenní jídelníčky.</p>
    <p>Registrujte se nebo se přihlaste pro přístup k jídelníčkům.</p>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
