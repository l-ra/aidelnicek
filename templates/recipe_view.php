<?php
/** @var array{meal_name: string, recipe_text: string} $recipe */
use Aidelnicek\Url;

$pageTitle = htmlspecialchars($recipe['meal_name']);
?>
<section class="recipe-view">
    <h1 class="recipe-view__title"><?= htmlspecialchars($recipe['meal_name']) ?></h1>
    <a href="<?= Url::hu('/plan/day') ?>" class="btn btn-secondary btn-sm recipe-view__back">← Zpět na jídelníček</a>
    <pre class="recipe-view__text"><?= htmlspecialchars($recipe['recipe_text']) ?></pre>
</section>
