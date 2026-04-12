<?php
use Aidelnicek\Csrf;
use Aidelnicek\MealPlan;
use Aidelnicek\Url;

$pageTitle = 'Týdenní jídelníček';

$weekNumber = (int) $week['week_number'];
$weekYear   = (int) $week['year'];

// Compute prev / next week numbers
$currentDt  = new DateTimeImmutable();
$weekStart  = (new DateTimeImmutable())->setISODate($weekYear, $weekNumber, 1);

$prevWeekDt = $weekStart->modify('-1 week');
$nextWeekDt = $weekStart->modify('+1 week');

$prevWeek   = (int) $prevWeekDt->format('W');
$prevYear   = (int) $prevWeekDt->format('o');
$nextWeek   = (int) $nextWeekDt->format('W');
$nextYear   = (int) $nextWeekDt->format('o');

$isCurrentWeek = ((int) $currentDt->format('W') === $weekNumber && (int) $currentDt->format('o') === $weekYear);

$todayIso            = $todayIso ?? (int) date('N');
$currentCalendarWeek = MealPlan::getOrCreateCurrentWeek();

ob_start();
?>
<section class="week-plan">

    <div class="week-plan__header">
        <a href="<?= Url::hu('/plan/week?week=' . $prevWeek . '&year=' . $prevYear) ?>" class="btn btn-secondary btn-sm">
            &larr; Předchozí
        </a>
        <h1 class="plan-heading">
            Týden <?= $weekNumber ?>/<?= $weekYear ?>
            <?php if ($isCurrentWeek): ?>
                <span class="badge-current">aktuální</span>
            <?php endif; ?>
        </h1>
        <div class="plan-share-actions">
            <?php if (!empty($shareSignedUrl ?? '')): ?>
            <button
                type="button"
                class="btn btn-secondary btn-sm js-copy-signed-link"
                data-copy-url="<?= htmlspecialchars($shareSignedUrl, ENT_QUOTES, 'UTF-8') ?>"
                title="Veřejný odkaz platný <?= (int) ($shareValidityHours ?? 0) ?> hodin"
            >
                Sdílet týden
            </button>
            <?php endif; ?>
        </div>
        <a href="<?= Url::hu('/plan/week?week=' . $nextWeek . '&year=' . $nextYear) ?>" class="btn btn-secondary btn-sm">
            Další &rarr;
        </a>
    </div>

    <div class="week-table-wrap">
        <table class="week-table">
            <thead>
                <tr>
                    <th class="week-table__meal-col"></th>
                    <?php for ($d = 1; $d <= 7; $d++): ?>
                        <?php
                        $dDate   = $weekStart->modify('+' . ($d - 1) . ' days');
                        $isToday = $isCurrentWeek && $d === $todayIso;
                        ?>
                        <th class="<?= $isToday ? 'today' : '' ?>">
                            <a href="<?= Url::hu(Url::planDayPath($d, $week)) ?>" class="week-table__day-link">
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
                            <?php
                            $slot     = $weekPlan[$d][$mealType] ?? ['alt1' => null, 'alt2' => null];
                            $chosen   = null;
                            $isToday  = $isCurrentWeek && $d === $todayIso;
                            $tdClass  = $isToday ? 'today' : '';

                            // Find chosen alternative, or fall back to alt1
                            foreach (['alt1', 'alt2'] as $k) {
                                if ($slot[$k] !== null && (int) $slot[$k]['is_chosen']) {
                                    $chosen = $slot[$k];
                                    break;
                                }
                            }
                            if ($chosen === null) {
                                $chosen = $slot['alt1'] ?? $slot['alt2'];
                            }

                            $isEaten = $chosen && (int) $chosen['is_eaten'];
                            ?>
                            <td class="<?= $tdClass ?>">
                                <?php if ($chosen): ?>
                                    <a href="<?= Url::hu(Url::planDayPath($d, $week)) ?>" class="week-table__cell-link <?= $isEaten ? 'is-eaten' : '' ?>">
                                        <?= htmlspecialchars($chosen['meal_name']) ?>
                                        <?php if ($isEaten): ?>
                                            <span class="eaten-mark" title="Snězeno">&#10003;</span>
                                        <?php endif; ?>
                                    </a>
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

    <div class="week-plan__footer">
        <a href="<?= Url::hu(Url::planDayPath($todayIso, $currentCalendarWeek)) ?>" class="btn btn-primary">Dnešní plán</a>
    </div>

</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
