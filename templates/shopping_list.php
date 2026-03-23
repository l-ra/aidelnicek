<?php

use Aidelnicek\Auth;
use Aidelnicek\Csrf;
use Aidelnicek\MealPlan;
use Aidelnicek\ShoppingList;
use Aidelnicek\Url;

$pageTitle = 'Nákupní seznam';
$user      = Auth::getCurrentUser();
$userId    = (int) $user['id'];

$week   = MealPlan::getOrCreateCurrentWeek();
$weekId = (int) $week['id'];

$allItems    = ShoppingList::getItems($weekId);
$totalCount  = count($allItems);
$boughtCount = count(array_filter($allItems, static fn(array $i): bool => (int) $i['is_purchased'] === 1));
$remaining   = $totalCount - $boughtCount;
$percent     = $totalCount > 0 ? (int) round($boughtCount / $totalCount * 100) : 0;

// Group by category; NULL category → 'Ostatní' displayed last
$grouped = [];
foreach ($allItems as $item) {
    $cat           = (string) ($item['category'] ?: 'Ostatní');
    $grouped[$cat][] = $item;
}
ksort($grouped);
// Move 'Ostatní' to the end if it was sorted earlier
if (isset($grouped['Ostatní'])) {
    $ostatni = $grouped['Ostatní'];
    unset($grouped['Ostatní']);
    $grouped['Ostatní'] = $ostatni;
}

ob_start();
?>
<section class="shopping" id="shopping-section">

    <div class="shopping-header">
        <div class="shopping-header__title">
            <h1>Nákupní seznam</h1>
            <p class="shopping-week-label">Týden <?= (int) $week['week_number'] ?>/<?= (int) $week['year'] ?></p>
        </div>
        <div class="shopping-header__actions">
            <?php if ($totalCount > 0): ?>
                <div class="shopping-export-buttons">
                    <a href="<?= Url::hu('/shopping/export?format=csv') ?>" class="btn btn-secondary btn-sm">Stáhnout CSV</a>
                    <a href="<?= Url::hu('/shopping/export?format=json') ?>" class="btn btn-secondary btn-sm">Stáhnout JSON</a>
                    <button type="button" class="btn btn-secondary btn-sm js-copy-signed-link"
                            data-url-csv="<?= htmlspecialchars($exportSignedUrlCsv ?? '') ?>"
                            data-url-json="<?= htmlspecialchars($exportSignedUrlJson ?? '') ?>"
                            title="Odkaz platný 7 dní, použitelný bez přihlášení">
                        Kopírovat odkaz ke stažení
                    </button>
                </div>
            <?php endif; ?>
            <form method="post" action="<?= Url::hu('/shopping/regenerate') ?>">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-secondary btn-sm">Generovat znovu</button>
            </form>
            <?php if ($boughtCount > 0): ?>
                <form method="post" action="<?= Url::hu('/shopping/clear') ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-secondary btn-sm">Smazat ✓</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($totalCount > 0): ?>
        <div class="shopping-progress">
            <div class="shopping-progress__bar">
                <div class="shopping-progress__fill" id="shopping-progress-fill"
                     style="width: <?= $percent ?>%"></div>
            </div>
            <span class="shopping-progress__label" id="shopping-progress-label">
                <?= $boughtCount ?> / <?= $totalCount ?> nakoupeno
            </span>
        </div>
    <?php endif; ?>

    <div class="shopping-filter" role="tablist" aria-label="Filtr položek">
        <button class="shopping-filter__tab is-active" data-filter="all"
                role="tab" aria-selected="true">
            Vše&nbsp;(<span id="count-all"><?= $totalCount ?></span>)
        </button>
        <button class="shopping-filter__tab" data-filter="remaining"
                role="tab" aria-selected="false">
            Zbývá&nbsp;(<span id="count-remaining"><?= $remaining ?></span>)
        </button>
        <button class="shopping-filter__tab" data-filter="purchased"
                role="tab" aria-selected="false">
            Nakoupeno&nbsp;(<span id="count-purchased"><?= $boughtCount ?></span>)
        </button>
    </div>

    <?php if (empty($allItems)): ?>
        <div class="shopping-empty">
            <p>Jídelníček pro tento týden nebyl zatím vygenerován. Nejdříve si prohlédni svůj
               <a href="<?= Url::hu('/plan') ?>">jídelníček</a> a pak klikni na <strong>Generovat znovu</strong>.</p>
        </div>
    <?php else: ?>
        <div id="shopping-items-container" data-week-id="<?= $weekId ?>">
            <?php foreach ($grouped as $category => $categoryItems): ?>
                <div class="shopping-category-group">
                    <p class="shopping-category"><?= htmlspecialchars($category) ?></p>
                    <ul class="shopping-list">
                        <?php foreach ($categoryItems as $item): ?>
                            <?php
                            $isPurchased = (int) $item['is_purchased'] === 1;
                            $qtyRaw      = $item['quantity'];
                            $qtyDisplay  = '';
                            if ($qtyRaw !== null) {
                                $qtyNum     = (float) $qtyRaw;
                                $qtyDisplay = ($qtyNum == (int) $qtyNum)
                                    ? (string) (int) $qtyNum
                                    : (string) $qtyNum;
                                $qtyDisplay .= ' ' . ($item['unit'] ?: 'ks');
                            }
                            ?>
                            <li class="shopping-item<?= $isPurchased ? ' is-purchased' : '' ?>"
                                data-item-id="<?= (int) $item['id'] ?>">
                                <button class="shopping-item__check"
                                        data-item-id="<?= (int) $item['id'] ?>"
                                        aria-label="<?= $isPurchased ? 'Odznačit' : 'Označit jako nakoupeno' ?>"
                                        aria-pressed="<?= $isPurchased ? 'true' : 'false' ?>"
                                        type="button">
                                    <svg class="check-icon" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="3"
                                         stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                </button>
                                <span class="shopping-item__name">
                                    <?= htmlspecialchars($item['name']) ?>
                                </span>
                                <?php if ($qtyDisplay !== ''): ?>
                                    <span class="shopping-item__qty"><?= htmlspecialchars($qtyDisplay) ?></span>
                                <?php endif; ?>
                                <button class="shopping-item__remove"
                                        data-item-id="<?= (int) $item['id'] ?>"
                                        aria-label="Odebrat položku"
                                        type="button">×</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="shopping-add-form">
        <h2>Přidat položku</h2>
        <form method="post" action="<?= Url::hu('/shopping/add') ?>" class="shopping-add-form__inner">
            <?= Csrf::field() ?>
            <input type="text" name="name" placeholder="Název" required
                   class="shopping-add-input" autocomplete="off">
            <input type="number" name="quantity" placeholder="Množství"
                   min="0.1" step="0.1" class="shopping-add-qty">
            <input type="text" name="unit" placeholder="Jednotka"
                   list="unit-suggestions" class="shopping-add-unit">
            <datalist id="unit-suggestions">
                <option value="ks">
                <option value="g">
                <option value="kg">
                <option value="ml">
                <option value="l">
                <option value="lžíce">
                <option value="lžička">
                <option value="hrnek">
            </datalist>
            <input type="text" name="category" placeholder="Kategorie"
                   list="category-suggestions" class="shopping-add-cat">
            <datalist id="category-suggestions">
                <option value="Mléčné výrobky">
                <option value="Maso a ryby">
                <option value="Zelenina">
                <option value="Ovoce">
                <option value="Pečivo">
                <option value="Luštěniny">
                <option value="Obiloviny a těstoviny">
                <option value="Tuky a oleje">
                <option value="Koření a dochucovadla">
                <option value="Nápoje">
                <option value="Ostatní">
            </datalist>
            <button type="submit" class="btn btn-primary btn-sm">+ Přidat</button>
        </form>
    </div>

</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
