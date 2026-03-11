<?php
use Aidelnicek\Auth;
use Aidelnicek\MealPlan;

$pageTitle  = 'Dashboard';
$user       = Auth::getCurrentUser();
$csrfError  = ($_GET['error'] ?? '') === 'csrf';
$userId     = (int) $user['id'];
$todayIso   = (int) date('N'); // 1=Mon … 7=Sun

$week    = MealPlan::getOrCreateCurrentWeek();
$weekId  = (int) $week['id'];
MealPlan::seedDemoWeek($userId, $weekId);
$dayPlan = MealPlan::getDayPlan($userId, $weekId, $todayIso);

ob_start();
?>
<section class="dashboard">
    <?php if ($csrfError): ?>
        <p class="alert alert-error">Reload stránky a zkuste znovu.</p>
    <?php endif; ?>

    <h1>Vítejte, <?= htmlspecialchars($user['name']) ?>!</h1>
    <p class="lead">Zdravé stravování pro celou domácnost.</p>

    <div class="dashboard-today">
        <div class="dashboard-today__header">
            <h2>Dnešní jídelníček
                <span class="dashboard-today__day">
                    &mdash; <?= htmlspecialchars(MealPlan::getDayLabel($todayIso)) ?>
                    <?= date('j. n. Y') ?>
                </span>
            </h2>
            <a href="/plan/day" class="btn btn-primary btn-sm">Otevřít</a>
        </div>

        <ul class="dashboard-meal-list">
            <?php foreach (MealPlan::getMealTypeOrder() as $mealType): ?>
                <?php
                $slot   = $dayPlan[$mealType] ?? ['alt1' => null, 'alt2' => null];
                $chosen = null;
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
                <li class="dashboard-meal-list__item <?= $isEaten ? 'is-eaten' : '' ?>">
                    <span class="dashboard-meal-list__type">
                        <?= htmlspecialchars(MealPlan::getMealTypeLabel($mealType)) ?>
                    </span>
                    <span class="dashboard-meal-list__name">
                        <?= $chosen ? htmlspecialchars($chosen['meal_name']) : '—' ?>
                    </span>
                    <?php if ($isEaten): ?>
                        <span class="eaten-mark" title="Snězeno">&#10003;</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="dashboard-actions">
        <a href="/plan/day" class="btn btn-primary">Dnešní plán</a>
        <a href="/plan/week" class="btn btn-secondary">Týdenní přehled</a>
    </div>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
