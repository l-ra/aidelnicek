# Bezpečnost a správa uživatelů (M8)

## 1. Unifikace LLM komunikace přes worker

Veškerá komunikace s LLM nyní prochází výhradně přes Python FastAPI sidecar (`llm_worker/`).
PHP aplikace **nikdy nevolá OpenAI API přímo** — pouze sestaví prompty a předá je workeru.

### Přehled změn

| Volající | Dříve | Nyní |
|----------|-------|------|
| `MealGenerator::generateWeek()` | Sync PHP curl → OpenAI | `startGenerationJob()` → worker `/generate` + polling |
| `MealGenerator::startGenerationJob()` | Neexistovalo | Nová metoda: worker `/generate`, vrátí `job_id` |
| `/plan/regenerate` (uživatel) | Synchronní `generateWeek()` | Fire-and-forget `startGenerationJob()`, redirect |
| `/admin/llm-test` (admin sandbox) | Sync PHP curl → OpenAI | Worker `/complete` endpoint (synchronní odpověď) |
| `cron/generate_weekly.php` | Volalo `generateWeek()` s PHP LLM | Volá `generateWeek()` → worker + polling |

### Worker endpointy

| Metoda | Endpoint | Popis |
|--------|----------|-------|
| `POST` | `/generate` | Asynchronní generování jídelníčku (streaming, job_id zpět) |
| `POST` | `/complete` | Synchronní LLM completion (bez streamingu, přímá odpověď) |
| `GET`  | `/health`   | K8s liveness/readiness probe |

### Proměnná prostředí

```
LLM_WORKER_URL   URL Python workeru (výchozí: http://localhost:8001)
```

---

## 2. Logování LLM volání

Každé volání LLM — jak streamované generování jídelníčku, tak synchronní completion —
se zaznamenává do **per-day SQLite souborů** ve složce `data/`.

### Formát souborů

```
data/llm_YYYY-MM-DD.db
```

### Schéma tabulky `llm_log`

| Sloupec        | Typ     | Popis |
|----------------|---------|-------|
| `id`           | INTEGER | Primární klíč |
| `provider`     | TEXT    | Poskytovatel LLM (`openai`) |
| `model`        | TEXT    | Název modelu |
| `user_id`      | INTEGER | ID uživatele (nebo NULL) |
| `prompt_system`| TEXT    | Systémový prompt |
| `prompt_user`  | TEXT    | Uživatelský prompt |
| `response_text`| TEXT    | Odpověď modelu |
| `tokens_in`    | INTEGER | Počet vstupních tokenů |
| `tokens_out`   | INTEGER | Počet výstupních tokenů |
| `request_at`   | TEXT    | Čas požadavku (UTC) |
| `duration_ms`  | INTEGER | Trvání v ms |
| `status`       | TEXT    | `ok` nebo `error` |
| `error_message`| TEXT    | Chybová zpráva (nebo NULL) |

Soubory zapisuje **Python worker** (`llm_worker/logger.py`) — stejné schéma jako PHP
`LlmLogger`, takže PHP admin interface `/admin/llm-logs` zobrazuje záznamy z obou zdrojů.

---

## 3. Bootstrap výchozího administrátora

Při prvním spuštění aplikace (nebo pokud v databázi neexistuje žádný uživatel s `is_admin = 1`)
`Database::ensureAdminUser()` automaticky vytvoří výchozí admin účet.

### Parametry výchozího účtu

| Parametr | Hodnota |
|----------|---------|
| Jméno    | Administrátor |
| E-mail   | `admin@localhost` |
| Heslo    | Náhodný 16-znakový řetězec (base64url) |
| is_admin | 1 |

### Kde najít heslo

Heslo je zapsáno na dvě místa:

1. **PHP error log** — řádek se prefixem `Aidelnicek: Byl vytvořen výchozí administrátorský účet.`
2. **Soubor `/tmp/initial-admin-password`** (oprávnění `0600`) — smazat po přihlášení!

### Bezpečnostní doporučení

- Po prvním přihlášení heslo okamžitě změňte v profilu.
- Soubor `/tmp/initial-admin-password` po přečtení smažte.
- V produkci použijte Kubernetes Secret pro přístup k tomuto souboru.

---

## 4. Systém pozvánek

Registrace je **uzavřená** — nový uživatel se může zaregistrovat pouze s platným
zvacím odkazem vygenerovaným správcem aplikace.

### Generování pozvánky

Admin otevře `/admin/invite`, zadá e-mail pozvaného a platnost odkazu (výchozí 7 dní).
Systém vygeneruje zvací odkaz ve tvaru:

```
https://your-domain.com/register?invite=<TOKEN>
```

### Formát tokenu

Token je self-contained JWT-like řetězec s HMAC-SHA256 podpisem:

```
base64url(JSON payload) . "." . base64url(HMAC-SHA256 podpis)
```

**Payload:**

```json
{
  "email":   "uzivatel@example.com",
  "expires": 1234567890,
  "nonce":   "a1b2c3d4e5f6g7h8"
}
```

### Tajemství pro podpis

Tajemství je generováno automaticky při prvním použití a uloženo do souboru:

```
data/invite_secret.key
```

Soubor má oprávnění `0600`. Pokud neexistuje, vygeneruje se `bin2hex(random_bytes(32))`.

> **Důležité:** Soubor `data/invite_secret.key` **nesmí být v gitu** (viz `.gitignore`).
> V Kubernetes ho mountujte jako Secret.

### Validace tokenu

Token je validní, pokud:
1. HMAC-SHA256 podpis odpovídá obsahu payloadu a tajemství
2. `expires` je v budoucnosti
3. `email` v payloadu je syntakticky validní

### Vlastnosti pozvánkového systému

- Jeden token = jeden konkrétní e-mail
- Token lze technicky použít vícekrát v době platnosti, ale databázový UNIQUE index na
  `users.email` zabrání druhé registraci se stejným e-mailem
- Po vypršení platnosti token přestane fungovat
- Není potřeba databázová tabulka pro tracking tokenů — vše je zakódováno v tokenu

### Registrační formulář

- E-mail je předvyplněn z tokenu a nelze ho změnit
- Formulář obsahuje všechna profilová pole: jméno, heslo, pohlaví, věk, postava,
  výška, váha, dietní omezení, cíl jídelníčku

---

## 5. Přehled souborů

| Soubor | Popis |
|--------|-------|
| `src/Invite.php` | Třída pro generování a validaci tokenů |
| `src/Database.php` | `ensureAdminUser()` — bootstrap admin účtu |
| `src/User.php` | `create()` rozšíren o výšku, váhu, cíl; `validateRegistration()` rozšíren |
| `src/MealGenerator.php` | `startGenerationJob()`, `generateWeek()`, `waitForJob()` |
| `llm_worker/logger.py` | Logování LLM volání do per-day SQLite |
| `llm_worker/generator.py` | `stream_and_store()` + logování; nová `complete_sync()` |
| `llm_worker/main.py` | Nový endpoint `POST /complete` |
| `templates/admin_invite.php` | Admin UI pro generování pozvánek |
| `templates/register.php` | Rozšířený formulář + validace pozvánky |
| `templates/layout.php` | Skryta položka "Registrace" pro nepřihlášené |
| `templates/login.php` | Informační zpráva při chybějící pozvánce |
| `public/index.php` | Aktualizované routy register, llm-test, regenerate; nové routy /admin/invite |
| `data/invite_secret.key` | HMAC tajemství (auto-generováno, .gitignore) |
