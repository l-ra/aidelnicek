<?php
/** @var array{meal_name: string, recipe_text: string} $recipe */
$recipeBackUrl = $recipeBackUrl ?? \Aidelnicek\Url::u('/plan/day');

$pageTitle = htmlspecialchars($recipe['meal_name']);
?>
<section class="recipe-view">
    <h1 class="recipe-view__title"><?= htmlspecialchars($recipe['meal_name']) ?></h1>
    <a href="<?= htmlspecialchars($recipeBackUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-sm recipe-view__back">← Zpět na jídelníček</a>
    <pre class="recipe-view__text"><?= htmlspecialchars($recipe['recipe_text']) ?></pre>
</section>
