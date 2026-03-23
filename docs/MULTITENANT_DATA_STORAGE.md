# Multitenantnost datového úložiště (analýza a návrh)

Tento dokument shrnuje analýzu požadavku na oddělení dat více domácností (tenantů) pod jednou nasazenou instancí aplikace a navrhuje způsob implementace. **Samotná implementace zde není provedena** — jde o podklad pro další práci.

## 1. Požadavek (shrnutí)

- Jedna kódová báze a jeden běh aplikace obsluhuje více tenantů.
- Tenant je adresován **prefixem cesty** na stejné doméně, např. `https://host/dplusk/…` a `https://host/vitr/…`.
- Data každého tenantu jsou v **izolované složce** pod kořenem dat: `/data/<tenant>/` (v repozitáři relativně `{projectRoot}/data/<tenant>/`).
- **Router** detekuje tenant z URL a následně se používá odpovídající datová složka (zejména SQLite a související soubory).
- Volání **LLM workeru** musí nést identifikaci tenantu, aby worker zapisoval do správné databáze / správného datového stromu.

Poznámka k formulaci „kód aplikace neměnit uvnitř“: v praxi je nutné **upravit vstupní vrstvu** (bootstrap, router, generování URL, volání workeru) a místa, která dnes mají cestu k `data/` natvrdo. Logika domény (modely, šablony jako takové) může zůstat beze změny, pokud veškerý přístup k DB a souborům projde společným „tenant-aware“ kontextem.

## 2. Současný stav v repozitáři

### 2.1 PHP — databáze a soubory v `data/`

- `Database::init($projectRoot)` vytváří `{projectRoot}/data` a používá `{projectRoot}/data/aidelnicek.sqlite`. Spojení je **statické singleton** (`Database::get()`).
- `Router` už podporuje **odříznutí URL prefixu** přes konstruktor `Router($projectRoot, $urlBasePath)`, ale `public/index.php` dnes volá `new Router($projectRoot)` bez druhého parametru.
- **Přesměrování a odkazy** jsou v `public/index.php` a dalších místech většinou absolutní vůči kořenu hostitele (`header('Location: /login')`, atd.), nikoli vůči tenant prefixu.
- `Auth` používá PHP session a cookie `remember_token` s cestou `'/'`. Session ani cookie nejsou v názvu vázané na tenant → **riziko prolnutí přihlášení** mezi tenanty na stejné doméně.
- Soubory / cesty závislé na `data/`:
  - `Invite::getSecretKey()` — `data/invite_secret.key`
  - `ShoppingListExport::getSecretKey()` — stejný soubor
  - `LlmLogger` — `data/llm_YYYY-MM-DD.db`
  - `public/index.php` (endpoint admin LLM logů) — `projectRoot/data/<filename>`
  - `public/admin/phpliteadmin.php` — `projectRoot/data/`

### 2.2 LLM worker (Python)

- `llm_worker/main.py`: `POST /generate` a `POST /complete`; databáze přes `database.open_db()` a `DB_PATH` (výchozí `/data/aidelnicek.sqlite`).
- `GenerationJobService::startJob()` posílá JSON na `{LLM_WORKER_URL}/generate` **bez** pole pro tenant.

### 2.3 Kubernetes / Docker

- V `helm/aidelnicek/templates/deployment.yaml` je stejný PVC připojený jako `/var/www/html/data` (PHP) a jako `/data` (worker). Kořen svazku je sdílený: soubor viditelný jako `/data/aidelnicek.sqlite` v workeru odpovídá `/var/www/html/data/aidelnicek.sqlite` v kontejneru aplikace.
- Po zavedení tenant složek musí zůstat zachována **konzistence cest** mezi PHP (`/var/www/html/data/<tenant>/…`) a workerem (`/data/<tenant>/…`).

### 2.4 Pozadí procesy

- `bin/generation-projector.php` volá `Database::init($projectRoot)` bez tenant kontextu — dnes implicitně jedna DB.
- `cron/generate_weekly.php` stejně tak — jeden „globální“ tenant.

## 3. Cílový model

### 3.1 Identifikace tenantu

- **Primární zdroj:** první segment cesty po kořeni aplikace na ingressu, např. `/dplusk/plan` → tenant `dplusk`.
- **Validace názvu:** doporučuje se povolit pouze bezpečný subset (např. `[a-z0-9_-]+`, délka, rezervovaná jména jako `static` pokud budou potřeba).
- **Po normalizaci:** interní cesty pro router = cesta **bez** prefixu tenantu (stejně jako dnes `/plan`, `/login`, …).

### 3.2 Datový adresář

Pro tenant `t`:

- Hlavní SQLite: `{dataRoot}/t/aidelnicek.sqlite` (odpovídá požadavku „/data/dplusk“, „/data/vitr“).
- Stejně pod `t/` patří: `invite_secret.key`, `llm_*.db`, případné další soubory vytvářené aplikací.

**Inicializace:** při prvním požadavku na tenant (nebo při explicitním provisioningu) vytvořit `{dataRoot}/t/` s právy jako dnes u `data/`.

### 3.3 Propojení s LLM workerem

Worker musí pro každý požadavek otevřít správnou databázi. Možné varianty:

1. **Doporučeno:** v těle `GenerateRequest` / `CompleteRequest` přidat pole `tenant_id` (řetězec). Worker z něj sestaví cestu, např. `os.path.join(os.environ.get("DATA_ROOT", "/data"), tenant_id, "aidelnicek.sqlite")`, s validací proti path traversal.
2. Alternativa: posílat přímo `db_path` nebo `sqlite_path` — vyšší flexibilita, ale větší riziko zneužití, pokud worker není chráněn síťově; vyžaduje přísnou validaci vůči prefixu `/data`.

PHP při `startJob()` doplní `tenant_id` z aktuálního request kontextu (viz níže).

### 3.4 Request kontext v PHP

Zavedení jednoho z následujících mechanismů (konkrétní volba je implementační detail):

- Jednoduchá třída `TenantContext` s `getId(): ?string` a `getDataDir(): string` vůči `projectRoot`, nastavená v `public/index.php` **před** `Database::init`.
- Nebo rozšíření `Database::init($projectRoot, ?string $tenantId = null)` s interním výpočtem `dataDir`.

Důsledek: všechny volání `Database::get()` v rámci jednoho HTTP requestu používají stejného tenanta. **Pozor:** singleton napříč requesty v PHP typicky nebývá problém (nový proces / nový request), ale u dlouho běžících workerů (php-fpm) musí být stav vždy vázán na request, ne na globální statickou proměnnou přetrvávající mezi requesty — současný statický `$connection` v `Database` je OK, pokud se před každým requestem zavolá `init` s správným tenantem a případně se při změně tenanta resetuje spojení (nebo se použije lazy connection keyed by tenant v rámci requestu).

## 4. Oblasti, které bude nutné řešit (checklist implementace)

| Oblast | Problém | Směr řešení |
|--------|---------|-------------|
| Vstupní bod | Tenant z URL | Parsování `REQUEST_URI` před routováním; 404 pro neplatný tenant |
| `Database` | Jedna cesta | Cílová cesta `{data}/<tenant>/aidelnicek.sqlite`; reset připojení při změně tenantu (nebo request-scoped) |
| `Router` | Prefix | Předat `urlBasePath = '/<tenant>'` do `Router` |
| Redirecty a odkazy | Absolutní `/…` | Centralizovaná funkce `url('/path')` nebo konstanta base path; upravit `header('Location: …')`, šablony, `Invite::getInviteUrl()`, export URL, atd. |
| Session a cookie | Sdílená doména | `session_name` per tenant a/nebo `session.cookie_path` = `/<tenant>/`; cookie `remember_token` s `path` = `/<tenant>/` |
| `Invite` / `ShoppingListExport` | `data/` natvrdo | Číst `invite_secret.key` z tenant `data/` adresáře |
| `LlmLogger` | Per-day db | Cesta `{data}/<tenant>/llm_*.db` |
| `GenerationJobService` | Worker payload | Přidat `tenant_id` do JSON |
| `llm_worker` | `DB_PATH` | Odvodit z `tenant_id` + `DATA_ROOT`; migrace health endpointu |
| `public/admin/phpliteadmin.php` | Jedna složka | Vybrat DB podle tenanta nebo zákaz v multi-tenant režimu bez výběru |
| `.htaccess` / statické soubory | `public/` pod prefixem | Ingress strip prefix **nebo** úprava rewrite pravidel tak, aby `/tenant/…` směřovalo na `index.php` a statika měla správný prefix (často `RewriteBase /tenant/` nebo jedna úroveň dynamiky — náročnější na Apache; u nginx/ingress často lepší `X-Forwarded-Prefix` nebo strip) |
| Helm / Ingress | Jedna cesta `/` | Buď jedna rule s path `/` a aplikace dostává celou cestu, nebo více path prefixů na stejný service; ověřit, zda upstream dostává `/tenant/...` v `REQUEST_URI` |
| Projector a cron | Bez HTTP | Konfigurace seznamu tenantů (`TENANTS=dplusk,vitr`) nebo průchod podadresáři `data/*/`; pro každý tenant spustit stejnou logiku s nastaveným kontextem |

## 5. Migrace existujících dat

- Dnešní instalace má data v `{projectRoot}/data/` přímo (bez podadresáře).
- Možnosti: (a) zvolit **výchozího** tenanta např. `default` a při startu přesunout/namapovat staré soubory; (b) jednorázový migrační skript; (c) režim „legacy“: pokud URL nemá prefix, použít `{data}/` jako dnes (kompatibilita), a multitenant pouze na explicitních prefixech — odchylka od zadání, ale užitečné pro přechod.

## 6. Rizika a bezpečnost

- **Izolace:** oddělené SQLite soubory dávají silnou separaci dat; stále je potřeba zabránit výběru cesty útočníkem (validace `tenant_id`, žádné `..`).
- **Session fixation / cross-tenant:** bez úpravy cookie path a session je přihlášení na `/dplusk` sdílené s `/vitr` — **kritické**.
- **Job ID:** `job_id` v SQLite je per-tenant; SSE a AJAX musí používat URL s prefixem, aby četly stejnou DB.
- **Signed URL** (nákupní seznam): token podepsaný klíčem z tenant `data/` je platný v rámci tenanta; odkaz musí obsahovat stejný path prefix.

## 7. Otevřené otázky (k upřesnění před implementací)

1. **Kořenová URL bez tenant prefixu** — má vracet 404, přesměrovat na výchozí tenant, nebo zobrazit výběr / landing?
2. **Seznam povolených tenantů** — pevně v konfiguraci (env / ConfigMap), nebo jakýkoli podadresář v `data/`, nebo dynamické vytváření při prvním hitu?
3. **Statické soubory** (`/css/`, `/js/`, service worker): mají být na **sdílené** cestě bez tenantu (např. `/assets/…`), nebo duplikované pod každým prefixem? Dopad na `RewriteBase` a cache.
4. **Cron a projector** — má každý tenant stejný harmonogram, nebo někteří tenanti vypnutí?
5. **phpliteadmin** — ponechat pro admina s výběrem tenant DB, nebo odstranit z multi-tenant provozu?

---

*Dokument vytvořen jako součást plánovacího úkolu; implementace proběhne v samostatných commitech podle tohoto návrhu.*
