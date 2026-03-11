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
| Upravit  | `cron/generate_weekly.php`                     |
| Upravit  | `Dockerfile`                                   |
| Upravit  | `helm/aidelnicek/templates/deployment.yaml`    |
| Upravit  | `.github/workflows/deploy.yml`                 |
| Upravit  | `.github/workflows/release.yml`                |
| Upravit  | `docs/GITHUB_SECRETS.md`                       |

---

## 1. Architektura LLM vrstvy

```
LlmFactory::create()
    └─> OpenAiProvider (implements LlmInterface)
            ├─ Auth: OPENAI_AUTH_METHOD=api_key  → Bearer $OPENAI_API_KEY
            │        OPENAI_AUTH_METHOD=oauth    → Bearer $OPENAI_OAUTH_TOKEN
            ├─ Model: $OPENAI_MODEL (výchozí: gpt-4o)
            ├─ Base URL: $OPENAI_BASE_URL (výchozí: https://api.openai.com/v1)
            └─ Loguje každé volání přes LlmLogger

LlmLogger
    └─> data/llm_YYYY-MM-DD.db   (SQLite, automatická rotace po dnech)
            └─ tabulka: llm_log

MealGenerator
    ├─> User::findById()              — profil uživatele
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

Namespace `Aidelnicek\Llm`. PSR-4 autoloading to pokryje automaticky díky mapování `Aidelnicek\` → `src/`.

```php
namespace Aidelnicek\Llm;

interface LlmInterface
{
    /**
     * Odešle prompt modelu a vrátí textovou odpověď.
     *
     * @param string $systemPrompt  Systémový kontext (role/instrukce modelu)
     * @param string $userPrompt    Uživatelský prompt (konkrétní zadání)
     * @param array  $options       Volitelné: 'temperature' (float), 'max_tokens' (int),
     *                              'user_id' (int) — pro logování
     * @return string               Textová odpověď modelu
     * @throws \RuntimeException    Při HTTP chybě, timeoutu nebo neplatné autentizaci
     */
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): string;

    /** Vrátí identifikátor poskytovatele, např. 'openai' */
    public function getName(): string;

    /** Vrátí název použitého modelu, např. 'gpt-4o' */
    public function getModel(): string;
}
```

---

## 3. `src/Llm/OpenAiProvider.php`

Namespace `Aidelnicek\Llm`.

```php
class OpenAiProvider implements LlmInterface
{
    private string $authMethod;  // 'api_key' | 'oauth'
    private string $token;       // hodnota z env dle authMethod
    private string $model;
    private string $baseUrl;
    private LlmLogger $logger;

    public function __construct() { ... }

    public function complete(string $systemPrompt, string $userPrompt, array $options = []): string
    {
        // 1. Sestaví payload (model, messages, temperature, max_tokens)
        // 2. Zaznamená $requestAt, spustí timer
        // 3. Zavolá callApi('/chat/completions', $payload) — try/catch
        // 4. Extrahuje odpověď a tokeny z JSON
        // 5. V bloku finally: zaloguje vše přes $this->logger->log(...)
        // 6. Při výjimce vyhodí RuntimeException výše (po zalogování)
    }

    public function getName(): string  { return 'openai'; }
    public function getModel(): string { return $this->model; }

    /**
     * Volání OpenAI REST API přes curl.
     * Hlavička Authorization: Bearer <token>  (API klíč i OAuth token mají identický formát)
     * Timeout: 90 s (LLM generování může trvat déle)
     * @throws \RuntimeException při HTTP != 2xx nebo curl chybě
     */
    private function callApi(string $endpoint, array $payload): string { ... }
}
```

### Autentizační logika

| `OPENAI_AUTH_METHOD` | Načítá se z env       | HTTP hlavička                        |
|----------------------|-----------------------|--------------------------------------|
| `api_key` (výchozí)  | `OPENAI_API_KEY`      | `Authorization: Bearer <api-key>`    |
| `oauth`              | `OPENAI_OAUTH_TOKEN`  | `Authorization: Bearer <oauth-token>`|

Přepínač `OPENAI_AUTH_METHOD` pouze určuje, ze které env proměnné token načíst —
HTTP hlavička je v obou případech identická (OpenAI API je rozlišuje serverově).
Pokud je proměnná prázdná, konstruktor vyhodí `RuntimeException` se srozumitelnou
chybovou zprávou, aby selhání bylo viditelné okamžitě při startu cronu.

---

## 4. `src/Llm/LlmLogger.php`

Namespace `Aidelnicek\Llm`.

```php
class LlmLogger
{
    /**
     * Vrátí cestu k dnešní log DB.
     * Formát: <project_root>/data/llm_YYYY-MM-DD.db
     * Soubor se vytvoří automaticky při prvním volání daného dne.
     */
    private function getDbPath(): string
    {
        return dirname(__DIR__, 2) . '/data/llm_' . date('Y-m-d') . '.db';
    }

    /**
     * Vrátí PDO spojení s dnešní DB.
     * Při neexistenci souboru: vytvoří ho a zavolá initSchema().
     */
    private function getConnection(): \PDO { ... }

    /**
     * Vytvoří tabulku llm_log (voláno jen při inicializaci nového souboru).
     */
    private function initSchema(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS llm_log (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            provider       TEXT    NOT NULL,
            model          TEXT    NOT NULL,
            auth_method    TEXT    NOT NULL,
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

    /**
     * Zapíše jeden řádek komunikace s LLM.
     * Volá se z OpenAiProvider::complete() — vždy, i při chybě.
     */
    public function log(array $data): void { ... }
}
```

### Schéma tabulky `llm_log`

| Sloupec        | Typ     | Popis                                          |
|----------------|---------|------------------------------------------------|
| `id`           | INTEGER | Primární klíč (autoincrement)                  |
| `provider`     | TEXT    | Název poskytovatele, např. `openai`            |
| `model`        | TEXT    | Název modelu, např. `gpt-4o`                   |
| `auth_method`  | TEXT    | `api_key` nebo `oauth`                         |
| `user_id`      | INTEGER | ID uživatele (NULL = systémové volání)         |
| `prompt_system`| TEXT    | Plný text systémového promptu                  |
| `prompt_user`  | TEXT    | Plný text uživatelského promptu                |
| `response_text`| TEXT    | Plný text odpovědi modelu (NULL při chybě)     |
| `tokens_in`    | INTEGER | Počet vstupních tokenů (z API odpovědi)        |
| `tokens_out`   | INTEGER | Počet výstupních tokenů (z API odpovědi)       |
| `request_at`   | TEXT    | Čas odeslání požadavku (`YYYY-MM-DD HH:MM:SS`) |
| `duration_ms`  | INTEGER | Doba trvání volání v milisekundách             |
| `status`       | TEXT    | `ok` nebo `error`                              |
| `error_message`| TEXT    | Chybová zpráva (NULL pokud `status=ok`)        |

### Rotace logů

- Každý nový den vznikne nový soubor `data/llm_YYYY-MM-DD.db` automaticky.
- Stará log DB se neodstraňuje — správce ji archivuje nebo maže dle politiky.
- K prohlížení lze použít libovolný SQLite klient (DB Browser for SQLite, `sqlite3` CLI apod.).
- Složka `data/` je persistentně uložena na PVC (`/var/www/html/data`) — sdílena i pro
  `aidelnicek.sqlite`. Při vysokém počtu uživatelů zvažte navýšení `persistence.size`
  v `helm/aidelnicek/values.yaml` (výchozí `1Gi`).

---

## 5. `src/Llm/LlmFactory.php`

Namespace `Aidelnicek\Llm`.

```php
class LlmFactory
{
    /**
     * Vytvoří a vrátí nakonfigurovaného poskytovatele LLM.
     * Provider se vybírá dle env proměnné LLM_PROVIDER (výchozí: 'openai').
     *
     * Přidání nového providera:
     *   1. Vytvořit src/Llm/NovyProvider.php implementující LlmInterface
     *   2. Přidat case do switch níže
     *   3. Nastavit LLM_PROVIDER=novy_provider v K8s Secret / env
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

Šablony jsou prosté textové soubory s proměnnými ve formátu `{NAZEV_PROMENNE}`.
`MealGenerator` je načítá přes `file_get_contents()` a nahrazuje hodnoty pomocí `strtr()`.
Editace šablon nevyžaduje změnu PHP kódu.

---

### `prompts/system.txt`

**Účel:** Definuje roli asistenta a závazná pravidla odpovědi (JSON only, žádný obalující text).
Posílá se jako `role: system` při každém volání API. Měnit pouze při změně formátu výstupu
nebo stravovací filosofie.

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

**Účel:** Hlavní uživatelský prompt pro generování kompletního 7denního jídelníčku.
Obsahuje profil uživatele, volitelný blok historie (`{HISTORY_BLOCK}`) a definici
očekávaného JSON výstupu (`{JSON_SCHEMA}`). Oba bloky vkládá `MealGenerator`
programaticky před odesláním.

Proměnné: `{USER_NAME}`, `{GENDER}`, `{AGE}`, `{BODY_TYPE}`, `{DIETARY_NOTES}`,
`{WEEK_NUMBER}`, `{YEAR}`, `{HISTORY_BLOCK}`, `{JSON_SCHEMA}`.

```
Vytvoř týdenní jídelníček (7 dní) pro uživatele s těmito parametry:

Profil:
- Jméno: {USER_NAME}
- Pohlaví: {GENDER}
- Věk: {AGE} let
- Typ postavy: {BODY_TYPE}
- Stravovací omezení / preference: {DIETARY_NOTES}
- Generuji pro: týden {WEEK_NUMBER}/{YEAR}
{HISTORY_BLOCK}
Pro každý den (1 = pondělí … 7 = neděle) vygeneruj 5 chodů:
breakfast, snack_am, lunch, snack_pm, dinner.
Každý chod má dvě alternativy (alt1, alt2). Ingredience uváděj jako pole objektů
{"name": "...", "quantity": číslo, "unit": "..."} (např. g, ml, ks, lžíce).

Vrať přesně tento JSON (bez markdown, bez komentářů):
{JSON_SCHEMA}
```

---

### `prompts/meal_plan_history.txt`

**Účel:** Volitelný blok vkládaný do `{HISTORY_BLOCK}` v `meal_plan_generate.txt`.
Informuje model o preferencích uživatele odvozených z tabulky `meal_history`.
Vkládá se pouze pokud uživatel má ≥ 5 záznamů v `meal_history`.
Při nedostatku dat je `{HISTORY_BLOCK}` prázdný řetězec.

Proměnné: `{LIKED_MEALS}`, `{DISLIKED_MEALS}`.

```
Historická data preferencí uživatele (respektuj je při výběru jídel):
- Oblíbená jídla (zvolena nebo snězena vícekrát — zahrnuj je nebo jejich variace): {LIKED_MEALS}
- Méně oblíbená jídla (opakovaně odmítnuta — vyhýbej se jim): {DISLIKED_MEALS}
```

---

### `prompts/README.md`

**Účel:** Dokumentace všech prompt souborů pro budoucí správce a vývojáře.

```markdown
# Šablony promptů — dokumentace

Všechny soubory v této složce jsou prostý text načítaný třídou `MealGenerator`.
Proměnné ve formátu `{NAZEV}` jsou nahrazeny dynamickými hodnotami před odesláním na API.

## system.txt
Systémový prompt (role: system). Nastavuje roli, jazyk a formát odpovědi.
Posílá se při každém volání API jako první zpráva.
Změna: pouze při úpravě stravovací filosofie nebo výstupního JSON formátu.

## meal_plan_generate.txt
Hlavní uživatelský prompt pro generování 7denního jídelníčku.
Proměnné:
  {USER_NAME}      — křestní jméno uživatele
  {GENDER}         — pohlaví (muž/žena)
  {AGE}            — věk v letech
  {BODY_TYPE}      — typ postavy z profilu
  {DIETARY_NOTES}  — stravovací omezení a poznámky z profilu
  {WEEK_NUMBER}    — ISO číslo týdne (1–53)
  {YEAR}           — rok
  {HISTORY_BLOCK}  — obsah meal_plan_history.txt s dosazenými hodnotami,
                     nebo prázdný řetězec pokud není dostatek historických dat
  {JSON_SCHEMA}    — inline JSON schéma výstupu (generuje MealGenerator)

## meal_plan_history.txt
Volitelný blok dosazovaný za {HISTORY_BLOCK}.
Aktivuje se pokud uživatel má ≥ 5 záznamů v tabulce meal_history.
Proměnné:
  {LIKED_MEALS}    — čárkami oddělený seznam oblíbených jídel
                     (kritérium: times_eaten / times_offered ≥ 0.6, min. 3 nabídnutí)
  {DISLIKED_MEALS} — čárkami oddělený seznam odmítaných jídel
                     (kritérium: times_eaten / times_offered ≤ 0.2, min. 3 nabídnutí)
```

---

## 7. `src/MealGenerator.php`

Namespace `Aidelnicek`.

```php
class MealGenerator
{
    /**
     * Vygeneruje týdenní jídelníček pro daného uživatele přes LLM.
     * Idempotentní: pokud pro daný week_id již existují meal_plans řádky, přeskočí.
     * Při selhání LLM nebo parsování JSON spustí MealPlan::seedDemoWeek() jako fallback.
     *
     * @return bool  true = úspěšně vygenerováno přes LLM, false = použit fallback
     */
    public static function generateWeek(int $userId, int $weekId): bool

    /**
     * Sestaví systémový a uživatelský prompt ze šablon v prompts/.
     * Načte profil uživatele z DB a historii preferencí.
     *
     * @return array{system: string, user: string}
     */
    private static function buildPrompts(array $user, int $weekNumber, int $year): array

    /**
     * Zparsuje JSON odpověď LLM do strukturovaného pole.
     * Validuje přítomnost 7 dní × 5 meal typů × 2 alternativ.
     * Při neplatném JSON nebo chybějící struktuře vyhodí \InvalidArgumentException.
     */
    private static function parseResponse(string $response): array

    /**
     * Vloží zparsovaná LLM data do tabulky meal_plans.
     * Volá MealHistory::recordOffer() pro každé vložené jídlo.
     * Idempotentní přes INSERT OR IGNORE.
     */
    private static function seedFromLlm(int $userId, int $weekId, array $days): void

    /**
     * Načte a vrátí preference z meal_history.
     *
     * Liked:    times_eaten / times_offered ≥ 0.6  AND times_offered ≥ 3
     * Disliked: times_eaten / times_offered ≤ 0.2  AND times_offered ≥ 3
     *
     * @return array{liked: string[], disliked: string[]}
     */
    private static function getPreferences(int $userId): array

    /**
     * Vrátí inline JSON schéma pro dosazení do {JSON_SCHEMA} v promptu.
     * Statická hodnota — popisuje strukturu očekávané odpovědi.
     */
    private static function getJsonSchema(): string
}
```

### Očekávaný JSON formát odpovědi LLM

```json
{
  "days": [
    {
      "day": 1,
      "meals": {
        "breakfast": {
          "alt1": {
            "name": "Ovesná kaše s lesním ovocem",
            "description": "Teplá kaše s čerstvým ovocem a medem.",
            "ingredients": [
              {"name": "ovesné vločky", "quantity": 80, "unit": "g"},
              {"name": "borůvky",       "quantity": 100, "unit": "g"},
              {"name": "med",           "quantity": 1,   "unit": "lžíce"}
            ]
          },
          "alt2": { "name": "...", "description": "...", "ingredients": [...] }
        },
        "snack_am":  { "alt1": {...}, "alt2": {...} },
        "lunch":     { "alt1": {...}, "alt2": {...} },
        "snack_pm":  { "alt1": {...}, "alt2": {...} },
        "dinner":    { "alt1": {...}, "alt2": {...} }
      }
    }
    // ... dny 2–7
  ]
}
```

### Logika metody `generateWeek`

```
1. Zkontroluj existenci záznamů v meal_plans pro (userId, weekId) → pokud existují, return true
2. Načti profil uživatele přes User::findById($userId)
3. Sestaví prompty: buildPrompts($user, $weekNumber, $year)
   → načte prompts/system.txt a prompts/meal_plan_generate.txt
   → pokud getPreferences() vrátí ≥ 5 záznamů, dosadí také prompts/meal_plan_history.txt
4. Zavolej LlmFactory::create()->complete($system, $user, ['user_id' => $userId])
   → OpenAiProvider automaticky zaloguje volání do data/llm_YYYY-MM-DD.db
5. Pokus 1: parseResponse($response) → při InvalidArgumentException pokus 2
   Pokus 2: pošle doplňující prompt "Odpověz pouze validním JSON bez dalšího textu."
            a znovu volá complete() a parseResponse()
6. Při úspěchu: seedFromLlm($userId, $weekId, $days) → return true
7. Při jakékoliv výjimce (síťová chyba, timeout, oba JSON pokusy selhaly):
   → zaloguje chybu (logger ji zaznamenal v kroku 4)
   → MealPlan::seedDemoWeek($userId, $weekId)
   → return false
```

---

## 8. Aktualizace `src/MealPlan.php`

Přidat jednu novou metodu (analogická k `getOrCreateCurrentWeek`):

```php
/**
 * Vrátí (a případně vytvoří) záznam pro příští ISO týden.
 * Pokud aktuální týden = 52/53, správně přejde na týden 1 nového roku.
 *
 * @return array{id: int, week_number: int, year: int}
 */
public static function getOrCreateNextWeek(): array
```

Tato metoda je potřebná pro `cron/generate_weekly.php`.

---

## 9. Aktualizace `cron/generate_weekly.php`

```php
<?php
/**
 * Spouštěn každou neděli v 23:00 přes K8s CronJob (implementován v M7).
 * Účel: vygenerování jídelníčků pro všechny aktivní uživatele na nadcházející týden.
 * Výstup: stdout — počty úspěšných/fallback generování.
 */
require_once __DIR__ . '/../vendor/autoload.php';

use Aidelnicek\Database;
use Aidelnicek\MealPlan;
use Aidelnicek\MealGenerator;

$db      = Database::getInstance()->getConnection();
$nextWeek = MealPlan::getOrCreateNextWeek();

$users = $db->query("SELECT id, name FROM users")->fetchAll(\PDO::FETCH_ASSOC);

$ok       = 0;
$fallback = 0;

foreach ($users as $user) {
    $count = $db->prepare("SELECT COUNT(*) FROM meal_plans WHERE user_id = ? AND week_id = ?");
    $count->execute([$user['id'], $nextWeek['id']]);
    if ($count->fetchColumn() > 0) {
        $ok++;
        continue;
    }

    $success = MealGenerator::generateWeek($user['id'], $nextWeek['id']);
    $success ? $ok++ : $fallback++;
}

echo date('Y-m-d H:i:s') . " — Generování týdne {$nextWeek['week_number']}/{$nextWeek['year']}: "
   . "{$ok} OK, {$fallback} fallback\n";
```

---

## 10. Aktualizace `Dockerfile`

Přidat povolení PHP `curl` extension (potřebné pro volání OpenAI API):

```dockerfile
RUN apt-get update && apt-get install -y libsqlite3-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_sqlite curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
```

Nahradí stávající řádek s `apt-get install -y libsqlite3-dev`.

---

## 11. Nové GitHub Secrets

| Secret                | Povinný      | Popis                                                              |
|-----------------------|--------------|--------------------------------------------------------------------|
| `OPENAI_AUTH_METHOD`  | Ano          | Autentizační metoda: `api_key` (výchozí) nebo `oauth`             |
| `OPENAI_API_KEY`      | Podmíněně    | API klíč; vyžadován pokud `OPENAI_AUTH_METHOD=api_key`            |
| `OPENAI_OAUTH_TOKEN`  | Podmíněně    | OAuth access token; vyžadován pokud `OPENAI_AUTH_METHOD=oauth`    |
| `OPENAI_MODEL`        | Ne           | Název modelu (výchozí: `gpt-4o`); např. `gpt-4o`, `gpt-4o-mini`  |
| `OPENAI_BASE_URL`     | Ne           | Vlastní endpoint (výchozí: `https://api.openai.com/v1`)           |
| `LLM_PROVIDER`        | Ne           | Provider (výchozí: `openai`); pro budoucí rozšíření               |

Secrets platí pro obě prostředí (staging i production). Doporučení: pokud chcete
různé modely pro staging a production, definujte `OPENAI_MODEL` na úrovni GitHub Environment.

---

## 12. Aktualizace `.github/workflows/deploy.yml`

Přidat krok **před** „Deploy to Staging" v jobu `deploy-staging`:

```yaml
- name: Create OpenAI LLM secret
  run: |
    kubectl create secret generic aidelnicek-llm \
      --from-literal=OPENAI_AUTH_METHOD="${{ secrets.OPENAI_AUTH_METHOD || 'api_key' }}" \
      --from-literal=OPENAI_API_KEY="${{ secrets.OPENAI_API_KEY }}" \
      --from-literal=OPENAI_OAUTH_TOKEN="${{ secrets.OPENAI_OAUTH_TOKEN }}" \
      --from-literal=OPENAI_MODEL="${{ secrets.OPENAI_MODEL || 'gpt-4o' }}" \
      --from-literal=OPENAI_BASE_URL="${{ secrets.OPENAI_BASE_URL || 'https://api.openai.com/v1' }}" \
      --from-literal=LLM_PROVIDER="openai" \
      --namespace=${{ secrets.K8S_STAGING_NAMESPACE }} \
      --dry-run=client -o yaml | kubectl apply -f -
```

Vzor `--dry-run=client -o yaml | kubectl apply -f -` zajistí idempotenci
(vytvoří nebo aktualizuje secret, nevyhodí chybu pokud již existuje).

---

## 13. Aktualizace `.github/workflows/release.yml`

Přidat identický krok **před** „Deploy to Production" v jobu `deploy-production`:

```yaml
- name: Create OpenAI LLM secret
  run: |
    kubectl create secret generic aidelnicek-llm \
      --from-literal=OPENAI_AUTH_METHOD="${{ secrets.OPENAI_AUTH_METHOD || 'api_key' }}" \
      --from-literal=OPENAI_API_KEY="${{ secrets.OPENAI_API_KEY }}" \
      --from-literal=OPENAI_OAUTH_TOKEN="${{ secrets.OPENAI_OAUTH_TOKEN }}" \
      --from-literal=OPENAI_MODEL="${{ secrets.OPENAI_MODEL || 'gpt-4o' }}" \
      --from-literal=OPENAI_BASE_URL="${{ secrets.OPENAI_BASE_URL || 'https://api.openai.com/v1' }}" \
      --from-literal=LLM_PROVIDER="openai" \
      --namespace=${{ secrets.K8S_PRODUCTION_NAMESPACE }} \
      --dry-run=client -o yaml | kubectl apply -f -
```

---

## 14. Aktualizace `helm/aidelnicek/templates/deployment.yaml`

Přidat sekci `envFrom` do specifikace kontejneru (po `imagePullPolicy`):

```yaml
          envFrom:
            - secretRef:
                name: {{ include "aidelnicek.fullname" . }}-llm
                optional: true
```

`optional: true` zajistí, že pod nastartuje i bez LLM secretu (pro lokální vývoj
bez OpenAI klíče). Při pokusu o generování pak cron selže čitelnou chybou.

---

## 15. Aktualizace `docs/GITHUB_SECRETS.md`

Přidat novou sekci „M5 — OpenAI LLM integrace" s tabulkou ze sekce 11 tohoto dokumentu
a poznámkou o tom, že secrets jsou předány do K8s přes `kubectl create secret generic`
(vzor viz deploy.yml a release.yml).

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
  MealPlan.php             ← upravit: +getOrCreateNextWeek()

prompts/
  system.txt               ← nový
  meal_plan_generate.txt   ← nový
  meal_plan_history.txt    ← nový
  README.md                ← nový

data/
  aidelnicek.sqlite        ← existující (hlavní DB)
  llm_YYYY-MM-DD.db        ← automaticky vytvářeno při každém LLM volání

cron/
  generate_weekly.php      ← přepsán (plná implementace)

docs/
  GITHUB_SECRETS.md        ← upravit: přidat M5 secrets

helm/aidelnicek/
  templates/
    deployment.yaml        ← upravit: +envFrom secretRef

.github/workflows/
  deploy.yml               ← upravit: +kubectl create secret (staging)
  release.yml              ← upravit: +kubectl create secret (production)

Dockerfile                 ← upravit: +curl PHP extension
```

---

## Souhrn nových HTTP endpointů

M5 nepřidává žádné nové HTTP endpointy — generování jídelníčků probíhá výhradně
v cronu (implementace cron jobu je součástí M7). Uživatel vidí AI generovaná jídla
na stávajících routách `/plan/day` a `/plan/week` místo fixních demo dat.

Záložní `MealPlan::seedDemoWeek()` zůstává funkční pro případ selhání LLM.

---

## Poznámky k rozšiřitelnosti

- **Nový LLM provider** (Anthropic, Gemini, Mistral...):
  1. `src/Llm/NovyProvider.php` implementující `LlmInterface`
  2. Přidat `case` do `LlmFactory::create()`
  3. Nastavit `LLM_PROVIDER=novy_provider` v K8s secret
  4. Přidat příslušné auth secrets do GitHub/K8s
- **`LlmLogger`** je provider-agnostický — loguje libovolného `LlmInterface` implementátora.
- **Prompt šablony** lze upravovat bez zásahu do PHP kódu — editací `.txt` souborů.
- **Nový typ generování** (např. recept na vyžádání) — přidat nový prompt soubor
  a novou metodu v `MealGenerator` nebo samostatné třídě implementující `LlmInterface`.
