<?php
use Aidelnicek\MealPlan;
use Aidelnicek\Csrf;
use Aidelnicek\Url;

$pageTitle = MealPlan::getMealTypeLabel($mealType) . ' — ' . MealPlan::getDayLabel($day);
$householdSelections = $householdSelections ?? [];
$currentUserName = $currentUser['name'] ?? '';

$backUrl = Url::u('/plan/day?day=' . $day);
$mealDetailRedirect = Url::u('/plan/day/meal?day=' . (int) $day . '&meal_type=' . urlencode($mealType));
$weekStart = $weekStart ?? new DateTimeImmutable('monday this week');
?>
<section class="meal-detail" data-week-id="<?= (int) $weekId ?>" data-current-day="<?= (int) $day ?>">
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
                $mealDetailLink = Url::hu('/plan/day/meal?day=' . $d . '&meal_type=' . urlencode($mealType));
                ?>
                <a href="<?= htmlspecialchars($mealDetailLink) ?>" class="<?= $classes ?>">
                    <span class="plan-nav__day-short"><?= MealPlan::getDayShortLabel($d) ?></span>
                    <span class="plan-nav__day-date"><?= $dDate->format('j.n.') ?></span>
                </a>
            <?php endfor; ?>
        </div>
        <a href="<?= Url::hu('/plan/day?day=' . $day) ?>" class="plan-nav__week-link">Denní přehled</a>
    </nav>

    <div class="meal-detail__header-row">
        <a href="<?= htmlspecialchars($backUrl) ?>" class="meal-detail__back btn btn-secondary btn-sm">← Zpět na denní přehled</a>
        <h1 class="meal-detail__heading plan-heading">
            <?= htmlspecialchars(MealPlan::getMealTypeLabel($mealType)) ?>
            <span class="meal-detail__date plan-heading__date"><?= $dayDate->format('j. n. Y') ?></span>
        </h1>
    </div>

    <div class="meal-detail__content">
        <?php if ($chosenAlt !== null): ?>
            <?php
            $isEaten = (bool) $chosenAlt['is_eaten'];
            $hasStoredRecipe = (int) ($chosenAlt['has_recipe'] ?? 0) === 1;
            ?>
            <div class="meal-detail__primary alt-option is-chosen" data-plan-id="<?= (int) $chosenAlt['id'] ?>">
                <div class="meal-detail__card">
                    <div class="alt-option__header">
                        <span class="alt-badge">Varianta <?= $chosenAltNum ?></span>
                        <span class="alt-name"><?= htmlspecialchars($chosenAlt['meal_name']) ?></span>
                    </div>
                    <div class="alt-choose-actions alt-choose-actions--chosen">
                        <button type="button"
                                class="btn btn-secondary btn-sm alt-choose-btn alt-choose-btn--me"
                                data-plan-id="<?= (int) $chosenAlt['id'] ?>"
                                data-redirect="<?= htmlspecialchars($mealDetailRedirect) ?>"
                                aria-pressed="true">
                            Vybrat pro mě
                        </button>
                        <button type="button"
                                class="btn btn-secondary btn-sm alt-choose-btn alt-choose-btn--household"
                                data-plan-id="<?= (int) $chosenAlt['id'] ?>"
                                data-redirect="<?= htmlspecialchars($mealDetailRedirect) ?>"
                                aria-pressed="false">
                            Vybrat pro všechny
                        </button>
                    </div>

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
                        <a href="<?= Url::hu('/plan/recipe/view?plan_id=' . (int) $chosenAlt['id']) ?>"
                           target="_blank" rel="noopener"
                           class="meal-recipe-open-tab">Otevřít v nové záložce</a>
                    </div>

                    <label class="eaten-checkbox" data-plan-id="<?= (int) $chosenAlt['id'] ?>">
                        <input type="checkbox"
                               class="eaten-checkbox__input"
                               data-plan-id="<?= (int) $chosenAlt['id'] ?>"
                               data-redirect="<?= htmlspecialchars($mealDetailRedirect) ?>"
                               <?= $isEaten ? 'checked' : '' ?>>
                        <span class="eaten-checkbox__label">Snězeno</span>
                    </label>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($otherAlt !== null): ?>
            <?php
            $otherAltNum = $chosenAltNum === 1 ? 2 : 1;
            $hasStoredRecipeOther = (int) ($otherAlt['has_recipe'] ?? 0) === 1;
            $otherMembers = $householdSelections[$mealType]['alt' . $otherAltNum] ?? [];
            $ingredients = !empty($otherAlt['ingredients']) ? (json_decode($otherAlt['ingredients'], true) ?? []) : [];
            ?>
            <div class="meal-detail__secondary alt-option alt-option--collapsed meal-slot-collapsed" data-collapsed="false"
                 data-plan-id="<?= (int) $otherAlt['id'] ?>"
                 data-alt="<?= $otherAltNum ?>">
                <div class="meal-detail__card">
                    <div class="alt-option__header">
                        <span class="alt-badge">Varianta <?= $otherAltNum ?></span>
                        <span class="alt-name"><?= htmlspecialchars($otherAlt['meal_name']) ?></span>
                    </div>
                    <div class="alt-choose-actions">
                        <button type="button"
                                class="btn btn-secondary btn-sm alt-choose-btn alt-choose-btn--me"
                                data-plan-id="<?= (int) $otherAlt['id'] ?>"
                                data-redirect="<?= htmlspecialchars($mealDetailRedirect) ?>"
                                aria-pressed="false">
                            Vybrat pro mě
                        </button>
                        <button type="button"
                                class="btn btn-secondary btn-sm alt-choose-btn alt-choose-btn--household"
                                data-plan-id="<?= (int) $otherAlt['id'] ?>"
                                data-redirect="<?= htmlspecialchars($mealDetailRedirect) ?>"
                                aria-pressed="false">
                            Vybrat pro všechny
                        </button>
                    </div>
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
                            data-has-recipe="<?= $hasStoredRecipeOther ? '1' : '0' ?>" aria-expanded="false">
                        <?= $hasStoredRecipeOther ? 'Zobraz recept' : 'Generuj recept' ?>
                    </button>
                    <div class="meal-recipe-panel" hidden>
                        <p class="meal-recipe-meta" hidden></p>
                        <pre class="meal-recipe-text"></pre>
                        <a href="<?= Url::hu('/plan/recipe/view?plan_id=' . (int) $otherAlt['id']) ?>"
                           target="_blank" rel="noopener"
                           class="meal-recipe-open-tab">Otevřít v nové záložce</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
