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

**Poznámka:** nástroj phpLiteAdmin byl z repozitáře odstraněn (viz sekce 7).

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
- **Validace názvu:** bezpečný subset (např. `[a-z0-9_-]+`, délka, rezervovaná jména pro sdílenou statiku — viz níže).
- **Existence tenanta:** tenant je platný **jen pokud existuje adresář** `{dataRoot}/<tenant>/` (žádné „vymýšlení“ tenantů bez složky). Neexistující složka → **404** (nebo ekvivalent).
- **Po normalizaci:** interní cesty pro router = cesta **bez** prefixu tenantu (stejně jako dnes `/plan`, `/login`, …).

### 3.2 Bootstrap nového tenanta (první přístup)

Po potvrzení existence `{dataRoot}/<tenant>/`:

1. Při **prvním** použití databáze pro daný tenant: vytvořit `aidelnicek.sqlite`, spustit migrace, založit **admin účet s náhodným heslem** (stejný princip jako dnešní `ensureAdminUser`, ale výstup hesla směřuje do **souboru v datové složce tenanta**, nejen log /tmp).
2. Po **prvním úspěšném přihlášení administrátora** daného tenantu **soubor s heslem ve složce tenanta smazat** (citlivý únikový kanál — soubor nesmí zůstat dlouhodobě).

*Implementační detail:* název souboru (např. `initial-admin-password.txt`) a přesná politika zápisu sjednotit s bezpečnostními pravidly projektu; chmod omezit na proces uživatele webu.

### 3.3 Kořenová URL (`/`) — landing a `localStorage`

- Na cestě **bez** tenant prefixu se zobrazí **landing stránka** (bez nutnosti PHP session pro výběr tenanta).
- Landing **přesměruje JavaScriptem** na tenant uložený v **`localStorage`** (klíč a formát hodnoty je implementační detail, např. `aidelnicek_tenant` = `dplusk`).
- Po **úspěšném přihlášení** do konkrétního tenantu aplikace **zapíše aktuálního tenanta do `localStorage`**, aby příští návštěva kořene uživatele poslala na správný prefix.
- Pokud v `localStorage` není platný tenant nebo složka neexistuje, landing nabídne návod / ruční zadání / seznam (podle UX) — **doplnit při implementaci**.

### 3.4 Sdílená statika

- CSS, JS, service worker a ostatní statické soubory zůstávají na **společných cestách bez tenant prefixu** (např. `/css/…`, `/js/…`).
- Ingress / rewrite musí rozlišit: požadavky na statiku servírovat přímo z `public/`, požadavky `/<tenant>/…` předat `index.php` s plnou URI. Rezervovat názvy segmentů, které nesmí být interpretovány jako tenant (pokud by kolidovaly se složkami v `public/`).

### 3.5 Datový adresář (běžný provoz)

Pro existující tenant `t`:

- Hlavní SQLite: `{dataRoot}/t/aidelnicek.sqlite`.
- Pod `t/` dále: `invite_secret.key`, `llm_*.db`, případné další soubory vytvářené aplikací.

### 3.6 Propojení s LLM workerem

Worker musí pro každý požadavek otevřít správnou databázi.

1. **Doporučeno:** v těle `GenerateRequest` / `CompleteRequest` přidat pole `tenant_id` (řetězec). Worker z něj sestaví cestu, např. `os.path.join(os.environ.get("DATA_ROOT", "/data"), tenant_id, "aidelnicek.sqlite")`, s validací proti path traversal.
2. Alternativa: posílat přímo `db_path` — vyšší riziko; vyžaduje přísnou validaci vůči prefixu `/data`.

PHP při `startJob()` doplní `tenant_id` z aktuálního request kontextu.

### 3.7 Request kontext v PHP

- Třída nebo mechanismus `TenantContext` s `getId(): string` (u tenantovaných requestů) a `getDataDir(): string`, nastavená v `public/index.php` **před** `Database::init`.
- Nebo rozšíření `Database::init($projectRoot, string $tenantId)` s interním výpočtem `dataDir`.

**Singleton `Database`:** před každým requestem správný `init` + při změně tenanta reset PDO (nebo request-scoped držení připojení).

## 4. Oblasti, které bude nutné řešit (checklist implementace)

| Oblast | Problém | Směr řešení |
|--------|---------|-------------|
| Vstupní bod | Tenant z URL | Parsování `REQUEST_URI`; 404 pokud segment není platný tenant **nebo** neexistuje `{data}/<tenant>/` |
| Landing `/` | Výběr tenantu | Statická nebo lehká PHP stránka + JS redirect z `localStorage`; zápis tenant ID po loginu |
| `Database` | Jedna cesta | `{data}/<tenant>/aidelnicek.sqlite`; bootstrap admin + soubor s heslem + smazání po prvním admin loginu |
| `Router` | Prefix | Předat `urlBasePath = '/<tenant>'` do `Router` pro tenantované routy |
| Redirecty a odkazy | Absolutní `/…` | Centralizovaná funkce `url('/path')` zahrnující tenant prefix tam, kde jde o aplikaci |
| Session a cookie | Sdílená doména | `session.cookie_path` = `/<tenant>/`; cookie `remember_token` s `path` = `/<tenant>/` (případně `session_name` per tenant) |
| `Invite` / `ShoppingListExport` | `data/` natvrdo | Tenant `data/` adresář |
| `LlmLogger` | Per-day db | `{data}/<tenant>/llm_*.db` |
| `GenerationJobService` | Worker payload | Přidat `tenant_id` do JSON |
| `llm_worker` | `DB_PATH` | Odvodit z `tenant_id` + `DATA_ROOT`; health endpoint |
| Statické soubory | Prefix vs tenant | Společné `/css`, `/js` mimo tenant; rewrite / ingress pravidla |
| Helm / Ingress | Cesty | Jedna nebo více path rules; statika bez prefixu, aplikace s `/…` tenant segmentem |
| Projector a cron | Více tenantů | **Stejná logika pro všechny tenanty:** iterace podadresářů `data/*/` (nebo explicitní seznam složek), které vypadají jako platní tenanti; pro každý nastavit kontext a spustit stávající smyčku |

## 5. Migrace existujících dat

- Dnešní instalace může mít data v `{projectRoot}/data/` přímo (flat layout).
- Pro multitenant režim je potřeba **jednorázově** přesunout obsah do `{dataRoot}/<tenant_id>/` (např. `<tenant_id> = default`) nebo zároveň zavést provisioning nových složek jen pro nové domácnosti.
- Rozhodnutí o výchozím `<tenant_id>` pro migraci starých deploymentů doplnit při nasazení.

## 6. Rizika a bezpečnost

- **Izolace:** oddělené SQLite soubory; validace `tenant_id` (žádné `..`).
- **Session / cookie:** úprava `path` (případně názvu session) je **povinná** kvůli sdílené doméně.
- **Soubor s počátečním admin heslem:** krátká životnost, smazat po prvním admin loginu; oprávnění souboru jen pro uživatele procesu.
- **Job ID:** per-tenant SQLite; frontend musí volat SSE/API pod správným prefixem.
- **Signed URL:** token vázaný na tenantův secret; URL musí obsahovat tenant prefix.
- **Landing + `localStorage`:** užitečné pro UX; není náhrada za autorizaci — vždy ověřovat přístup na straně serveru podle tenant DB.

## 7. Rozhodnutí produktu (potvrzeno)

| Téma | Rozhodnutí |
|------|------------|
| URL bez tenant prefixu | Landing; redirect přes JavaScript podle tenanta v `localStorage`; tenant se do `localStorage` uloží po úspěšném přihlášení do daného tenantu |
| Kdo je tenant | Jen adresář `{dataRoot}/<tenant>/`, který **existuje**; jinak 404 |
| První přístup | Vytvoření admin uživatele s náhodným heslem; heslo uloženo do souboru v datové složce tenanta; **po prvním úspěšném přihlášení admina** se soubor se heslem **smaže** |
| Statika | **Společná** (bez tenant prefixu) |
| Cron a `generation-projector` | **Shodná logika pro všechny tenanty** (iterace všech tenantů) |
| phpLiteAdmin | **Úplně odstraněn** z repozitáře (`public/admin/phpliteadmin.php`, `tools/phpliteadmin.php`) |

---

*Dokument vytvořen jako součást plánovacího úkolu; implementace proběhne v samostatných commitech podle tohoto návrhu.*
