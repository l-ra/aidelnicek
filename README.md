# aidelnicek

Webová aplikace pro domácnost, která pomáhá se zdravým stravováním prostřednictvím
automaticky generovaných týdenních jídelníčků a sdíleného nákupního seznamu.

---

## 1. Koncept

Aplikace obsluhuje jednu **domácnost** — skupinu uživatelů, kteří sdílejí společný
nákupní seznam. Každý uživatel má vlastní individuální jídelníček přizpůsobený jeho
profilu (pohlaví, věk, postava) a historickým preferencím.

Každý týden aplikace automaticky vygeneruje jídelníčky pro všechny uživatele a k nim
odpovídající sdílený nákupní seznam. Každý večer odešle uživatelům web push notifikaci
s odkazem na jídelníček na následující den.

---

## 2. Uživatelé a domácnost

- Aplikace podporuje **libovolný počet uživatelů** v rámci jedné domácnosti.
- Jeden uživatel má roli **správce** (zakládá domácnost, spravuje uživatele, generuje pozvánky).
- Každý uživatel při registraci / v profilu zadává:
  - **Jméno** (zobrazované)
  - **Pohlaví**
  - **Věk**
  - **Postava** (drobná / průměrná / robustní)
  - **Výška** (cm)
  - **Váha** (kg)
  - **Dietní omezení / alergie** (volitelně)
  - **Cíl jídelníčku** (volný text — předán do LLM)
- Autentizace: jméno/e-mail + heslo, session-based.
- **Registrace** je možná výhradně přes zvací odkaz vygenerovaný správcem.

---

## 3. Jídelníček

### 3.1 Struktura

- Jídelníček je **týdenní**, individuální pro každého uživatele.
- Každý den obsahuje **5 jídel**:
  1. Snídaně
  2. Dopolední svačina
  3. Oběd
  4. Odpolední svačina
  5. Večeře
- U každého termínu jídla jsou **2 alternativy** — uživatel si vybere jednu.
- Každé jídlo obsahuje: název, krátký popis, hlavní ingredience.

### 3.2 Zobrazení

- **Denní pohled** (výchozí) — zobrazí jídla daného dne s alternativami.
- **Týdenní přehled** — kompaktní tabulka celého týdne s možností proklikat den.

### 3.3 Interakce

- U každého jídla checkbox **„snězeno"**.
- Výběr alternativy — uživatel označí, kterou variantu si zvolil.
- Historické volby se ukládají a slouží jako vstup pro budoucí generování.

---

## 4. Učení z historie

- Aplikace sleduje, které alternativy si uživatel volí a které označí jako snězené.
- Při generování nového jídelníčku se zohledňují:
  - **Oblíbená jídla** — jídla, která uživatel opakovaně volí.
  - **Odmítaná jídla** — jídla, která uživatel soustavně nevybírá.
  - **Pestrost** — neopakovat stejná jídla příliš často.
- Historie preferencí se předává jako kontext AI generátoru.

---

## 5. Nákupní seznam

- **Jeden sdílený seznam** pro celou domácnost.
- Data jsou v databázi vždy oddělená podle `week_id` (tabulka `shopping_list_items`).
- Automaticky se generuje z jídelníčků všech uživatelů pro **konkrétní týden**.
- Položky: název, množství, jednotka, kategorie (volitelně).
- Interakce:
  - **Odškrtnutí** položky jako nakoupené (viditelné všem uživatelům).
  - **Ruční přidání** položky.
  - **Odebrání** položky.
- Agregace: pokud více jídelníčků vyžaduje stejnou ingredienci, množství se sčítají.

### 5.1 Co přesně se agreguje

- Agregace probíhá pouze **uvnitř jednoho týdne**.
- Zdroj jsou vybraná jídla (`meal_plans`) všech uživatelů:
  - preferuje se varianta `is_chosen = 1`,
  - když uživatel v daném slotu nic nevybral, použije se fallback `alternative = 1`.
- Ingredience se deduplikují podle klíče `lowercase(name) + "|" + lowercase(unit)`.
- U shodných dvojic `name + unit` se sčítá `quantity`.

### 5.2 Proč v DB vidíš dva týdny, ale v UI jen jeden seznam

- V DB je normální mít položky pro více týdnů (historie i budoucí týdny).
- Obrazovka `/shopping` ale záměrně načítá jen **aktuální týden** (`MealPlan::getOrCreateCurrentWeek()`).
- Proto UI ukazuje jeden seznam; není to konsolidace napříč týdny.
- Konsolidace znamená: „sloučit položky všech uživatelů pro stejný týden“, ne „sloučit všechny týdny dohromady“.

---

## 6. Notifikace (Web Push)

- Každý večer (konfigurovatelný čas, výchozí 20:00) obdrží každý uživatel
  **web push notifikaci** s odkazem na svůj jídelníček na následující den.
- Implementace: Service Worker + Web Push API (VAPID klíče).
- Uživatel si v prohlížeči povolí příjem notifikací.
- Notifikace fungují i na mobilních zařízeních (Android Chrome, iOS Safari 16.4+).

---

## 7. Automatizace (plánované úlohy)

| Úloha | Frekvence | Popis |
|-------|-----------|-------|
| Generování jídelníčků | 1× týdně (neděle večer) | AI vygeneruje jídelníčky pro všechny uživatele + nákupní seznam |
| Denní notifikace | Denně (20:00) | Web push s odkazem na zítřejší jídelníček |

---

## 8. Datový model (SQLite3)

```
users
  id              INTEGER PRIMARY KEY
  name            TEXT NOT NULL
  email           TEXT UNIQUE NOT NULL
  password_hash   TEXT NOT NULL
  gender          TEXT                    -- male / female / other
  age             INTEGER
  body_type       TEXT                    -- slim / average / large
  dietary_notes   TEXT                    -- alergie, omezení (volný text)
  height          INTEGER                 -- výška v cm
  weight          REAL                    -- váha v kg
  diet_goal       TEXT                    -- cíl jídelníčku (volný text, předán do LLM)
  is_admin        INTEGER DEFAULT 0
  push_subscription TEXT                  -- JSON web push subscription
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP

weeks
  id              INTEGER PRIMARY KEY
  week_number     INTEGER NOT NULL
  year            INTEGER NOT NULL
  generated_at    DATETIME
  UNIQUE(week_number, year)

meal_plans
  id              INTEGER PRIMARY KEY
  user_id         INTEGER NOT NULL        → users(id)
  week_id         INTEGER NOT NULL        → weeks(id)
  day_of_week     INTEGER NOT NULL        -- 1=Po .. 7=Ne
  meal_type       TEXT NOT NULL            -- breakfast / snack_am / lunch / snack_pm / dinner
  alternative     INTEGER NOT NULL         -- 1 nebo 2
  meal_name       TEXT NOT NULL
  description     TEXT
  ingredients     TEXT                     -- JSON pole ingrediencí
  is_chosen       INTEGER DEFAULT 0        -- uživatel zvolil tuto alternativu
  is_eaten        INTEGER DEFAULT 0
  UNIQUE(user_id, week_id, day_of_week, meal_type, alternative)

shopping_list_items
  id              INTEGER PRIMARY KEY
  week_id         INTEGER NOT NULL        → weeks(id)
  name            TEXT NOT NULL
  quantity        REAL
  unit            TEXT
  category        TEXT
  is_purchased    INTEGER DEFAULT 0
  purchased_by    INTEGER                 → users(id)
  added_manually  INTEGER DEFAULT 0
  added_by        INTEGER                 → users(id)

meal_history (materializovaný pohled na preference)
  id              INTEGER PRIMARY KEY
  user_id         INTEGER NOT NULL        → users(id)
  meal_name       TEXT NOT NULL
  times_offered   INTEGER DEFAULT 0
  times_chosen    INTEGER DEFAULT 0
  times_eaten     INTEGER DEFAULT 0
  last_offered    DATETIME

notifications_log
  id              INTEGER PRIMARY KEY
  user_id         INTEGER NOT NULL        → users(id)
  sent_at         DATETIME
  type            TEXT                     -- daily_plan / weekly_generated
  status          TEXT                     -- sent / failed / clicked

settings
  key             TEXT PRIMARY KEY
  value           TEXT
```

---

## 9. Technologie

| Vrstva | Technologie |
|--------|------------|
| Backend | PHP 8.x |
| Databáze | SQLite3 (lokální soubor, WAL mode) |
| Frontend | HTML + CSS (responsivní, mobile-first) + vanilla JS |
| Web Push | Service Worker + Push API + VAPID (knihovna `web-push-php`) |
| AI generování | Python FastAPI sidecar s AsyncOpenAI streaming — viz [docs/M7_LLM_STREAMING.md](docs/M7_LLM_STREAMING.md) |
| Bezpečnost | HMAC-SHA256 podepsané pozvánky, session-based auth, CSRF ochrana — viz [docs/SECURITY.md](docs/SECURITY.md) |
| Deployment | Kubernetes (Helm) + GitHub Actions — viz [docs/GITHUB_SECRETS.md](docs/GITHUB_SECRETS.md) |

---

## 10. Struktura projektu

```
/
├── public/
│   ├── index.php              -- entry point / router
│   ├── sw.js                  -- service worker pro push notifikace
│   ├── manifest.json          -- PWA manifest
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── app.js
├── src/
│   ├── Database.php           -- SQLite connection + migrace + bootstrap admin uživatele
│   ├── Auth.php               -- autentizace, sessions
│   ├── User.php               -- správa uživatelů a profilů
│   ├── Invite.php             -- HMAC-SHA256 pozvánky pro registraci
│   ├── MealPlan.php           -- logika jídelníčků
│   ├── MealHistory.php        -- sledování preferencí
│   ├── MealGenerator.php      -- sestavení promptů + volání LLM workeru
│   ├── ShoppingList.php       -- logika nákupního seznamu
│   ├── Llm/                   -- LLM abstrakce (zachována pro budoucí použití)
│   └── Router.php             -- routing
├── llm_worker/                -- Python FastAPI sidecar pro LLM
│   ├── main.py                -- FastAPI app (POST /generate, POST /complete, GET /health)
│   ├── generator.py           -- async streaming + sync complete + seed meal_plans
│   ├── logger.py              -- LLM call logging do per-day SQLite souborů
│   ├── database.py            -- aiosqlite helpers (WAL mode)
│   ├── requirements.txt
│   └── Dockerfile
├── templates/
│   ├── layout.php
│   ├── login.php
│   ├── register.php           -- rozšířeno o výšku, váhu, cíl; vyžaduje pozvánku
│   ├── profile.php
│   ├── dashboard.php
│   ├── day_plan.php
│   ├── week_plan.php
│   ├── shopping_list.php
│   ├── admin_generate.php     -- streaming admin UI pro generování jídelníčků
│   └── admin_invite.php       -- admin UI pro generování pozvánek
├── cron/
│   ├── generate_weekly.php    -- týdenní generování (přes LLM worker)
│   └── send_notifications.php -- denní notifikace
├── prompts/                   -- šablony LLM promptů
├── data/
│   ├── .gitkeep               -- SQLite DB se vytvoří automaticky
│   └── invite_secret.key      -- HMAC tajemství (auto-generováno, není v gitu)
├── helm/aidelnicek/           -- Kubernetes Helm chart
├── docs/                      -- implementační dokumentace
├── composer.json
└── README.md
```

---

## 11. Obrazovky

1. **Login** — přihlášení (registrace vyžaduje zvací odkaz).
2. **Registrace (s pozvánkou)** — registrace po kliknutí na zvací odkaz; obsahuje všechny profilové údaje.
3. **Profil** — úprava jména, pohlaví, věku, postavy, výšky, váhy, dietních omezení, cíle, push notifikací.
4. **Dashboard** — dnešní jídelníček (5 jídel × 2 alternativy), rychlý odkaz na nákupní seznam.
5. **Denní pohled** — detail jídel daného dne, výběr alternativy, odškrtávání.
6. **Týdenní přehled** — kompaktní tabulka celého týdne.
7. **Nákupní seznam** — sdílený seznam, filtr nakoupené/nenakoupené, přidávání položek.
8. **Administrace** — přehled admin funkcí, generování jídelníčku, LLM test, logy, pozvánky.

---

## 12. Milníky vývoje

| Fáze | Rozsah |
|------|--------|
| **M1 — Kostra** | Struktura projektu, SQLite schéma, migrace, router, layout, CSS základ |
| **M2 — Uživatelé** | Registrace, login, profil (pohlaví, věk, postava), session management |
| **M3 — Jídelníček** | Zobrazení denní/týdenní, odškrtávání, výběr alternativ, ukládání historie |
| **M4 — Nákupní seznam** | Sdílený seznam, odškrtávání, ruční přidávání, agregace položek |
| **M5 — AI generátor** | Napojení na LLM (PHP curl), generování jídelníčků dle profilu + historie |
| **M6 — Web Push** | Service worker, VAPID, subscription management, denní notifikace |
| **M7 — LLM streaming** | Python FastAPI sidecar, OpenAI streaming, SSE progress v admin UI — viz [docs/M7_LLM_STREAMING.md](docs/M7_LLM_STREAMING.md) |
| **M8 — Bezpečnost a správa** | Unifikace LLM přes worker, logování, bootstrap admin, zvací systém, rozšířená registrace — viz [docs/SECURITY.md](docs/SECURITY.md) |
