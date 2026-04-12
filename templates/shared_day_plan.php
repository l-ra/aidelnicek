<?php
use Aidelnicek\MealPlan;
use Aidelnicek\PlanShare;

$week = $week ?? [];
$weekNumber = (int) ($week['week_number'] ?? 0);
$weekYear   = (int) ($week['year'] ?? 0);
$day        = isset($day) ? (int) $day : 1;
$dayPlan    = is_array($dayPlan ?? null) ? $dayPlan : [];
$shareExpiresLabel = (string) ($shareExpiresLabel ?? '');
$sharedWeekUrl = (string) ($sharedWeekUrl ?? '');

$weekStart = (new DateTimeImmutable())->setISODate($weekYear, $weekNumber, 1);
$dayDate   = $weekStart->modify('+' . ($day - 1) . ' days');

ob_start();
?>
<section class="shared-plan shared-plan--day">
    <div class="shared-plan__hero">
        <div>
            <p class="shared-plan__eyebrow">Veřejně sdílený jídelníček</p>
            <h1 class="plan-heading">
                <?= htmlspecialchars(MealPlan::getDayLabel($day)) ?>
                <span class="plan-heading__date"><?= $dayDate->format('j. n. Y') ?></span>
            </h1>
            <p class="shared-plan__meta">
                Týden <?= $weekNumber ?>/<?= $weekYear ?>
                <?php if ($shareExpiresLabel !== ''): ?>
                    <span class="shared-plan__separator">•</span>
                    Platnost odkazu do <?= htmlspecialchars($shareExpiresLabel) ?>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($sharedWeekUrl !== ''): ?>
            <a href="<?= htmlspecialchars($sharedWeekUrl) ?>" class="btn btn-secondary btn-sm">Zobrazit celý týden</a>
        <?php endif; ?>
    </div>

    <div class="meal-cards meal-cards--compact">
        <?php foreach (MealPlan::getMealTypeOrder() as $mealType): ?>
            <?php $meal = $dayPlan[$mealType] ?? null; ?>
            <article class="meal-card meal-card--compact meal-card--shared">
                <div class="meal-card__header meal-card__header-row">
                    <span><?= htmlspecialchars(MealPlan::getMealTypeLabel($mealType)) ?></span>
                </div>
                <div class="meal-card__shared-body">
                    <?php if ($meal !== null): ?>
                        <h2 class="meal-card__shared-title"><?= htmlspecialchars((string) ($meal['meal_name'] ?? '')) ?></h2>
                        <?php if (!empty($meal['description'])): ?>
                            <p class="alt-desc"><?= htmlspecialchars((string) $meal['description']) ?></p>
                        <?php endif; ?>

                        <?php
                        $ingredients = !empty($meal['ingredients']) ? (json_decode((string) $meal['ingredients'], true) ?? []) : [];
                        ?>
                        <?php if (!empty($ingredients)): ?>
                            <ul class="alt-ingredients alt-ingredients--compact">
                                <?php foreach ($ingredients as $ing): ?>
                                    <?php if (is_array($ing)): ?>
                                        <li>
                                            <?= htmlspecialchars((string) ($ing['name'] ?? '')) ?>
                                            <?php if (!empty($ing['quantity'])): ?>
                                                — <?= htmlspecialchars((string) $ing['quantity']) ?>
                                                <?php if (!empty($ing['unit'])): ?> <?= htmlspecialchars((string) $ing['unit']) ?><?php endif; ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php else: ?>
                                        <li><?= htmlspecialchars((string) $ing) ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ((int) ($meal['has_recipe'] ?? 0) === 1 && (int) ($meal['id'] ?? 0) > 0): ?>
                            <div class="shared-plan__actions">
                                <a href="<?= htmlspecialchars(PlanShare::getSignedRecipeUrl(
                                    (int) ($meal['user_id'] ?? 0),
                                    (int) ($meal['id'] ?? 0),
                                    (int) ($meal['week_id'] ?? 0),
                                    (int) ($meal['day_of_week'] ?? $day),
                                    PlanShare::getDefaultValidityHours(),
                                    isset($share['expires']) ? (int) $share['expires'] : null
                                )) ?>" class="btn btn-secondary btn-sm">Zobrazit recept</a>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-muted">Pro toto jídlo zatím není vybraná varianta.</p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
