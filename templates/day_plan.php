<?php
use Aidelnicek\MealPlan;
use Aidelnicek\Csrf;

$pageTitle = 'Denní jídelníček';
$csrfToken = Csrf::generate();

$currentRedirect = '/plan/day?day=' . $day;
$householdSelections = $householdSelections ?? [];
$weekPlan = $weekPlan ?? [];
$weekId = $weekId ?? 0;
$householdSlotDetails = $householdSlotDetails ?? [];

// Day navigation
$prevDay = $day > 1 ? $day - 1 : null;
$nextDay = $day < 7 ? $day + 1 : null;

// Date for each day of week (Mon–Sun of current week)
$weekStart = new DateTimeImmutable('monday this week');
$dayDate = $weekStart->modify('+' . ($day - 1) . ' days');

ob_start();
?>
<section class="day-plan">

    <nav class="plan-nav" aria-label="Navigace dní">
        <div class="plan-nav__days">
            <?php for ($d = 1; $d <= 7; $d++): ?>
                <?php
                $dDate    = $weekStart->modify('+' . ($d - 1) . ' days');
                $isActive = $d === $day;
                $isToday  = $d === (int) date('N');
                $classes  = 'plan-nav__day';
                if ($isActive) $classes .= ' is-active';
                if ($isToday)  $classes .= ' is-today';
                ?>
                <a href="/plan/day?day=<?= $d ?>" class="<?= $classes ?>">
                    <span class="plan-nav__day-short"><?= MealPlan::getDayShortLabel($d) ?></span>
                    <span class="plan-nav__day-date"><?= $dDate->format('j.n.') ?></span>
                </a>
            <?php endfor; ?>
        </div>
        <a href="/plan/week" class="plan-nav__week-link">Týdenní přehled</a>
    </nav>

    <div class="day-plan__header-row">
        <h1 class="plan-heading">
            <?= htmlspecialchars(MealPlan::getDayLabel($day)) ?>
            <span class="plan-heading__date"><?= $dayDate->format('j. n. Y') ?></span>
        </h1>
        <button type="button" class="btn btn-secondary btn-sm js-expand-all-variants" id="expand-all-variants-btn" hidden aria-label="Rozbalit všechny skryté varianty">
            Rozbalit všechny varianty
        </button>
    </div>

    <div class="meal-cards">
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
            ?>
            <div class="meal-card" data-meal-type="<?= htmlspecialchars($mealType) ?>"
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
                <div class="meal-alternatives">
                    <?php
                    $chosenAlt = $chosenAltNum !== null ? ($chosenAltNum === 1 ? $alt1 : $alt2) : ($alt1 ?? $alt2);
                    $otherAlt = ($chosenAltNum === 1 ? $alt2 : $alt1);
                    $slotDetail = $householdSlotDetails[$mealType] ?? ['users' => [], 'aggregated_ingredients' => []];
                    $currentUserName = $currentUser['name'] ?? '';
                    ?>
                    <!-- Vybraná varianta: zobrazí se vždy -->
                    <?php if ($chosenAlt !== null): ?>
                        <?php
                        $isEaten = (bool) $chosenAlt['is_eaten'];
                        $hasStoredRecipe = (int) ($chosenAlt['has_recipe'] ?? 0) === 1;
                        ?>
                        <div class="alt-option is-chosen meal-slot-primary"
                             data-plan-id="<?= (int) $chosenAlt['id'] ?>"
                             data-alt="<?= $chosenAltNum ?>">
                            <button type="button"
                                    class="alt-choose-btn"
                                    data-plan-id="<?= (int) $chosenAlt['id'] ?>"
                                    data-redirect="<?= htmlspecialchars($currentRedirect) ?>"
                                    aria-pressed="true">
                                <span class="alt-badge">Varianta <?= $chosenAltNum ?></span>
                                <span class="alt-name"><?= htmlspecialchars($chosenAlt['meal_name']) ?></span>
                            </button>

                            <div class="meal-slot-detail" id="slot-detail-<?= htmlspecialchars($mealType) ?>">
                                <?php if (!empty($chosenAlt['description'])): ?>
                                    <p class="alt-desc"><?= htmlspecialchars($chosenAlt['description']) ?></p>
                                <?php endif; ?>

                                <div class="meal-slot-detail__sections">
                                    <div class="meal-slot-detail__chosen">
                                        <h4 class="meal-slot-detail__heading">Vybrané jídlo</h4>
                                        <?php
                                        $chosenIng = !empty($chosenAlt['ingredients']) ? (json_decode($chosenAlt['ingredients'], true) ?? []) : [];
                                        ?>
                                        <?php if (!empty($chosenIng)): ?>
                                            <ul class="alt-ingredients">
                                                <?php foreach ($chosenIng as $ing): ?>
                                                    <?php if (is_array($ing)): ?>
                                                        <li>
                                                            <?= htmlspecialchars($ing['name'] ?? '') ?>
                                                            <?php if (!empty($ing['quantity'])): ?>
                                                                — <?= htmlspecialchars((string) $ing['quantity']) ?>
                                                                <?php if (!empty($ing['unit'])): ?> <?= htmlspecialchars($ing['unit']) ?><?php endif; ?>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php else: ?>
                                                        <li><?= htmlspecialchars((string) $ing) ?></li>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>

                                    <?php
                                    $otherUsersInSlot = array_filter($slotDetail['users'], fn($u) => $u['user_name'] !== $currentUserName);
                                    ?>
                                    <?php if (!empty($otherUsersInSlot)): ?>
                                        <div class="meal-slot-detail__others">
                                            <h4 class="meal-slot-detail__heading">Ostatní uživatelé</h4>
                                            <?php foreach ($otherUsersInSlot as $ou): ?>
                                                <div class="meal-slot-detail__other-variant">
                                                    <span class="meal-slot-detail__other-user"><?= htmlspecialchars($ou['user_name']) ?>:</span>
                                                    <span class="meal-slot-detail__other-meal"><?= htmlspecialchars($ou['meal_name']) ?></span>
                                                    <?php if (!empty($ou['ingredients'])): ?>
                                                        <ul class="alt-ingredients alt-ingredients--compact">
                                                            <?php foreach ($ou['ingredients'] as $ing): ?>
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
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($slotDetail['aggregated_ingredients'])): ?>
                                        <div class="meal-slot-detail__aggregated">
                                            <h4 class="meal-slot-detail__heading">Ingredience dohromady pro všechny</h4>
                                            <ul class="alt-ingredients">
                                                <?php foreach ($slotDetail['aggregated_ingredients'] as $ing): ?>
                                                    <li>
                                                        <?= htmlspecialchars($ing['name'] ?? '') ?>
                                                        <?php if (isset($ing['quantity']) && $ing['quantity'] !== null): ?>
                                                            — <?= htmlspecialchars((string) $ing['quantity']) ?>
                                                            <?php if (!empty($ing['unit'])): ?> <?= htmlspecialchars($ing['unit']) ?><?php endif; ?>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <button type="button"
                                        class="btn btn-secondary btn-sm meal-recipe-btn"
                                        data-plan-id="<?= (int) $chosenAlt['id'] ?>"
                                        data-has-recipe="<?= $hasStoredRecipe ? '1' : '0' ?>"
                                        aria-expanded="false">
                                    <?= $hasStoredRecipe ? 'Zobraz recept' : 'Generuj recept' ?>
                                </button>
                                <div class="meal-recipe-panel" hidden>
                                    <p class="meal-recipe-meta" hidden></p>
                                    <pre class="meal-recipe-text"></pre>
                                    <a href="/plan/recipe/view?plan_id=<?= (int) $chosenAlt['id'] ?>"
                                       target="_blank" rel="noopener"
                                       class="meal-recipe-open-tab">Otevřít v nové záložce</a>
                                </div>

                                <label class="eaten-checkbox" data-plan-id="<?= (int) $chosenAlt['id'] ?>">
                                    <input type="checkbox"
                                           class="eaten-checkbox__input"
                                           data-plan-id="<?= (int) $chosenAlt['id'] ?>"
                                           data-redirect="<?= htmlspecialchars($currentRedirect) ?>"
                                           <?= $isEaten ? 'checked' : '' ?>>
                                    <span class="eaten-checkbox__label">Snězeno</span>
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Druhá varianta: sbalená, rozbalitelná -->
                    <?php if ($otherAlt !== null): ?>
                        <?php $otherAltNum = $chosenAltNum === 1 ? 2 : 1; ?>
                        <div class="alt-option alt-option--collapsed meal-slot-collapsed" data-collapsed="true"
                             data-plan-id="<?= (int) $otherAlt['id'] ?>"
                             data-alt="<?= $otherAltNum ?>">
                            <button type="button" class="alt-expand-btn js-expand-variant" aria-expanded="false">
                                <span class="alt-expand-btn__icon" aria-hidden="true">▼</span>
                                <span class="alt-expand-btn__label">Rozbalit variantu <?= $otherAltNum ?></span>
                            </button>
                            <div class="alt-option__collapsed-content" hidden>
                                <?php
                                $isEaten = (bool) $otherAlt['is_eaten'];
                                $hasStoredRecipe = (int) ($otherAlt['has_recipe'] ?? 0) === 1;
                                $otherMembers = $householdSelections[$mealType]['alt' . $otherAltNum] ?? [];
                                $ingredients = !empty($otherAlt['ingredients']) ? (json_decode($otherAlt['ingredients'], true) ?? []) : [];
                                ?>
                                <button type="button"
                                        class="alt-choose-btn"
                                        data-plan-id="<?= (int) $otherAlt['id'] ?>"
                                        data-redirect="<?= htmlspecialchars($currentRedirect) ?>"
                                        aria-pressed="false">
                                    <span class="alt-badge">Varianta <?= $otherAltNum ?></span>
                                    <span class="alt-name"><?= htmlspecialchars($otherAlt['meal_name']) ?></span>
                                </button>
                                <?php if (!empty($otherAlt['description'])): ?>
                                    <p class="alt-desc"><?= htmlspecialchars($otherAlt['description']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($ingredients)): ?>
                                    <ul class="alt-ingredients">
                                        <?php foreach ($ingredients as $ing): ?>
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
                                <button type="button" class="btn btn-secondary btn-sm meal-recipe-btn"
                                        data-plan-id="<?= (int) $otherAlt['id'] ?>"
                                        data-has-recipe="<?= $hasStoredRecipe ? '1' : '0' ?>" aria-expanded="false">
                                    <?= $hasStoredRecipe ? 'Zobraz recept' : 'Generuj recept' ?>
                                </button>
                                <div class="meal-recipe-panel" hidden>
                                    <p class="meal-recipe-meta" hidden></p>
                                    <pre class="meal-recipe-text"></pre>
                                    <a href="/plan/recipe/view?plan_id=<?= (int) $otherAlt['id'] ?>"
                                       target="_blank" rel="noopener"
                                       class="meal-recipe-open-tab">Otevřít v nové záložce</a>
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
<form id="form-choose" method="post" action="/plan/choose" style="display:none">
    <?= Csrf::field() ?>
    <input type="hidden" name="plan_id" id="form-choose-plan-id">
    <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($currentRedirect) ?>">
</form>
<form id="form-eaten" method="post" action="/plan/eaten" style="display:none">
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
require __DIR__ . '/layout.php';
