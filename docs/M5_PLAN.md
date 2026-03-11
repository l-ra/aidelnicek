# M5 — AI generátor jídelníčků: Implementační plán

## Kontext

- **M1–M4 hotové**: databázové schéma, autentizace, profily, jídelníček (denní/týdenní pohled,
  výběr alternativ, sledování snězeno), nákupní seznam.
- **Tabulka `meal_history`** sbírá statistiky preferencí (`times_offered`, `times_chosen`,
  `times_eaten`) — tato data vstupují jako kontext do LLM promptu.
- **`MealPlan::seedDemoWeek()`** zůstává jako záložní generátor (fallback) při selhání LLM.
- Stack: PHP 8.2, SQLite, bez frameworku. LLM komunikace přes OpenAI REST API (PHP curl).

---

## Přehled souborů

| Akce     | Soubor                                         |
|----------|------------------------------------------------|
| Vytvořit | `src/Llm/LlmInterface.php`                     |
| Vytvořit | `src/Llm/OpenAiProvider.php`                   |
| Vytvořit | `src/Llm/LlmLogger.php`                        |
| Vytvořit | `src/Llm/LlmFactory.php`                       |
| Vytvořit | `src/MealGenerator.php`                        |
| Vytvořit | `prompts/system.txt`                           |
| Vytvořit | `prompts/meal_plan_generate.txt`               |
| Vytvořit | `prompts/meal_plan_history.txt`                |
| Vytvořit | `prompts/README.md`                            |
| Upravit  | `src/MealPlan.php`                             |
| Upravit  | `src/ShoppingList.php`                         |
| Upravit  | `cron/generate_weekly.php`                     |
| Upravit  | `Dockerfile`                                   |
| Upravit  | `public/index.php`                             |
| Upravit  | `templates/week_plan.php`                      |
| Upravit  | `templates/day_plan.php`                       |
| Upravit  | `public/css/style.css`                         |
| Upravit  | `helm/aidelnicek/templates/deployment.yaml`    |
| Upravit  | `.github/workflows/deploy.yml`                 |
| Upravit  | `.github/workflows/release.yml`                |
| Upravit  | `docs/GITHUB_SECRETS.md`                       |

---

## 1. Architektura LLM vrstvy

```
LlmFactory::create()
    └─> OpenAiProvider (implements LlmInterface)
            ├─ Auth: OPENAI_AUTH_BEARER → Authorization: Bearer <token>
            │        (hodnota může být API klíč nebo OAuth access token — viz dokumentaci)
            ├─ Model: $OPENAI_MODEL (výchozí: gpt-4o)
            ├─ Base URL: $OPENAI_BASE_URL (výchozí: https://api.openai.com/v1)
            └─ Loguje každé volání přes LlmLogger

LlmLogger
    └─> data/llm_YYYY-MM-DD.db   (SQLite, automatická rotace po dnech)
            └─ tabulka: llm_log

MealGenerator
    ├─> User::findById()               — profil uživatele
    ├─> MealGenerator::getPreferences() — oblíbená/odmítaná jídla z meal_history
    ├─> načte prompts/system.txt + prompts/meal_plan_generate.txt
    │   (+ volitelně prompts/meal_plan_history.txt jako {HISTORY_BLOCK})
    ├─> LlmFactory::create()->complete(system, user, options)
    │   └─ OpenAiProvider zaloguje volání do data/llm_YYYY-MM-DD.db
    ├─> parseResponse() — validuje JSON, 2 pokusy při parse chybě
    ├─> seedFromLlm() → INSERT do meal_plans + MealHistory::recordOffer()
    └─> při selhání: MealPlan::seedDemoWeek() jako fallback
```

---

## 2. `src/Llm/LlmInterface.php`

Namespace `Aidelnicek\Llm`. PSR-4 autoloading to pokryje díky mapování `Aidelnicek\` → `src/`.

```php
namespace Aidelnicek\Llm;

interface LlmInterface
{
    /**
     * Odešle prompt modelu a vrátí textovou odpověď.
     *
     * @param array $options  Volitelné: 'temperature' (float), 'max_tokens' (int), 'user_id' (int)
     * @throws \RuntimeException při HTTP chybě, timeoutu nebo neplatném tokenu
     */
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): string;

    public function getName(): string;
    public function getModel(): string;
}
```

---

## 3. `src/Llm/OpenAiProvider.php`

Namespace `Aidelnicek\Llm`.

```php
class OpenAiProvider implements LlmInterface
{
    private string $bearerToken;  // načten z OPENAI_AUTH_BEARER
    private string $model;        // např. 'gpt-4o'
    private string $baseUrl;      // výchozí 'https://api.openai.com/v1'
    private LlmLogger $logger;

    public function __construct()
    {
        $this->bearerToken = getenv('OPENAI_AUTH_BEARER') ?: '';
        $this->model       = getenv('OPENAI_MODEL')       ?: 'gpt-4o';
        $this->baseUrl     = rtrim(getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1', '/');
        $this->logger      = new LlmLogger();

        if (empty($this->bearerToken)) {
            throw new \RuntimeException(
                'OpenAI bearer token není nastaven. Nastavte env proměnnou OPENAI_AUTH_BEARER.'
            );
        }
    }
    // complete(), getName(), getModel(), callApi() — viz implementace
}
```

### Autentizace — jediná env proměnná

Kód používá **výhradně** proměnnou `OPENAI_AUTH_BEARER`. Hodnota se posílá v HTTP hlavičce
`Authorization: Bearer <hodnota>`. Způsob vytvoření tokenu je záležitostí deploymentu:

| Způsob autentizace | Hodnota `OPENAI_AUTH_BEARER` |
|--------------------|------------------------------|
| **API klíč** (nejčastější) | API klíč z OpenAI dashboardu (začíná `sk-...`) |
| **OAuth access token** | OAuth token vydaný poskytovatelem identity (SSO, enterprise) |

Kód nerozlišuje typ tokenu — HTTP hlavička je v obou případech identická, OpenAI API je
zpracovává serverově. Pro přepnutí způsobu autentizace stačí změnit hodnotu v GitHub Secret.

---

## 4. `src/Llm/LlmLogger.php`

Namespace `Aidelnicek\Llm`.

```php
class LlmLogger
{
    private function getDbPath(): string
    {
        // data/llm_YYYY-MM-DD.db  (data/ = PVC mount)
        return dirname(__DIR__, 2) . '/data/llm_' . date('Y-m-d') . '.db';
    }

    private function getConnection(): \PDO { ... }  // lazy init + initSchema() pro nový soubor

    private function initSchema(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS llm_log (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            provider       TEXT    NOT NULL,
            model          TEXT    NOT NULL,
            user_id        INTEGER,
            prompt_system  TEXT,
            prompt_user    TEXT    NOT NULL,
            response_text  TEXT,
            tokens_in      INTEGER,
            tokens_out     INTEGER,
            request_at     TEXT    NOT NULL,
            duration_ms    INTEGER,
            status         TEXT    NOT NULL DEFAULT 'ok',
            error_message  TEXT
        )");
    }

    public function log(array $data): void { ... }  // INSERT, chyby tiše loguje přes error_log()
}
```

### Schéma tabulky `llm_log`

| Sloupec         | Popis                                                       |
|-----------------|-------------------------------------------------------------|
| `provider`      | Název poskytovatele, např. `openai`                         |
| `model`         | Název modelu, např. `gpt-4o`                                |
| `user_id`       | ID uživatele (NULL = systémové volání z cronu)              |
| `prompt_system` | Plný text systémového promptu                               |
| `prompt_user`   | Plný text uživatelského promptu                             |
| `response_text` | Plný text odpovědi modelu (NULL při chybě)                  |
| `tokens_in`     | Počet vstupních tokenů (z API odpovědi)                     |
| `tokens_out`    | Počet výstupních tokenů (z API odpovědi)                    |
| `request_at`    | Čas odeslání požadavku (`YYYY-MM-DD HH:MM:SS`)              |
| `duration_ms`   | Doba trvání volání v milisekundách                          |
| `status`        | `ok` nebo `error`                                           |
| `error_message` | Chybová zpráva (NULL pokud `status=ok`)                     |

### Rotace logů

- Každý nový den vznikne nový soubor `data/llm_YYYY-MM-DD.db` automaticky.
- Stará log DB se neodstraňuje — správce archivuje nebo maže dle politiky.
- Složka `data/` je persistentně uložena na PVC (`/var/www/html/data`).
- Při vysokém počtu volání zvažte navýšení `persistence.size` v `helm/aidelnicek/values.yaml`.

---

## 5. `src/Llm/LlmFactory.php`

Namespace `Aidelnicek\Llm`.

```php
class LlmFactory
{
    /**
     * Přidání nového providera:
     *   1. src/Llm/NovyProvider.php implementující LlmInterface
     *   2. Přidat case do match níže
     *   3. Nastavit LLM_PROVIDER=novy_provider v K8s Secret
     */
    public static function create(): LlmInterface
    {
        $provider = getenv('LLM_PROVIDER') ?: 'openai';
        return match ($provider) {
            'openai' => new OpenAiProvider(),
            default  => throw new \InvalidArgumentException("Neznámý LLM provider: {$provider}"),
        };
    }
}
```

---

## 6. Šablony promptů (`prompts/`)

Prosté textové soubory s proměnnými ve formátu `{NAZEV}`.
`MealGenerator` je načítá přes `file_get_contents()` a nahrazuje hodnoty pomocí `strtr()`.
Editace šablon nevyžaduje změnu PHP kódu.

---

### `prompts/system.txt`

**Účel:** Definuje roli asistenta a závazná pravidla odpovědi (JSON only).
Posílá se jako `role: system` při každém volání API.

```
Jsi odborný nutriční poradce specializující se na sestavování týdenních jídelníčků
pro české domácnosti. Tvé jídelníčky jsou:
- Nutričně vyvážené (bílkoviny, sacharidy, zdravé tuky v doporučených poměrech)
- Sestavené z dostupných českých ingrediencí s konkrétními množstvími a jednotkami
- Přizpůsobené profilu uživatele (pohlaví, věk, typ postavy, stravovací omezení)
- Pestré — každý den jiná jídla; obě alternativy téhož chodu se navzájem liší

Odpovídáš VÝHRADNĚ platným JSON objektem podle předepsaného schématu.
Nepiš žádný text před ani za JSON. Nepoužívej markdown kódové bloky.
```

---

### `prompts/meal_plan_generate.txt`

**Účel:** Hlavní uživatelský prompt pro generování 7denního jídelníčku.
Obsahuje profil uživatele, volitelný blok historie a definici výstupního JSON formátu.

Proměnné: `{USER_NAME}`, `{GENDER}`, `{AGE}`, `{BODY_TYPE}`, `{DIETARY_NOTES}`,
`{WEEK_NUMBER}`, `{YEAR}`, `{HISTORY_BLOCK}`, `{JSON_SCHEMA}`.

---

### `prompts/meal_plan_history.txt`

**Účel:** Volitelný blok vkládaný do `{HISTORY_BLOCK}` v `meal_plan_generate.txt`.
Aktivuje se pouze pokud uživatel má ≥ 5 záznamů v `meal_history`.

Proměnné: `{LIKED_MEALS}`, `{DISLIKED_MEALS}`.

---

### `prompts/README.md`

Dokumentace všech prompt souborů — viz implementovaný soubor.

---

## 7. `src/MealGenerator.php`

Namespace `Aidelnicek`.

```php
class MealGenerator
{
    /**
     * @param bool $force  true = smaže existující plány uživatele+týden a přegeneruje
     * @return bool         true = LLM úspěch, false = fallback na demo data
     */
    public static function generateWeek(int $userId, int $weekId, bool $force = false): bool

    private static function buildPrompts(array $user, int $weekNumber, int $year): array
    // @return array{0: string, 1: string}  [systemPrompt, userPrompt]

    private static function parseResponse(string $response): array
    // @throws \InvalidArgumentException při neplatném JSON nebo chybějící struktuře

    private static function seedFromLlm(int $userId, int $weekId, array $days): void
    // INSERT OR IGNORE do meal_plans + MealHistory::recordOffer() pro každé jídlo

    private static function getPreferences(int $userId): array
    // @return array{liked: string[], disliked: string[]}
    // Liked:    times_eaten/times_offered >= 0.6 AND times_offered >= 3
    // Disliked: times_eaten/times_offered <= 0.2 AND times_offered >= 3

    private static function getJsonSchema(): string
    // Vrátí inline JSON schéma pro dosazení do {JSON_SCHEMA}
}
```

### Očekávaný JSON formát odpovědi LLM

```json
{
  "days": [
    {
      "day": 1,
      "meals": {
        "breakfast":  { "alt1": { "name": "...", "description": "...", "ingredients": [{"name":"...","quantity":80,"unit":"g"}] }, "alt2": {...} },
        "snack_am":   { "alt1": {...}, "alt2": {...} },
        "lunch":      { "alt1": {...}, "alt2": {...} },
        "snack_pm":   { "alt1": {...}, "alt2": {...} },
        "dinner":     { "alt1": {...}, "alt2": {...} }
      }
    }
  ]
}
```

Ingredience jsou ukládány jako JSON objekty `{name, quantity, unit}`. Stávající šablony
(`day_plan.php`, `ShoppingList::aggregateIngredients`) jsou aktualizovány pro zpracování
obou formátů — původní demo data (plain string) i nových strukturovaných dat (objekt).

### Logika `generateWeek`

```
1. Pokud !$force a plány existují → return true (skip)
2. Pokud $force → DELETE FROM meal_plans WHERE user_id=? AND week_id=?
3. Načti profil uživatele + týden z DB
4. buildPrompts() → načte šablony ze souborů, dosadí proměnné
5. LlmFactory::create()->complete(system, user, options)
   → OpenAiProvider loguje do data/llm_YYYY-MM-DD.db
6. Pokus 1: parseResponse() → při InvalidArgumentException pokus 2
   Pokus 2: correction prompt → parseResponse() znovu
7. Úspěch: seedFromLlm() → return true
8. Při jakékoliv výjimce: error_log() + seedDemoWeek() → return false
```

---

## 8. Manuální přegenerování z UI

Funkce pro přegenerování jídelníčku na kliknutí uživatele. Aktivní pouze pokud je nastavena
env proměnná `AI_REGEN_UI_ENABLED=true`. Tato funkce umožňuje uživateli zkusit novou sadu
AI jídel bez čekání na automatický cron.

### Nová routa `POST /plan/regenerate`

```
POST /plan/regenerate
  CSRF validace
  Kontrola: getenv('AI_REGEN_UI_ENABLED') ∈ ['true','1','yes'] — jinak redirect bez akce
  Parametr: week_id (z formuláře) — pokud chybí, použije aktuální týden
  Zavolá: MealGenerator::generateWeek($userId, $weekId, force: true)
  Redirect: /plan/week?week=N&year=Y
```

### UI: tlačítko v šablonách

Tlačítko `Přegenerovat AI` je zobrazeno v `templates/week_plan.php` i `templates/day_plan.php`
pokud `AI_REGEN_UI_ENABLED` je truthy. Odesílá formulář s CSRF tokenem a `week_id`.

---

## 9. Aktualizace `src/MealPlan.php`

Přidat metodu:

```php
/**
 * Vrátí (a případně vytvoří) záznam pro příští ISO týden.
 * Správně ošetřuje přechod rok/týden pomocí PHP ISO date formátu 'o' (ISO year).
 *
 * @return array{id: int, week_number: int, year: int, generated_at: ?string}
 */
public static function getOrCreateNextWeek(): array
```

---

## 10. Aktualizace `cron/generate_weekly.php`

```php
<?php
/**
 * Spouštěn každou neděli v 23:00 přes K8s CronJob (M7).
 * Generuje jídelníčky pro všechny uživatele na příští týden.
 * Výstup: stdout pro K8s logy.
 */
require_once __DIR__ . '/../vendor/autoload.php';

use Aidelnicek\Database;
use Aidelnicek\MealPlan;
use Aidelnicek\MealGenerator;

Database::init(dirname(__DIR__));
$db       = Database::get();
$nextWeek = MealPlan::getOrCreateNextWeek();
$users    = $db->query("SELECT id, name FROM users")->fetchAll(PDO::FETCH_ASSOC);

$ok = $fallback = 0;
foreach ($users as $user) {
    $exists = $db->prepare('SELECT COUNT(*) FROM meal_plans WHERE user_id=? AND week_id=?');
    $exists->execute([$user['id'], $nextWeek['id']]);
    if ($exists->fetchColumn() > 0) { $ok++; continue; }

    MealGenerator::generateWeek($user['id'], $nextWeek['id']) ? $ok++ : $fallback++;
}

echo date('Y-m-d H:i:s') . " — Týden {$nextWeek['week_number']}/{$nextWeek['year']}: {$ok} OK, {$fallback} fallback\n";
```

---

## 11. Aktualizace `Dockerfile`

Přidat `curl` PHP extension (potřebná pro volání OpenAI API):

```dockerfile
RUN apt-get update && apt-get install -y libsqlite3-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_sqlite curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
```

---

## 12. Nové GitHub Secrets

| Secret                | Povinný   | Popis                                                                 |
|-----------------------|-----------|-----------------------------------------------------------------------|
| `OPENAI_AUTH_BEARER`  | **Ano**   | Bearer token: API klíč (`sk-...`) nebo OAuth access token             |
| `OPENAI_MODEL`        | Ne        | Model (výchozí: `gpt-4o`); např. `gpt-4o`, `gpt-4o-mini`             |
| `OPENAI_BASE_URL`     | Ne        | Vlastní endpoint (výchozí: `https://api.openai.com/v1`)               |
| `LLM_PROVIDER`        | Ne        | Provider (výchozí: `openai`)                                          |
| `AI_REGEN_UI_ENABLED` | Ne        | `true` = zobrazí tlačítko Přegenerovat AI v UI (výchozí: skryto)      |

**Poznámka k `OPENAI_AUTH_BEARER`:** Hodnota může být:
- **API klíč** — vygenerovaný na [platform.openai.com/api-keys](https://platform.openai.com/api-keys)
- **OAuth access token** — vydaný poskytovatelem identity při enterprise SSO přihlášení

Obě varianty se posílají identicky jako HTTP bearer token.

---

## 13. Aktualizace `helm/aidelnicek/templates/deployment.yaml`

Přidat sekci `envFrom` do specifikace kontejneru:

```yaml
          envFrom:
            - secretRef:
                name: {{ include "aidelnicek.fullname" . }}-llm
                optional: true
```

`optional: true` zajistí start podu i bez LLM secretu (lokální vývoj bez OpenAI klíče).

---

## 14. Aktualizace `.github/workflows/deploy.yml` a `release.yml`

Přidat krok před Helm deploy:

```yaml
- name: Create OpenAI LLM secret
  run: |
    kubectl create secret generic aidelnicek-llm \
      --from-literal=OPENAI_AUTH_BEARER="${{ secrets.OPENAI_AUTH_BEARER }}" \
      --from-literal=OPENAI_MODEL="${{ secrets.OPENAI_MODEL || 'gpt-4o' }}" \
      --from-literal=OPENAI_BASE_URL="${{ secrets.OPENAI_BASE_URL || 'https://api.openai.com/v1' }}" \
      --from-literal=LLM_PROVIDER="openai" \
      --from-literal=AI_REGEN_UI_ENABLED="${{ secrets.AI_REGEN_UI_ENABLED || 'false' }}" \
      --namespace=<NAMESPACE> \
      --dry-run=client -o yaml | kubectl apply -f -
```

---

## 15. Souhrn endpointů

| Metoda | URL               | Popis                                                    |
|--------|-------------------|----------------------------------------------------------|
| POST   | `/plan/regenerate`| Přegenerování AI jídelníčku; aktivní jen při `AI_REGEN_UI_ENABLED=true` |

Ostatní endpointy (M3/M4) zůstávají beze změny. Generování probíhá transparentně —
uživatel vidí AI generovaná jídla na stávajících routách `/plan/day` a `/plan/week`.

---

## 16. Adresářová struktura po M5

```
src/
  Llm/
    LlmInterface.php       ← nový
    OpenAiProvider.php     ← nový
    LlmLogger.php          ← nový
    LlmFactory.php         ← nový
  MealGenerator.php        ← nový
  MealPlan.php             ← +getOrCreateNextWeek()
  ShoppingList.php         ← +strukturované ingredience v aggregateIngredients

prompts/
  system.txt               ← nový
  meal_plan_generate.txt   ← nový
  meal_plan_history.txt    ← nový
  README.md                ← nový

data/
  aidelnicek.sqlite        ← existující
  llm_YYYY-MM-DD.db        ← automaticky vytvářeno LlmLoggerem

cron/
  generate_weekly.php      ← přepsán (plná implementace)

templates/
  week_plan.php            ← +tlačítko Přegenerovat AI (podmíněno env)
  day_plan.php             ← +strukturované ingredience + tlačítko Přegenerovat AI

public/
  index.php                ← +POST /plan/regenerate
  css/style.css            ← +styly regenerate button

docs/
  GITHUB_SECRETS.md        ← +M5 secrets

helm/aidelnicek/
  templates/
    deployment.yaml        ← +envFrom secretRef

.github/workflows/
  deploy.yml               ← +kubectl create secret (staging)
  release.yml              ← +kubectl create secret (production)

Dockerfile                 ← +curl PHP extension
```
