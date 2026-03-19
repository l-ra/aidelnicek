# Plán: Dvoufázové sestavení Docker image v GitHub Actions

## Současný stav

### Struktura buildu

1. **release.yml** – při push tagů `v*`:
   - Sestaví hlavní image (PHP/Apache)
   - Sestaví LLM worker image (Python/FastAPI)
   - Publikuje Helm chart
   - Nasazuje do produkce

2. **deploy.yml** – při push na `main`:
   - Sestaví staging image (oba obrazy)
   - Nasadí do staging prostředí

### Aktuální Dockerfile struktura

**Hlavní aplikace (Dockerfile):**
- Base: `php:8.2-apache`
- Závislosti: apt (SQLite, curl, PHP extensions), Composer, `composer install`
- Aplikace: `COPY . /var/www/html/`

**LLM worker (llm_worker/Dockerfile):**
- Base: `python:3.12-slim`
- Závislosti: `pip install -r requirements.txt`
- Aplikace: `COPY . .`

---

## Cíl změny

Oddělit vytváření image do dvou fází:
1. **Fáze 1 – Base image** – image se závislostmi (změny méně často)
2. **Fáze 2 – Cílový image** – sestavení aplikace nad base image (změny častěji)

Důvody: zrychlení buildů, menší zatížení registru, možnost manuální aktualizace base image.

---

## Návrh architektury

### Fáze 1: Base image

**Hlavní aplikace – base image:**
- `php:8.2-apache` + PHP extensions + Composer
- `composer install` (pouze na `composer.json` a `composer.lock` pokud existuje)
- Výstup: např. `ghcr.io/OWNER/REPO-base:VERSION` nebo `ghcr.io/OWNER/REPO-base:HASH`

**LLM worker – base image:**
- `python:3.12-slim` + `pip install -r requirements.txt`
- Výstup: např. `ghcr.io/OWNER/REPO-llm-worker-base:VERSION` nebo `ghcr.io/OWNER/REPO-llm-worker-base:HASH`

### Fáze 2: Cílový image

- Začíná od base image
- Přidá aplikační kód (`COPY`)
- Konfigurace Apache (pro hlavní app)
- CMD/EXPOSE

---

## Metoda detekce: kdy znovu sestavovat base image

### Princip

Base image má být znovu sestaven, jen když se změní vstupy fáze 1. Tyto vstupy je možné zachytit do **deterministického hashe**.

### Vstupy pro hash base image

**Hlavní aplikace:**
- `composer.json`
- `composer.lock` (pokud existuje)
- Příslušná část Dockerfile (řádky před `COPY` aplikace)
- Verze base image (`php:8.2-apache`)

**LLM worker:**
- `requirements.txt`
- Příslušná část Dockerfile (řádky před `COPY` aplikace)
- Verze base image (`python:3.12-slim`)

### Implementace hashe

1. **Výpočet hashe**: SHA-256 (nebo podobně) z obsahu výše uvedených souborů.
2. **Tag base image**: např. `base-<short-hash>` (např. `base-a1b2c3d`).
3. **Kontrola existence**: před buildem base image ověřit v GHCR, zda image s tagem `base-<hash>` už existuje.

### Varianta: content-addressable image

- Tag base image = hash vstupů (např. prvních 12 znaků SHA-256).
- Pokud image s tímto tagem existuje v registry → skip build.
- Pokud neexistuje → spustit build a push.

---

## Návrh GitHub Actions

### 1. Nový workflow: `build-base-images.yml`

**Účel:** Sestavení base images pro hlavní app a LLM worker.

**Triggers:**
- `workflow_dispatch` – manuální spuštění
- Volitelně: `push` na změny v `composer.json`, `composer.lock`, `requirements.txt`, příslušných Dockerfiles (jen pokud chcete automatický rebuild base při změně závislostí)

**Kroky:**
1. Checkout
2. Vypočítat hash závislostí (hlavní app, LLM worker)
3. Pro každý image:
   - Zkontrolovat, zda v GHCR existuje image s tagem `base-<hash>`
   - Pokud ano → skip (nebo uložit tag pro fázi 2)
   - Pokud ne → sestavit, push, uložit tag

**Outputs:**
- `main-base-tag`: tag base image hlavní aplikace
- `llm-worker-base-tag`: tag base image LLM workeru

### 2. Kontrola existence image v GHCR

**Možnosti:**

- **GHCR API**: `GET /orgs/{org}/packages/container/{package}/versions`
- **Docker manifest inspect**: `docker manifest inspect ghcr.io/...` – pokud existuje, nevrací chybu.
- **Skript**: curl na GHCR API s GITHUB_TOKEN.

**Příklad logiky:**
```
if image s tagem base-<hash> existuje v GHCR:
  použít existující
else:
  sestavit a pushnout
```

### 3. Úprava existujících workflows

**release.yml a deploy.yml:**

1. **Job „ensure-base-images“** (nebo reuse workflow):
   - Zavolá nebo spustí logiku build-base-images.
   - Zajistí, že před buildem cílových images existují příslušné base images.
   - Předá tagy base images do dalších jobů.

2. **Job „build-and-push-docker“ / „build-staging-image“**:
   - Použije `--build-arg BASE_IMAGE=ghcr.io/.../base:HASH` nebo multi-stage build s předpřipraveným base.
   - Cílový Dockerfile bude dědit od base image místo plného buildu od začátku.

### 4. Manuální vyvolání

- Workflow `build-base-images.yml` má `workflow_dispatch`.
- V GitHub UI: Actions → Build base images → Run workflow.
- Volitelné vstupy: např. „force rebuild“ pro ignorování kontroly a vždy nový build.

---

## Struktura souborů po změně

```
.github/workflows/
  build-base-images.yml    # NOVÝ – fáze 1, manuálně i automaticky
  release.yml             # ÚPRAVA – používá base images
  deploy.yml              # ÚPRAVA – používá base images

Dockerfile                # ÚPRAVA – multi-stage nebo FROM base
llm_worker/Dockerfile     # ÚPRAVA – multi-stage nebo FROM base
```

---

## Varianta: composite action pro kontrolu base image

Pro znovupoužitelnost lze vytvořit **composite action** např. `actions/check-base-image`:

**Vstupy:**
- `registry`, `image-name`, `tag`
- `dependency-files` (JSON nebo seznam cest)

**Výstupy:**
- `exists`: true/false
- `hash`: spočítaný hash

**Použití:** V workflow před buildem base image zavolat action, podle `exists` rozhodnout o build vs skip.

---

## Pořadí implementace (doporučené)

1. **Vytvořit skript pro výpočet hashe** – např. `scripts/compute-base-hash.sh`.
2. **Vytvořit workflow `build-base-images.yml`** – s `workflow_dispatch`, logikou kontroly existence a buildem.
3. **Rozštěpit Dockerfiles** – base vs aplikace (multi-stage build).
4. **Upravit release.yml** – zajistit base images, používat je při buildování.
5. **Upravit deploy.yml** – stejně jako release.
6. **Přidat composite action** (volitelné) – pro znovupoužitelnost kontroly existence.

---

## Rizika a omezení

- **Composer**: `composer install` potřebuje `composer.json` a případně `composer.lock`. Do base fáze je nutné zahrnout alespoň `composer.json`; bez `composer.lock` může být build méně reprodukovatelný.
- **GHCR API rate limiting**: Při častých kontrolách existence může být potřeba cachování nebo úsporné volání API.
- **Správa tagů**: Staré base images s různými hashi se budou hromadit v registru – vhodné je nastavení retention policy pro `*-base:*` tagy.

---

## Shrnutí

| Komponenta                    | Změna |
|------------------------------|-------|
| `build-base-images.yml`      | NOVÝ – base images, workflow_dispatch, kontrola existence |
| `release.yml`                | ÚPRAVA – závislost na base, použití base images |
| `deploy.yml`                 | ÚPRAVA – závislost na base, použití base images |
| `Dockerfile`                 | ÚPRAVA – multi-stage / FROM base |
| `llm_worker/Dockerfile`      | ÚPRAVA – multi-stage / FROM base |
| Skript / action pro hash     | NOVÝ – výpočet a kontrola existence v GHCR |

Tento plán je připraven k implementaci po schválení.
