<?php
use Aidelnicek\MealPlan;
use Aidelnicek\Csrf;
use Aidelnicek\Url;

$pageTitle = 'Denní jídelníček';
$csrfToken = Csrf::generate();

$week = $week ?? [];
$weekNumber = (int) ($week['week_number'] ?? 0);
$weekYear   = (int) ($week['year'] ?? 0);

$currentRedirect = Url::u(Url::planDayPath($day, $week));
$householdSelections = $householdSelections ?? [];
$weekPlan = $weekPlan ?? [];
$weekId = $weekId ?? 0;

// Day navigation
$prevDay = $day > 1 ? $day - 1 : null;
$nextDay = $day < 7 ? $day + 1 : null;

// Date for each day of week (Mon–Sun of selected ISO week)
$weekStart = (new DateTimeImmutable())->setISODate($weekYear, $weekNumber, 1);
$dayDate = $weekStart->modify('+' . ($day - 1) . ' days');

$prevWeekDt = $weekStart->modify('-1 week');
$nextWeekDt = $weekStart->modify('+1 week');
$prevWeekNav = ['week_number' => (int) $prevWeekDt->format('W'), 'year' => (int) $prevWeekDt->format('o')];
$nextWeekNav = ['week_number' => (int) $nextWeekDt->format('W'), 'year' => (int) $nextWeekDt->format('o')];

$currentDt         = new DateTimeImmutable();
$isViewedWeekToday = ((int) $currentDt->format('W') === $weekNumber && (int) $currentDt->format('o') === $weekYear);
$todayIso          = (int) date('N');

ob_start();
?>
<section class="day-plan">

    <nav class="plan-nav" aria-label="Navigace dní">
        <a href="<?= Url::hu(Url::planDayPath($day, $prevWeekNav)) ?>"
           class="plan-nav__week-step plan-nav__week-step--prev btn btn-secondary btn-sm"
           title="Předchozí týden">&larr;</a>
        <div class="plan-nav__days">
            <?php for ($d = 1; $d <= 7; $d++): ?>
                <?php
                $dDate    = $weekStart->modify('+' . ($d - 1) . ' days');
                $isActive = $d === $day;
                $isToday  = $isViewedWeekToday && $d === $todayIso;
                $classes  = 'plan-nav__day';
                if ($isActive) $classes .= ' is-active';
                if ($isToday)  $classes .= ' is-today';
                ?>
                <a href="<?= Url::hu(Url::planDayPath($d, $week)) ?>" class="<?= $classes ?>">
                    <span class="plan-nav__day-short"><?= MealPlan::getDayShortLabel($d) ?></span>
                    <span class="plan-nav__day-date"><?= $dDate->format('j.n.') ?></span>
                </a>
            <?php endfor; ?>
        </div>
        <a href="<?= Url::hu(Url::planDayPath($day, $nextWeekNav)) ?>"
           class="plan-nav__week-step plan-nav__week-step--next btn btn-secondary btn-sm"
           title="Následující týden">&rarr;</a>
        <a href="<?= Url::hu('/plan/week?week=' . $weekNumber . '&year=' . $weekYear) ?>" class="plan-nav__week-link">Týden <?= $weekNumber ?>/<?= $weekYear ?></a>
    </nav>

    <div class="day-plan__header-row">
        <h1 class="plan-heading">
            <?= htmlspecialchars(MealPlan::getDayLabel($day)) ?>
            <span class="plan-heading__date"><?= $dayDate->format('j. n. Y') ?></span>
        </h1>
        <div class="plan-share-actions">
            <?php if (!empty($shareSignedUrl ?? '')): ?>
                <button type="button"
                        class="btn btn-secondary btn-sm js-copy-signed-link"
                        data-copy-url="<?= htmlspecialchars($shareSignedUrl) ?>"
                        title="Veřejný odkaz platný <?= (int) ($shareValidityHours ?? 0) ?> hodin">
                    Sdílet denní jídelníček
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary btn-sm js-expand-all-variants" id="expand-all-variants-btn" hidden aria-label="Rozbalit všechny skryté varianty">
                Rozbalit všechny varianty
            </button>
        </div>
    </div>

    <div class="meal-cards meal-cards--compact">
        <?php foreach (MealPlan::getMealTypeOrder() as $mealType): ?>
            <?php
            $slot = $dayPlan[$mealType] ?? ['alt1' => null, 'alt2' => null];
            $alt1 = $slot['alt1'];
            $alt2 = $slot['alt2'];
            $chosenAltNum = null;
            foreach ([1 => $alt1, 2 => $alt2] as $candidateAltNum => $candidateAlt) {
                if ($candidateAlt !== null && (int) ($candidateAlt['is_chosen'] ?? 0) === 1) {
                    $chosenAltNum = $candidateAltNum;
                    break;
                }
            }
            if ($chosenAltNum === null) {
                $chosenAltNum = $alt1 !== null ? 1 : ($alt2 !== null ? 2 : null);
            }
            $chosenAlt = $chosenAltNum !== null ? ($chosenAltNum === 1 ? $alt1 : $alt2) : ($alt1 ?? $alt2);
            $otherAlt = ($chosenAltNum === 1 ? $alt2 : $alt1);
            $mealDetailUrl = Url::u(Url::planDayMealPath($day, $mealType, $week));
            ?>
            <div class="meal-card meal-card--compact" data-meal-type="<?= htmlspecialchars($mealType) ?>"
                 data-week-id="<?= (int) $weekId ?>"
                 data-current-day="<?= (int) $day ?>"
                 data-redirect="<?= htmlspecialchars($currentRedirect) ?>">
                <div class="meal-card__header meal-card__header-row">
                    <span><?= htmlspecialchars(MealPlan::getMealTypeLabel($mealType)) ?></span>
                    <?php if ($weekId > 0): ?>
                    <?php
                    $swapOptions = [];
                    for ($d = 1; $d <= 7; $d++) {
                        if ($d === $day) continue;
                        $otherSlot = $weekPlan[$d][$mealType] ?? ['alt1' => null, 'alt2' => null];
                        $otherChosen = null;
                        foreach (['alt1', 'alt2'] as $k) {
                            $row = $otherSlot[$k] ?? null;
                            if ($row !== null && (int) ($row['is_chosen'] ?? 0) === 1) {
                                $otherChosen = $row;
                                break;
                            }
                        }
                        if ($otherChosen === null) {
                            $otherChosen = $otherSlot['alt1'] ?? $otherSlot['alt2'];
                        }
                        $ingredients = [];
                        if (!empty($otherChosen['ingredients'])) {
                            $ingredients = json_decode($otherChosen['ingredients'], true) ?? [];
                        }
                        $swapOptions[] = [
                            'day' => $d,
                            'dayLabel' => MealPlan::getDayShortLabel($d),
                            'dayFullLabel' => MealPlan::getDayLabel($d),
                            'mealName' => $otherChosen['meal_name'] ?? '—',
                            'description' => $otherChosen['description'] ?? '',
                            'ingredients' => $ingredients,
                        ];
                    }
                    ?>
                    <div class="meal-card__swap-wrap">
                        <button type="button"
                                class="btn btn-secondary btn-sm meal-card__swap-btn"
                                data-meal-type="<?= htmlspecialchars($mealType) ?>"
                                data-meal-type-label="<?= htmlspecialchars(MealPlan::getMealTypeLabel($mealType)) ?>"
                                data-swap-options="<?= htmlspecialchars(json_encode($swapOptions)) ?>"
                                aria-haspopup="dialog"
                                aria-label="Vyměnit <?= htmlspecialchars(MealPlan::getMealTypeLabel($mealType)) ?> za jiný den">
                            Vyměnit za…
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="meal-alternatives meal-alternatives--compact">
                    <?php if ($chosenAlt !== null): ?>
                        <?php
                        $isEaten = (bool) $chosenAlt['is_eaten'];
                        ?>
                        <div class="alt-option is-chosen meal-slot-primary meal-slot-summary"
                             data-plan-id="<?= (int) $chosenAlt['id'] ?>"
                             data-alt="<?= $chosenAltNum ?>">
                            <a href="<?= htmlspecialchars($mealDetailUrl) ?>" class="meal-slot-summary__link" data-meal-detail>
                                <span class="alt-badge">Varianta <?= $chosenAltNum ?></span>
                                <span class="alt-name"><?= htmlspecialchars($chosenAlt['meal_name']) ?></span>
                                <?php if ($isEaten): ?>
                                    <span class="eaten-mark" title="Snězeno">&#10003;</span>
                                <?php endif; ?>
                            </a>
                            <?php
                            $chosenIngredients = !empty($chosenAlt['ingredients']) ? (json_decode($chosenAlt['ingredients'], true) ?? []) : [];
                            ?>
                            <?php if (!empty($chosenIngredients)): ?>
                                <ul class="alt-ingredients alt-ingredients--compact meal-slot-summary__ingredients">
                                    <?php foreach (array_slice($chosenIngredients, 0, 5) as $ing): ?>
                                        <?php if (is_array($ing)): ?>
                                            <li><?= htmlspecialchars($ing['name'] ?? '') ?>
                                                <?php if (!empty($ing['quantity'])): ?>
                                                    — <?= htmlspecialchars((string) $ing['quantity']) ?>
                                                    <?php if (!empty($ing['unit'])): ?> <?= htmlspecialchars($ing['unit']) ?><?php endif; ?>
                                                <?php endif; ?></li>
                                        <?php else: ?>
                                            <li><?= htmlspecialchars((string) $ing) ?></li>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (count($chosenIngredients) > 5): ?>
                                        <li class="alt-ingredients__more"><a href="<?= htmlspecialchars($mealDetailUrl) ?>">… celý detail</a></li>
                                    <?php endif; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($otherAlt !== null): ?>
                        <?php $otherAltNum = $chosenAltNum === 1 ? 2 : 1; ?>
                        <div class="alt-option alt-option--collapsed meal-slot-collapsed js-collapsed-variant" data-collapsed="true"
                             data-plan-id="<?= (int) $otherAlt['id'] ?>"
                             data-alt="<?= $otherAltNum ?>">
                            <button type="button" class="alt-expand-btn js-expand-variant" aria-expanded="false">
                                <span class="alt-expand-btn__icon" aria-hidden="true">▼</span>
                                <span class="alt-expand-btn__label">Rozbalit variantu <?= $otherAltNum ?></span>
                            </button>
                            <div class="alt-option__collapsed-content" hidden>
                                <?php
                                $hasStoredRecipe = (int) ($otherAlt['has_recipe'] ?? 0) === 1;
                                $otherMembers = $householdSelections[$mealType]['alt' . $otherAltNum] ?? [];
                                $ingredients = !empty($otherAlt['ingredients']) ? (json_decode($otherAlt['ingredients'], true) ?? []) : [];
                                ?>
                                <div class="alt-option__collapsed-inner">
                                    <div class="alt-option__header">
                                        <span class="alt-badge">Varianta <?= $otherAltNum ?></span>
                                        <span class="alt-name"><?= htmlspecialchars($otherAlt['meal_name']) ?></span>
                                    </div>
                                    <div class="alt-choose-actions">
                                        <button type="button"
                                                class="btn btn-secondary btn-sm alt-choose-btn alt-choose-btn--me"
                                                data-plan-id="<?= (int) $otherAlt['id'] ?>"
                                                data-redirect="<?= htmlspecialchars($currentRedirect) ?>"
                                                aria-pressed="false">
                                            Vybrat pro mě
                                        </button>
                                        <button type="button"
                                                class="btn btn-secondary btn-sm alt-choose-btn alt-choose-btn--household"
                                                data-plan-id="<?= (int) $otherAlt['id'] ?>"
                                                data-redirect="<?= htmlspecialchars($currentRedirect) ?>"
                                                aria-pressed="false">
                                            Vybrat pro všechny
                                        </button>
                                    </div>
                                    <?php if (!empty($otherAlt['description'])): ?>
                                        <p class="alt-desc"><?= htmlspecialchars($otherAlt['description']) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($ingredients)): ?>
                                        <ul class="alt-ingredients alt-ingredients--compact">
                                            <?php foreach (array_slice($ingredients, 0, 5) as $ing): ?>
                                                <?php if (is_array($ing)): ?>
                                                    <li><?= htmlspecialchars($ing['name'] ?? '') ?>
                                                        <?php if (!empty($ing['quantity'])): ?>
                                                            — <?= htmlspecialchars((string) $ing['quantity']) ?>
                                                            <?php if (!empty($ing['unit'])): ?> <?= htmlspecialchars($ing['unit']) ?><?php endif; ?>
                                                        <?php endif; ?></li>
                                                <?php else: ?>
                                                    <li><?= htmlspecialchars((string) $ing) ?></li>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            <?php if (count($ingredients) > 5): ?>
                                                <li class="alt-ingredients__more"><a href="<?= htmlspecialchars($mealDetailUrl) ?>">… celý detail</a></li>
                                            <?php endif; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if (!empty($otherMembers)): ?>
                                        <div class="alt-household-pref">
                                            <span class="alt-household-pref__label">Zvolili:</span>
                                            <span class="alt-household-pref__users">
                                                <?php foreach ($otherMembers as $memberName): ?>
                                                    <span class="alt-household-pref__user"><?= htmlspecialchars((string) $memberName) ?></span>
                                                <?php endforeach; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <a href="<?= htmlspecialchars($mealDetailUrl) ?>" class="btn btn-secondary btn-sm">Otevřít detail</a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</section>

<!-- Hidden CSRF forms used by JS for non-AJAX fallback -->
<form id="form-choose" method="post" action="<?= Url::hu('/plan/choose') ?>" style="display:none">
    <?= Csrf::field() ?>
    <input type="hidden" name="plan_id" id="form-choose-plan-id">
    <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($currentRedirect) ?>">
</form>
<form id="form-eaten" method="post" action="<?= Url::hu('/plan/eaten') ?>" style="display:none">
    <?= Csrf::field() ?>
    <input type="hidden" name="plan_id" id="form-eaten-plan-id">
    <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($currentRedirect) ?>">
</form>

<!-- Modal pro výběr jídla k výměně -->
<div id="swap-meal-modal" class="modal swap-meal-modal" hidden aria-modal="true" role="dialog" aria-labelledby="swap-meal-modal-title">
    <div class="modal-backdrop swap-meal-modal__backdrop"></div>
    <div class="modal-content swap-meal-modal__content">
        <h2 id="swap-meal-modal-title" class="swap-meal-modal__title">Vyměnit jídlo</h2>
        <p class="swap-meal-modal__subtitle" id="swap-meal-modal-subtitle"></p>
        <div class="swap-meal-modal__scope">
            <label class="swap-meal-modal__scope-toggle">
                <input type="checkbox" id="swap-meal-modal-scope-user-only" value="1" aria-describedby="swap-meal-modal-scope-hint">
                <span class="swap-meal-modal__scope-label">Výměna jen u mě</span>
            </label>
            <p id="swap-meal-modal-scope-hint" class="swap-meal-modal__scope-hint text-muted">
                Ve výchozím režimu se jídlo vymění u všech členů rodiny. Zaškrtnutím pouze u vás.
            </p>
        </div>
        <div class="swap-meal-modal__list" id="swap-meal-modal-list" role="list"></div>
        <div class="modal-actions swap-meal-modal__actions">
            <button type="button" class="btn btn-secondary" id="swap-meal-modal-close">Zrušit</button>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
$planDayJsDefault = ['day' => $day, 'week' => $weekNumber, 'year' => $weekYear];
require __DIR__ . '/layout.php';
