<?php
use Aidelnicek\MealPlan;
use Aidelnicek\Csrf;

$pageTitle = 'Denní jídelníček';
$csrfToken = Csrf::generate();

$currentRedirect = '/plan/day?day=' . $day;
$householdSelections = $householdSelections ?? [];

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

    <h1 class="plan-heading">
        <?= htmlspecialchars(MealPlan::getDayLabel($day)) ?>
        <span class="plan-heading__date"><?= $dayDate->format('j. n. Y') ?></span>
    </h1>

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
            <div class="meal-card" data-meal-type="<?= htmlspecialchars($mealType) ?>">
                <div class="meal-card__header">
                    <?= htmlspecialchars(MealPlan::getMealTypeLabel($mealType)) ?>
                </div>
                <div class="meal-alternatives">
                    <?php foreach ([1 => $alt1, 2 => $alt2] as $altNum => $alt): ?>
                        <?php if ($alt === null): continue; endif; ?>
                        <?php
                        $isChosen = $chosenAltNum !== null && $chosenAltNum === $altNum;
                        $isEaten  = (bool) $alt['is_eaten'];
                        $hasStoredRecipe = (int) ($alt['has_recipe'] ?? 0) === 1;
                        $otherMembers = $householdSelections[$mealType]['alt' . $altNum] ?? [];
                        $altClass = 'alt-option';
                        if ($isChosen) $altClass .= ' is-chosen';
                        if ($isEaten)  $altClass .= ' is-eaten';

                        $ingredients = [];
                        if (!empty($alt['ingredients'])) {
                            $ingredients = json_decode($alt['ingredients'], true) ?? [];
                        }
                        ?>
                        <div class="<?= $altClass ?>"
                             data-plan-id="<?= (int) $alt['id'] ?>"
                             data-alt="<?= $altNum ?>">

                            <button type="button"
                                    class="alt-choose-btn"
                                    data-plan-id="<?= (int) $alt['id'] ?>"
                                    data-redirect="<?= htmlspecialchars($currentRedirect) ?>"
                                    aria-pressed="<?= $isChosen ? 'true' : 'false' ?>">
                                <span class="alt-badge">Varianta <?= $altNum ?></span>
                                <span class="alt-name"><?= htmlspecialchars($alt['meal_name']) ?></span>
                            </button>

                            <?php if (!empty($alt['description'])): ?>
                                <p class="alt-desc"><?= htmlspecialchars($alt['description']) ?></p>
                            <?php endif; ?>

                            <?php if (!empty($ingredients)): ?>
                                <ul class="alt-ingredients">
                                    <?php foreach ($ingredients as $ing): ?>
                                        <?php if (is_array($ing)): ?>
                                            <li>
                                                <?= htmlspecialchars($ing['name'] ?? '') ?>
                                                <?php if (!empty($ing['quantity'])): ?>
                                                    — <?= htmlspecialchars((string) $ing['quantity']) ?>
                                                    <?php if (!empty($ing['unit'])): ?>
                                                        <?= htmlspecialchars($ing['unit']) ?>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </li>
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

                            <button type="button"
                                    class="btn btn-secondary btn-sm meal-recipe-btn"
                                    data-plan-id="<?= (int) $alt['id'] ?>"
                                    data-has-recipe="<?= $hasStoredRecipe ? '1' : '0' ?>"
                                    aria-expanded="false">
                                <?= $hasStoredRecipe ? 'Zobraz recept' : 'Generuj recept' ?>
                            </button>

                            <div class="meal-recipe-panel" hidden>
                                <p class="meal-recipe-meta" hidden></p>
                                <pre class="meal-recipe-text"></pre>
                                <a href="/plan/recipe/view?plan_id=<?= (int) $alt['id'] ?>"
                                   target="_blank" rel="noopener"
                                   class="meal-recipe-open-tab">Otevřít v nové záložce</a>
                            </div>

                            <?php if ($isChosen): ?>
                                <label class="eaten-checkbox" data-plan-id="<?= (int) $alt['id'] ?>">
                                    <input type="checkbox"
                                           class="eaten-checkbox__input"
                                           data-plan-id="<?= (int) $alt['id'] ?>"
                                           data-redirect="<?= htmlspecialchars($currentRedirect) ?>"
                                           <?= $isEaten ? 'checked' : '' ?>>
                                    <span class="eaten-checkbox__label">Snězeno</span>
                                </label>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
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
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
