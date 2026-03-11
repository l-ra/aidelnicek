# M4 — Nákupní seznam: Implementační plán

## Kontext

- **M1–M3 hotové**: databázové schéma (vč. tabulky `shopping_list_items`), autentizace,
  profily, jídelníček (denní/týdenní pohled, výběr alternativ, sledování snězeno).
- **M5 (AI generátor) ještě neexistuje** → M4 generuje nákupní seznam z demo dat jídelníčků;
  ingredience jsou uloženy jako JSON pole prostých názvů (bez množství/jednotek).
  Po příchodu M5 metoda `generateFromMealPlans` bude pracovat se strukturovanými daty beze změny rozhraní.
- Tabulka `shopping_list_items` již existuje v DB migraci — žádná schémová změna není potřeba.
- Stack: PHP 8.2, SQLite, vanilla CSS/JS, bez frameworku.

---

## Přehled souborů

| Akce     | Soubor                            |
|----------|-----------------------------------|
| Vytvořit | `src/ShoppingList.php`            |
| Vytvořit | `templates/shopping_list.php`     |
| Upravit  | `public/index.php`                |
| Upravit  | `templates/layout.php`            |
| Upravit  | `templates/dashboard.php`         |
| Upravit  | `public/css/style.css`            |
| Upravit  | `public/js/app.js`                |

---

## 1. `src/ShoppingList.php`

Statická třída s těmito metodami:

```php
// Vrátí položky pro daný týden seřazené dle category, name
// $purchased: null = vše, true = jen nakoupené, false = jen nenakoupené
public static function getItems(int $weekId, ?bool $purchased = null): array

// Automaticky vygeneruje položky ze zvolených jídelníčků všech uživatelů pro daný týden.
// Idempotentní: přeskočí, pokud pro daný week_id již existují záznamy s added_manually=0.
// Při opakovaném zavolání s $force=true smaže staré auto-generované záznamy a generuje znovu.
public static function generateFromMealPlans(int $weekId, bool $force = false): void

// Přepne is_purchased pro danou položku; při označení nastaví purchased_by=userId, při odznačení NULL.
// Vrátí false, pokud itemId danému weekId nepatří (bezpečnostní kontrola).
public static function togglePurchased(int $userId, int $itemId): bool

// Ručně přidá novou položku; vrátí ID nově vloženého řádku.
public static function addItem(
    int $userId,
    int $weekId,
    string $name,
    ?float $quantity,
    ?string $unit,
    ?string $category
): int

// Smaže položku. Povoleno pouze pro: (a) položky, které daný user přidal, nebo (b) adminy.
// Vrátí false, pokud položka neexistuje nebo uživatel nemá oprávnění.
public static function removeItem(int $userId, int $itemId): bool

// Smaže všechny nakoupené položky pro daný týden. Vrátí počet smazaných řádků.
public static function clearPurchased(int $weekId): int

// Pomocná: agreguje ingredience z meal_plans řádků do pole pro hromadný INSERT.
// Vstup: pole řádků z meal_plans (každý má klíč 'ingredients' = JSON string).
// Výstup: pole ['name' => string, 'quantity' => float|null, 'unit' => null, 'category' => null]
// Deduplikuje dle lowercase(name); pokud se ingredient opakuje, quantity++ (počet porcí).
private static function aggregateIngredients(array $mealPlanRows): array
```

### Klíčové chování `generateFromMealPlans`

1. Načte všechny řádky z `meal_plans` pro daný `week_id` s `is_chosen=1`.
   Pokud uživatel žádnou alternativu nezvolil (žádný `is_chosen=1` pro daný slot),
   bere se jako default `alternative=1`.
2. Zavolá `aggregateIngredients()` — rozloží JSON ingredience, deduplikuje case-insensitive,
   u opakujících se ingrediencí navyšuje `quantity` (počet výskytů).
3. Hromadně vloží do `shopping_list_items` s `added_manually=0`, `added_by=NULL`.
4. Idempotence: na začátku zkontroluje `SELECT COUNT(*) WHERE week_id=? AND added_manually=0`.
   Pokud `> 0` a `$force=false`, funkce se vrátí bez akce.
   Pokud `$force=true`, nejprve smaže staré auto-generované záznamy (`added_manually=0`),
   pak vloží nové.

### Klíčové chování `removeItem`

```php
$item = SELECT * FROM shopping_list_items WHERE id = ?
if ($item === false)           → return false
if ($item['added_by'] !== $userId && !User::isAdmin($userId)) → return false
DELETE FROM shopping_list_items WHERE id = ?
return true
```

---

## 2. Nové routy v `public/index.php`

```
GET  /shopping          → templates/shopping_list.php
                          auto-generate pokud pro aktuální týden neexistují žádné auto-gen položky
POST /shopping/toggle   → ShoppingList::togglePurchased()
                          přijme: item_id, redirect_to
                          vrátí: JSON {"ok":true,"is_purchased":bool} při AJAX, nebo redirect
POST /shopping/add      → ShoppingList::addItem()
                          přijme: name, quantity (nepovinné), unit (nepovinné), category (nepovinné)
                          vrátí: JSON {"ok":true,"id":int} při AJAX, nebo redirect
POST /shopping/remove   → ShoppingList::removeItem()
                          přijme: item_id, redirect_to
                          vrátí: JSON {"ok":true} při AJAX, nebo redirect
POST /shopping/clear    → ShoppingList::clearPurchased()
                          přijme: (jen CSRF token)
                          vrátí: redirect na /shopping
POST /shopping/regenerate → ShoppingList::generateFromMealPlans($weekId, force: true)
                            přijme: (jen CSRF token)
                            vrátí: redirect na /shopping
```

CSRF validace na všech POST routách (stejný vzor jako stávající routy).
AJAX detekce: hlavička `X-Requested-With: XMLHttpRequest`.

---

## 3. `templates/shopping_list.php`

```
┌─────────────────────────────────────────────────────────────┐
│  Nákupní seznam — Týden 11/2026                             │
│  12 / 27 nakoupeno  [░░░░░░░░░░░░░░░░░░░░░░░░░░░] 44 %    │
│                              [Generovat znovu] [Smazat ✓]   │
├─────────────────────────────────────────────────────────────┤
│  [Vše (27)]  [Zbývá (15)]  [Nakoupeno (12)]                 │
├─────────────────────────────────────────────────────────────┤
│  ── Mléčné výrobky ──────────────────────────────────────── │
│  [✓] Řecký jogurt  · 3 ks                          [×]      │
│  [ ] Máslo         · 5 ks                          [×]      │
│  [ ] Tvaroh        · 4 ks                          [×]      │
│                                                             │
│  ── Ostatní ─────────────────────────────────────────────── │
│  [✓] Vejce         · 8 ks                          [×]      │
│  [ ] Ovesné vločky · 2 ks                          [×]      │
│  ...                                                        │
├─────────────────────────────────────────────────────────────┤
│  Přidat položku                                             │
│  [Název___________] [Množství] [Jednotka] [Kategorie]       │
│                                               [+ Přidat]   │
└─────────────────────────────────────────────────────────────┘
```

**Detaily:**

- Záhlaví zobrazuje číslo aktuálního týdne a rok.
- Progress bar ukazuje poměr nakoupených / celkem (CSS `width` inline styl).
- Tlačítko **„Generovat znovu"** odešle POST na `/shopping/regenerate`
  (zobrazit jen pokud seznam není prázdný, nebo vždy).
- Tlačítko **„Smazat ✓"** odešle POST na `/shopping/clear` — odstraní nakoupené položky
  (zobrazit jen pokud existuje alespoň jedna nakoupená položka).
- Filtrační tabs: **Vše / Zbývá / Nakoupeno** — přepínají se přes JS bez reload stránky.
- Položky jsou seskupeny dle `category` (seřazeno abecedně; `NULL` kategorie = „Ostatní").
- Klik na checkbox → AJAX POST `/shopping/toggle` → přepne třídu `is-purchased`.
- Klik na [×] → AJAX POST `/shopping/remove` → odstraní element ze stránky.
- Formulář „Přidat položku" — standardní HTML form POST (ne AJAX), redirect zpět na `/shopping`.
  Pole `quantity` a `unit` jsou nepovinná. Pole `category` je `<select>` s přednastavenými
  volbami + možnost volného textu (nebo prázdné = Ostatní).
- Pokud seznam nemá žádné položky, zobrazí se prázdný stav s výzvou:
  _„Jídelníček pro tento týden nebyl zatím vygenerován. Nejdříve si prohlédni svůj
  [jídelníček](/plan) a pak klikni na Generovat seznam."_

---

## 4. Aktualizace `templates/dashboard.php`

Nahrazení placeholderu nákupního seznamu widgetem:

```
┌────────────────────────────────┐
│  🛒 Nákupní seznam             │
│  15 zbývá nakoupit             │
│           [Otevřít seznam →]   │
└────────────────────────────────┘
```

- Načte `ShoppingList::getItems($weekId, purchased: false)` a zobrazí počet zbývajících položek.
- Pokud `$weekId` pro aktuální týden ještě neexistuje, zobrazí: _„Zatím žádné položky."_

---

## 5. Aktualizace `templates/layout.php`

- Přidání odkazu **„Nákupní seznam"** do navigace (`/shopping`).

---

## 6. CSS přídavky (`public/css/style.css`)

Nové bloky:

- `.shopping-header` — flex kontejner záhlaví (nadpis + akční tlačítka)
- `.shopping-progress` — wrapper progress baru
- `.shopping-progress__bar` — šedý container (100 % šířky, výška 8px, zaoblené rohy)
- `.shopping-progress__fill` — zelená výplň (inline `width: XX%`)
- `.shopping-progress__label` — text „X / Y nakoupeno"
- `.shopping-filter` — kontejner filtrovacích tabů
- `.shopping-filter__tab` — neaktivní tab (border-bottom: 2px solid transparent)
- `.shopping-filter__tab.is-active` — aktivní tab (border-bottom: 2px solid zelená)
- `.shopping-category` — nadpis kategorie (`<h3>` nebo `<p>`, šedá barva, font-size: 0.85rem, uppercase)
- `.shopping-list` — `<ul>` bez odrážek, margin: 0, padding: 0
- `.shopping-item` — `<li>` s flexbox layout (zarovnání checkbox + text + tlačítko)
- `.shopping-item.is-purchased .shopping-item__name` — `text-decoration: line-through; color: #999`
- `.shopping-item__check` — velký checkbox (24×24 px, vlastní SVG tick)
- `.shopping-item__name` — název položky (flex: 1)
- `.shopping-item__qty` — množství + jednotka (šedě, font-size: 0.85rem)
- `.shopping-item__remove` — tlačítko [×] (červená, průhledné pozadí, zobrazí se jen na hover řádku)
- `.shopping-add-form` — flex formulář (oddělený border-top od seznamu)
- `.shopping-add-form input, .shopping-add-form select` — kompaktní výška 36px
- `.shopping-empty` — stav prázdného seznamu (odsazení, šedý text)

---

## 7. JS přídavky (`public/js/app.js`)

```javascript
// Klik na checkbox položky → POST /shopping/toggle → přepne třídy is-purchased na .shopping-item
function initShoppingToggle() { ... }

// Klik na [×] → POST /shopping/remove → odstraní .shopping-item ze stránky;
// aktualizuje počítadlo v záhlaví a progress bar
function initShoppingRemove() { ... }

// Klik na filtrační tab → skryje/zobrazí .shopping-item dle třídy is-purchased;
// aktualizuje třídu is-active na tabech
function initShoppingFilter() { ... }
```

Všechny tři funkce používají `fetch()` s CSRF tokenem z `<meta name="csrf-token">`.
Při chybě sítě zobrazí chybovou hlášku (stejný vzor jako M3).

Pomocná funkce `updateShoppingProgress()` — přepočítá a aktualizuje progress bar a label
po každé togglePurchased nebo remove akci.

---

## Souhrn nových endpointů

| Metoda | URL                    | Popis                                       |
|--------|------------------------|---------------------------------------------|
| GET    | `/shopping`            | Zobrazení nákupního seznamu (aktuální týden) |
| POST   | `/shopping/toggle`     | Přepnutí stavu nakoupeno (CSRF)             |
| POST   | `/shopping/add`        | Ruční přidání položky (CSRF)                |
| POST   | `/shopping/remove`     | Odebrání položky (CSRF)                     |
| POST   | `/shopping/clear`      | Smazání všech nakoupených položek (CSRF)    |
| POST   | `/shopping/regenerate` | Přegenerování ze jídelníčků (CSRF)          |

---

## Poznámky k budoucí kompatibilitě (M5)

- `generateFromMealPlans` je navržena tak, aby při příchodu M5 fungovala beze změny
  veřejného rozhraní: stačí rozšířit `aggregateIngredients` o parsování strukturovaných
  ingrediencí (s `quantity` a `unit`), až AI generátor začne tato data produkovat.
- Tabulka `shopping_list_items` již obsahuje sloupce `quantity`, `unit`, `category`
  pro budoucí strukturovaná data — M4 je plní, pokud jsou k dispozici.
