<?php
use Aidelnicek\MealPlan;
use Aidelnicek\PlanShare;

$pageTitle = $pageTitle ?? 'Sdílený týdenní jídelníček';
$shareExpiresLabel = $shareExpiresLabel ?? '';
$weekPlan = $weekPlan ?? [];
$week = $week ?? [];
$weekNumber = (int) ($week['week_number'] ?? 0);
$weekYear   = (int) ($week['year'] ?? 0);
$sharedWeekUrl = $sharedWeekUrl ?? '';

$weekStart = (new DateTimeImmutable())->setISODate($weekYear, $weekNumber, 1);

$shareUserId   = (int) ($share['user_id'] ?? 0);
$shareWeekId   = (int) ($week['id'] ?? 0);
$shareExpires  = (int) ($share['expires'] ?? 0);
$sharedDayUrls = [];
for ($__d = 1; $__d <= 7; $__d++) {
    $sharedDayUrls[$__d] = PlanShare::getSignedPlanUrl(
        $shareUserId,
        $shareWeekId,
        $__d,
        PlanShare::getDefaultValidityHours(),
        $shareExpires
    );
}

ob_start();
?>
<section class="shared-plan">
    <div class="shared-plan__hero">
        <p class="shared-plan__eyebrow">Veřejně sdílený jídelníček</p>
        <h1 class="plan-heading">Týden <?= $weekNumber ?>/<?= $weekYear ?></h1>
        <p class="shared-plan__meta">Vybrané varianty pro celý týden.</p>
        <?php if ($shareExpiresLabel !== ''): ?>
            <p class="shared-plan__expires text-muted">Odkaz je platný do <?= htmlspecialchars($shareExpiresLabel) ?>.</p>
        <?php endif; ?>
    </div>

    <div class="week-table-wrap">
        <table class="week-table week-table--shared">
            <thead>
                <tr>
                    <th class="week-table__meal-col"></th>
                    <?php for ($d = 1; $d <= 7; $d++): ?>
                        <?php $dDate = $weekStart->modify('+' . ($d - 1) . ' days'); ?>
                        <th>
                            <a href="<?= htmlspecialchars($sharedDayUrls[$d] ?? '#') ?>" class="week-table__day-link">
                                <span class="week-table__day-short"><?= MealPlan::getDayShortLabel($d) ?></span>
                                <span class="week-table__day-date"><?= $dDate->format('j.n.') ?></span>
                            </a>
                        </th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach (MealPlan::getMealTypeOrder() as $mealType): ?>
                    <tr>
                        <th class="week-table__meal-label">
                            <?= htmlspecialchars(MealPlan::getMealTypeLabel($mealType)) ?>
                        </th>
                        <?php for ($d = 1; $d <= 7; $d++): ?>
                            <?php $chosen = $weekPlan[$d][$mealType] ?? null; ?>
                            <td>
                                <?php if (is_array($chosen)): ?>
                                    <div class="week-table__cell-link--shared">
                                        <a href="<?= htmlspecialchars($sharedDayUrls[$d] ?? '#') ?>" class="week-table__cell-link">
                                            <?= htmlspecialchars((string) ($chosen['meal_name'] ?? '')) ?>
                                        </a>
                                        <?php if ((int) ($chosen['has_recipe'] ?? 0) === 1): ?>
                                            <a
                                                href="<?= htmlspecialchars(PlanShare::getSignedRecipeUrl($shareUserId, (int) $chosen['id'], $shareWeekId, $d, PlanShare::getDefaultValidityHours(), $shareExpires)) ?>"
                                                class="week-table__recipe-link"
                                            >
                                                Recept
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="week-table__empty">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
