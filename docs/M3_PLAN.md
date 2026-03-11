# M3 — Jídelníček: Implementační plán

## Kontext

- **M1 a M2 hotové**: databázové schéma (vč. tabulek `meal_plans`, `meal_history`, `weeks`),
  autentizace, profily uživatelů.
- **M5 (AI generátor) ještě neexistuje** → M3 zavádí automatické seedování demo dat,
  která simulují výstup budoucího generátoru.
- Stack: PHP 8.2, SQLite, vanilla CSS/JS, bez frameworku.

---

## Přehled souborů

| Akce     | Soubor                       |
|----------|------------------------------|
| Vytvořit | `src/MealPlan.php`           |
| Vytvořit | `src/MealHistory.php`        |
| Vytvořit | `templates/day_plan.php`     |
| Vytvořit | `templates/week_plan.php`    |
| Upravit  | `public/index.php`           |
| Upravit  | `templates/layout.php`       |
| Upravit  | `templates/dashboard.php`    |
| Upravit  | `public/css/style.css`       |
| Upravit  | `public/js/app.js`           |

---

## 1. `src/MealPlan.php`

Statická třída s těmito metodami:

```php
// Vrátí (a případně vytvoří) záznam pro aktuální ISO týden
public static function getOrCreateCurrentWeek(): array  // ['id', 'week_number', 'year']

// Jídelní plán pro jeden den — vrátí strukturu indexovanou dle meal_type,
// každý typ má 'alt1' a 'alt2' (pole s daty řádku z meal_plans)
public static function getDayPlan(int $userId, int $weekId, int $dayOfWeek): array

// Celý týdenní plán — pole [1..7 => getDayPlan výsledek]
public static function getWeekPlan(int $userId, int $weekId): array

// Nastaví is_chosen=1 pro daný plan_id, zároveň is_chosen=0 pro druhou alternativu
// téhož (week, day, meal_type) — atomická transakce
public static function chooseAlternative(int $userId, int $planId): bool

// Přepne is_eaten pro daný plan_id (0→1 nebo 1→0)
public static function toggleEaten(int $userId, int $planId): bool

// Vloží demo data pro daného uživatele+týden, pokud ještě neexistují
// (7 dní × 5 typů × 2 alternativy = 70 řádků, česká jídla)
public static function seedDemoWeek(int $userId, int $weekId): void

// Pomocné: překlad kódů na české popisky
public static function getMealTypeLabel(string $type): string  // 'breakfast' → 'Snídaně'
public static function getDayLabel(int $day): string           // 1 → 'Pondělí'
public static function getMealTypeOrder(): array               // ['breakfast','snack_am',...]
```

### Klíčové chování

- `chooseAlternative`: v jedné transakci nastaví `is_chosen=0` pro obě alternativy
  daného slotu, pak `is_chosen=1` pro vybranou.
- `seedDemoWeek`: idempotentní — zkontroluje existenci řádků před vložením,
  zavolá také `MealHistory::recordOffer()` pro každé jídlo.

---

## 2. `src/MealHistory.php`

Statická třída; upsert do tabulky `meal_history`:

```php
// Zaznamená, že jídlo bylo nabídnuto (times_offered++)
public static function recordOffer(int $userId, string $mealName): void

// Zaznamená výběr alternativy (times_chosen++)
public static function recordChoice(int $userId, string $mealName): void

// Zaznamená snězení (times_eaten++)
public static function recordEaten(int $userId, string $mealName): void
```

Všechny tři metody provádějí `INSERT ... ON CONFLICT(user_id, meal_name) DO UPDATE`.

---

## 3. Nové routy v `public/index.php`

```
GET  /plan           → přesměruje na /plan/day (aktuální den)
GET  /plan/day       → templates/day_plan.php
                       query params: ?day=1-7  (výchozí: dnešní den v týdnu)
                       automaticky seed demo dat, pokud týden nemá žádná data pro uživatele
GET  /plan/week      → templates/week_plan.php
                       automaticky seed demo dat (stejná logika)
POST /plan/choose    → MealPlan::chooseAlternative() + MealHistory::recordChoice()
                       přijme: plan_id, redirect_to
                       vrátí: JSON {"ok":true} při AJAX, nebo redirect při form POST
POST /plan/eaten     → MealPlan::toggleEaten() + MealHistory::recordEaten() (při 0→1)
                       přijme: plan_id, redirect_to
                       vrátí: JSON {"ok":true} při AJAX, nebo redirect
```

CSRF validace na obou POST routách (stejný vzor jako existující routy).
AJAX detekce: hlavička `X-Requested-With: XMLHttpRequest`.
`layout.php` dostane `<meta name="csrf-token">` pro JS.

---

## 4. `templates/day_plan.php`

```
[navigace dne]  ← Pondělí 9.3. | Úterý 10.3. (aktivní) | Středa 11.3. →
                                    [Týdenní přehled ↗]

[5 karet — jedna pro každé jídlo]
  ┌─────────────────────────────────────────┐
  │ Snídaně                                  │
  │  ○ Ovesná kaše s ovocem  ○ Jogurt s granolou │
  │    Popis... Ingredience    Popis...           │
  │  [☐ Snězeno]  (pouze pro zvolenou alt.)       │
  └─────────────────────────────────────────┘
```

- Alternativy jsou vizuálně jako radio buttony (custom CSS).
- Kliknutí na alternativu okamžitě POSTuje přes Fetch API.
- "Snězeno" checkbox POSTuje přes Fetch API.
- Vizuální stav: zvolená = zelená outline, snězeno = zaškrtnuto + přeškrtnutý text.

---

## 5. `templates/week_plan.php`

```
Týden 11/2026  [← Předchozí]  [Další →]

         Po    Út    St    Čt    Pá    So    Ne
Snídaně  ...   ...   ...   ...   ...   ...   ...
Svačina  ...   ...   ...   ...   ...   ...   ...
Oběd     ...   ...   ...   ...   ...   ...   ...
Svačina  ...   ...   ...   ...   ...   ...   ...
Večeře   ...   ...   ...   ...   ...   ...   ...
```

- Záhlaví sloupců = klikatelné odkazy na `/plan/day?day=N`.
- Dnešní den zvýrazněn.
- V buňce: název zvolené alternativy (nebo "—"), ikona ✓ pokud snězeno.
- Navigace přes `?week=N&year=Y`.

---

## 6. Aktualizace `templates/dashboard.php`

Nahrazení placeholderu:
1. **Dnešní jídelníček** — 5 řádků s názvem zvoleného/výchozího jídla + stav snězeno.
2. **Akční tlačítka**: "Dnešní plán" → `/plan/day`, "Týdenní přehled" → `/plan/week`.

---

## 7. Aktualizace `templates/layout.php`

- Přidání odkazu "Jídelníček" do navigace.
- Přidání `<meta name="csrf-token" content="...">` pro AJAX.

---

## 8. CSS přídavky (`public/css/style.css`)

Nové bloky:
- `.plan-nav` — navigační lišta s dny/šipkami
- `.meal-cards` — flex/grid kontejner karet
- `.meal-card` — bílá karta se stínem
- `.meal-card__header` — název jídla (Snídaně / Oběd…)
- `.meal-alternatives` — dva sloupce side-by-side na desktop, pod sebou na mobilu
- `.alt-option` — klikatelná alternativa s border
- `.alt-option.is-chosen` — zelená outline + světlé zelené pozadí
- `.alt-option.is-eaten .alt-name` — přeškrtnuto
- `.eaten-checkbox` — stylovaný checkbox "Snězeno"
- `.week-table` — `table-layout: fixed`, kompaktní text
- `.week-table th.today, .week-table td.today` — zelené zvýraznění

---

## 9. JS přídavky (`public/js/app.js`)

```javascript
// Kliknutí na alternativu → POST /plan/choose → aktualizuje třídy is-chosen
function initAlternativePicker() { ... }

// Kliknutí na "Snězeno" checkbox → POST /plan/eaten → aktualizuje třídy is-eaten
function initEatenCheckbox() { ... }
```

Obě funkce používají `fetch()` s CSRF tokenem z `<meta name="csrf-token">`.
Při chybě sítě zobrazí chybovou hlášku.

---

## Souhrn nových endpointů

| Metoda | URL                      | Popis                          |
|--------|--------------------------|--------------------------------|
| GET    | `/plan`                  | redirect na `/plan/day`        |
| GET    | `/plan/day?day=N`        | denní pohled                   |
| GET    | `/plan/week?week=N&year=Y` | týdenní přehled              |
| POST   | `/plan/choose`           | výběr alternativy (CSRF)       |
| POST   | `/plan/eaten`            | přepnutí "snězeno" (CSRF)      |

---

## Demo data

`MealPlan::seedDemoWeek()` vloží 70 řádků (7 dní × 5 typů × 2 alternativy)
s reálnými českými jídly, pokud pro daný user+week ještě nic neexistuje.
Seeding nastane automaticky při prvním přístupu na `/plan/day` nebo `/plan/week`.
