# Multitenantnost datového úložiště (analýza a návrh)

Tento dokument shrnuje analýzu požadavku na oddělení dat více domácností (tenantů) pod jednou nasazenou instancí aplikace a návrh řešení. **Implementace** odpovídající tomuto dokumentu je v kódu: tenant z prvního segmentu URL, `data/<tenant>/`, landing s výběrem / přesměrováním podle `localStorage`, `Router` s `urlBasePath`, session a cookie vázané na tenant, `Url` pro prefixované odkazy, LLM worker s `tenant_id`, projector a cron iterují všechny tenanty. **Automatická migrace** plochého rozložení souborů v `data/` do jedné podsložky tenanta **v aplikaci není** — data musí být od začátku (nebo ručně) v `{dataRoot}/<tenant>/`.

## 1. Požadavek (shrnutí)

- Jedna kódová báze a jeden běh aplikace obsluhuje více tenantů.
- Tenant je adresován **prefixem cesty** na stejné doméně, např. `https://host/dplusk/…` a `https://host/vitr/…`.
- Data každého tenantu jsou v **izolované složce** pod kořenem dat: `/data/<tenant>/` (v repozitáři relativně `{projectRoot}/data/<tenant>/`).
- **Router** detekuje tenant z URL a následně se používá odpovídající datová složka (zejména SQLite a související soubory).
- Volání **LLM workeru** musí nést identifikaci tenantu, aby worker zapisoval do správné databáze / správného datového stromu.

Poznámka k formulaci „kód aplikace neměnit uvnitř“: v praxi je nutné **upravit vstupní vrstvu** (bootstrap, router, generování URL, volání workeru) a místa, která dnes mají cestu k `data/` natvrdo. Logika domény (modely, šablony jako takové) může zůstat beze změny, pokud veškerý přístup k DB a souborům projde společným „tenant-aware“ kontextem.

## 2. Implementovaný stav v repozitáři

### 2.1 PHP — vstup, tenant a databáze

- `public/index.php` z prvního segmentu URL zjistí tenant, pokud je platný slug **a** existuje `{projectRoot}/data/<slug>/`. Jinak (neplatný segment) vrací **404**. Kořen `/` bez tenantu zobrazí **landing** (`templates/landing.php`) a ukončí request; aplikace pod prefixem pokračuje až po výběru existujícího tenanta.
- `TenantContext` drží aktivní slug; `Database::init($projectRoot, ?string $tenantSlug)` pro konkrétního tenanta používá `{dataRoot}/<tenant>/aidelnicek.sqlite` (singleton PDO resetovaný při změně tenanta). Volání bez tenanta připraví jen kořen `data/` (landing / CLI).
- `Router($projectRoot, $urlBasePath)` — u tenantovaných requestů je `$urlBasePath = '/<tenant>'`.
- `Url::u()`, `Url::tenantLocation()` a šablony generují cesty **s tenant prefixem** tam, kde jde o aplikaci.
- `Auth::configureTenantSession()` nastaví `session_name` a `session.cookie_path` na `/<tenant>/`; cookie `remember_token` má stejný `path` → **oddělené přihlášení** mezi tenanty na jedné doméně.

### 2.2 Soubory pod `data/<tenant>/`

Per tenant mimo jiného: `aidelnicek.sqlite`, `invite_secret.key`, `llm_*.db`, případně `initial-admin-password.txt` (bootstrap admina).

### 2.3 LLM worker (Python)

- Požadavky nesou `tenant_id`; worker sestaví cestu k DB pod `DATA_ROOT` / tenant složku (viz implementace v `llm_worker/`).
- `GenerationJobService` doplňuje `tenant_id` z `TenantContext`.

### 2.4 Kubernetes / Docker

- Stejný datový svazek pro PHP (`…/data`) a worker (`/data`); soubory tenanta musí být na konzistentních cestách v obou kontejnerech (`…/data/<tenant>/…`).

### 2.5 Procesy na pozadí

- `bin/generation-projector.php` a `cron/generate_weekly.php` volají `Tenant::listTenantSlugs()` a pro **každého** tenanta nastaví kontext (`TenantContext::initFromSlug`, `Database::init`) a spustí stávající logiku.

**Poznámka:** nástroj phpLiteAdmin byl z repozitáře odstraněn (viz sekce 7).

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

## 4. Checklist implementace (stav)

| Oblast | Stav | Poznámka |
|--------|------|----------|
| Vstupní bod | Hotovo | `public/index.php` — tenant z URL, 404 pro neexistující / neplatný segment |
| Landing `/` | Hotovo | `templates/landing.php`, `localStorage` (`aidelnicek_tenant`), zápis po přihlášení v `layout.php` |
| `Database` | Hotovo | `{data}/<tenant>/aidelnicek.sqlite`; bootstrap admin + soubor s heslem (viz `Database.php`) |
| `Router` | Hotovo | `Router($projectRoot, '/<tenant>')` |
| Redirecty a odkazy | Hotovo | `Url::u()`, `Url::tenantLocation()` |
| Session a cookie | Hotovo | `Auth::configureTenantSession()` — path a název session per tenant |
| `Invite` / `ShoppingListExport` / LLM logy | Hotovo | Cesty pod `{data}/<tenant>/` |
| `GenerationJobService` / worker | Hotovo | `tenant_id` v payloadu, worker podle `DATA_ROOT` |
| Statika | Hotovo | Společné `/css`, `/js` bez tenant prefixu; rezervované slugy v `Tenant::RESERVED_SLUGS` |
| Helm / Ingress | Konfigurace nasazení | Statika vs aplikace s tenant segmentem — dle prostředí |
| Projector a cron | Hotovo | Iterace `Tenant::listTenantSlugs()` |

## 5. Data a „migrace“ z plochého rozložení

- Aplikace **nepřesouvá** soubory z plochého `{projectRoot}/data/*` do podsložky tenanta. Očekává se struktura `{dataRoot}/<tenant>/…` vytvořená provisioningem (nebo ručně).
- Máte-li starší instalaci s databází a soubory přímo v `data/` (bez podsložky tenanta), je potřeba obsah **jednorázově přesunout** do zvolené složky tenanta (např. `data/moje-domacnost/`) mimo běh aplikace — bez automatické migrace v kódu.

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

## 8. Verze v zápatí (git hash)

Řádek verze v `templates/layout.php` čte `Version::get()`, která v tomto pořadí použije:

1. Soubor `{projectRoot}/version.json` (např. generovaný při Docker buildu z `GIT_SHA` / `BUILD_DATE`).
2. Proměnné prostředí: `AIDELNICEK_GIT_SHA`, `GIT_SHA`, `GITHUB_SHA`, `COMMIT_SHA`, `VCS_REF` (volitelně `BUILD_DATE`, `AIDELNICEK_BUILD_DATE` nebo `SOURCE_DATE_EPOCH` pro datum sestavení).
3. Je-li v nasazení dostupný adresář `.git`, krátký hash a datum posledního commitu přes `git`.

V produkčním image často chybí `.git` i `version.json`; pro zobrazení hashe v zápatí tedy nastavte v runtime např. `GIT_SHA` z CI.

---

*Dokument popisuje návrh i aktuální implementaci; checklist v sekci 4 odráží stav v repozitáři.*
