# M7 — Python LLM Worker: Streaming generování jídelníčků

## Kontext a motivace

Původní implementace (M5) volá OpenAI `/chat/completions` synchronně z PHP přes `curl` s timeoutem
90 sekund. U modelů s delším „přemýšlením" (reasoning models: o1, o3, o4-mini) může generování
trvat i několik minut — synchronní REST volání z PHP toto spolehlivě nezvládne:

- Apache worker blokuje po celou dobu generování (žádný jiný požadavek nemůže obsadit slot)
- Ingress / load balancer timeoutuje dřív než model odpoví
- Uživatel nevidí žádný průběh — jen točící se kolečko a pak výsledek nebo chyba

**Řešení:** Generování přebírá dedikovaný **Python FastAPI sidecar** s `AsyncOpenAI` streamingem.
PHP pouze spustí job a okamžitě vrátí odpověď. Průběh je vidět v admin UI v reálném čase
prostřednictvím **Server-Sent Events (SSE)**.

---

## Přehled změněných souborů

| Akce     | Soubor                                          |
|----------|-------------------------------------------------|
| Vytvořit | `llm_worker/main.py`                            |
| Vytvořit | `llm_worker/generator.py`                       |
| Vytvořit | `llm_worker/database.py`                        |
| Vytvořit | `llm_worker/requirements.txt`                   |
| Vytvořit | `llm_worker/Dockerfile`                         |
| Vytvořit | `templates/admin_generate.php`                  |
| Upravit  | `src/Database.php`                              |
| Upravit  | `src/MealGenerator.php`                         |
| Upravit  | `public/index.php`                              |
| Upravit  | `templates/admin.php`                           |
| Upravit  | `helm/aidelnicek/templates/deployment.yaml`     |
| Upravit  | `helm/aidelnicek/values.yaml`                   |
| Upravit  | `.github/workflows/deploy.yml`                  |
| Upravit  | `.github/workflows/release.yml`                 |

---

## 1. Architektura

```
Browser (EventSource)
        │
        │  GET /admin/llm-stream?job_id=N   (SSE, text/event-stream)
        ▼
┌────────────────────────────────────────────┐
│  PHP App (Apache, port 80)                 │
│  POST /admin/llm-generate                  │
│    → sestaví prompty (MealGenerator)       │
│    → zavolá Python worker (curl, 5 s TO)   │
│    → vrátí { job_id }                      │
│                                            │
│  GET /admin/llm-stream?job_id=N            │
│    → SSE endpoint, polluje SQLite 400 ms   │
│    → forwarduje nové chunky jako SSE event │
└──────────────┬─────────────────────────────┘
               │  POST localhost:8001/generate
               │  (stejný K8s pod, sdílený síťový ns)
               ▼
┌────────────────────────────────────────────┐    ┌──────────────────────┐
│  Python LLM Worker (FastAPI, port 8001)    │───▶│  OpenAI Streaming    │
│  Sidecar kontejner ve stejném K8s podu     │◀───│  chat/completions    │
│                                            │    │  stream=True         │
│  async task stream_and_store():            │    └──────────────────────┘
│    1. UPDATE status='running'              │
│    2. stream OpenAI chunky                 │
│    3. každý chunk: UPDATE progress_text    │
│       (atomická SQL || konkatenace)        │
│    4. parse JSON → INSERT meal_plans       │
│    5. UPDATE status='done'|'error'         │
└────────────────────┬───────────────────────┘
                     │  aiosqlite (WAL mode)
                     ▼
┌────────────────────────────────────────────┐
│  SQLite: data/aidelnicek.sqlite            │
│  tabulka: generation_jobs                  │
│  (sdílené PVC — /var/www/html/data v PHP,  │
│   /data v Pythonu)                         │
└────────────────────────────────────────────┘
```

Klíčový princip: **browser komunikuje výhradně s PHP** (autentizace a CSRF zůstávají
centralizované). Python worker je schovaný za PHP a nikdy není dostupný zvenčí podu.

---

## 2. Nová tabulka: `generation_jobs`

Přidána migrací v `src/Database.php` (migrace č. 14, označení M7).

```sql
CREATE TABLE IF NOT EXISTS generation_jobs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL,
    week_id       INTEGER NOT NULL,
    status        TEXT    NOT NULL DEFAULT 'pending',
                  -- pending | running | done | error
    progress_text TEXT    NOT NULL DEFAULT '',
                  -- akumulovaný raw text z OpenAI streamu
    chunk_count   INTEGER NOT NULL DEFAULT 0,
                  -- PHP porovnává tento čítač, ne délku textu
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at    DATETIME,
    finished_at   DATETIME,
    error_message TEXT
);
```

`chunk_count` slouží jako levný čítač pro PHP polling — PHP zjistí, že přibyl nový obsah,
aniž by porovnával délku celého `progress_text` při každém dotazu.

### WAL mode

`src/Database.php` nyní nastavuje `PRAGMA journal_mode=WAL` při každém otevření spojení.
WAL (Write-Ahead Log) umožňuje souběžné čtení z PHP zatímco Python zapisuje do stejného
souboru. Bez WAL by zápis Pythonu blokoval čtení PHP SSE endpointu.

`llm_worker/database.py` nastavuje WAL mode taktéž — zajišťuje správné fungování i při
prvním připojení Pythonu (dříve než PHP inicializuje DB).

---

## 3. Python LLM Worker (`llm_worker/`)

### Závislosti (`requirements.txt`)

```
fastapi==0.115.6
uvicorn[standard]==0.34.0
openai==1.65.0
aiosqlite==0.21.0
```

### `main.py` — FastAPI aplikace

Naslouchá na portu 8001 (interní, nepublikovaný mimo pod).

**`POST /generate`**

```
Vstup (JSON body):
  user_id               int
  week_id               int
  system_prompt         str
  user_prompt           str
  model                 str   (výchozí: OPENAI_MODEL env)
  temperature           float (výchozí: 0.8)
  max_completion_tokens int   (výchozí: 4096)
  force                 bool  (výchozí: false)

Výstup:
  { "job_id": N }
```

Endpoint okamžitě vytvoří záznam v `generation_jobs` a spustí
`asyncio.create_task(stream_and_store(...))` na pozadí. PHP dostane `job_id` zpět
do ~100 ms — bez čekání na OpenAI.

**`POST /complete`** (přidáno v M8)

Synchronní (nestreaming) LLM completion pro admin LLM-test sandbox a libovolné PHP klienty.
Zavolá OpenAI API bez streamingu a vrátí okamžitou odpověď.

```
Vstup (JSON body):
  system_prompt         str   (volitelný)
  user_prompt           str
  model                 str   (výchozí: OPENAI_MODEL env)
  temperature           float (výchozí: 0.7)
  max_completion_tokens int   (výchozí: 1024)
  user_id               int   (volitelný, pro logování)

Výstup:
  { "response": "...", "model": "...", "provider": "openai", "tokens_in": N, "tokens_out": N }
```

Každé volání se loguje do per-day SQLite souboru (`data/llm_YYYY-MM-DD.db`).

**`GET /health`**

Vrátí `{"status":"ok","db_path":"...","model":"..."}` — používá K8s liveness/readiness sondy.

### `generator.py` — async streaming

```python
async def stream_and_store(job_id, user_id, week_id, system_prompt,
                            user_prompt, model, temperature,
                            max_completion_tokens, force):
    conn = await open_db()
    await mark_running(conn, job_id)

    client = AsyncOpenAI(api_key=..., base_url=...)
    accumulated = ""

    async with client.chat.completions.stream(
        model=model,
        messages=[{"role": "system", ...}, {"role": "user", ...}],
        temperature=temperature,
        max_completion_tokens=max_completion_tokens,
    ) as stream:
        async for chunk in stream:
            delta = chunk.choices[0].delta.content
            if delta:
                accumulated += delta
                await append_chunk(conn, job_id, delta)

    days = _parse_response(accumulated)  # retry při chybném JSON (stejná logika jako PHP)
    await seed_meal_plans(conn, user_id, week_id, days, force)
    await mark_done(conn, job_id)
```

`_parse_response()` zrcadlí PHP `MealGenerator::parseResponse()` — stripuje markdown code
fences, ořízne na `{...}`, parsuje JSON, validuje přítomnost a počet `days`.

`seed_meal_plans()` zrcadlí PHP `MealGenerator::seedFromLlm()` — iteruje 7 dní × 5 typů
jídel × 2 alternativy, vkládá do `meal_plans` (INSERT OR IGNORE), volá `_record_meal_offer()`
pro aktualizaci `meal_history`.

### `database.py` — SQLite async helpers

```python
async def append_chunk(conn, job_id, text):
    # Atomická SQL || konkatenace — žádný read-modify-write race
    await conn.execute(
        "UPDATE generation_jobs "
        "SET progress_text = progress_text || ?, chunk_count = chunk_count + 1 "
        "WHERE id=?",
        (text, job_id),
    )
    await conn.commit()
```

Použití SQL `||` (string concatenation) je klíčové — zabraňuje race condition, kdy by
Python přečetl starý `progress_text`, přidal chunk a zapsal zpět, přičemž PHP mezitím
přidalo záznam do jiné části DB.

---

## 4. PHP backend

### `src/MealGenerator.php` — nová veřejná metoda

```php
public static function getPromptsForWeek(int $userId, int $weekId): array
```

Načte uživatele a týden z DB, deleguje na existující privátní `buildPrompts()`.
Vrátí `[systemPrompt, userPrompt]`. Tím zůstává veškerá prompt logika v PHP —
Python dostane hotové prompty a stará se pouze o komunikaci s OpenAI a ukládání výsledku.

### `public/index.php` — nové routy

**`POST /admin/llm-generate`** (pouze admin, CSRF chráněno)

1. Akceptuje `user_id` + `week_number`/`year` (nebo `week_id`)
2. Pokud chybí `week_id`, rozřeší ho z `week_number`+`year` (vytvoří záznam v `weeks` pokud neexistuje)
3. Zavolá `MealGenerator::getPromptsForWeek()` — sestaví prompty
4. Curl POST na `http://localhost:8001/generate` (timeout 5 s — jen trigger)
5. Vrátí `{"ok": true, "job_id": N}` nebo `{"ok": false, "error": "..."}`

**`GET /admin/llm-stream`** (pouze admin, job_id jako query param)

SSE endpoint pro live přenos průběhu generování do prohlížeče:

```
1. Disable output buffering
2. Odeslat SSE hlavičky (Content-Type: text/event-stream, X-Accel-Buffering: no)
3. set_time_limit(0)
4. Smyčka (max 10 minut):
   a. SELECT status, progress_text, chunk_count FROM generation_jobs WHERE id=?
   b. Pokud chunk_count > lastChunkCount:
        newText = substr(progress_text, lastTextLen)
        echo "data: {\"type\":\"chunk\",\"text\":\"...\",\"count\":N}\n\n"
        flush()
   c. Pokud status IN ('done','error'):
        echo "data: {\"type\":\"done\",\"status\":\"...\",\"error\":...}\n\n"
        flush()
        break
   d. Každých ~10 s: echo ": keepalive\n\n"  — zabraňuje timeout proxy
   e. usleep(400_000)  — 400 ms polling interval
```

---

## 5. Admin UI (`templates/admin_generate.php`)

### Rozložení stránky

```
┌─────────────────────────────────────────────┐
│  Generování jídelníčku přes AI              │
├──────────────────┬──────────────────────────┤
│  Uživatel: [▼]   │  { "days": [            │
│  Týden: [11]     │    { "day": 1,          │
│  Rok:   [2026]   │      "meals": {         │
│  [✓] Přepsat     │        "breakfast": {   │  ← live
│                  │          "alt1": {      │    streaming
│  [▶ Spustit]     │            "name":      │    výstup
│  ● Stav: ...     │            "Ovesná…    │
│                  │  ...                    │
│  Job: 42         │                         │
│  Stav: running   │                         │
│  Čas:  00:23     │                         │
│  Chunků: 87      │                         │
│                  │  [Kopírovat]            │
│  [→ Jídelníček]  │                         │
└──────────────────┴─────────────────────────┘
```

### JavaScript flow

```javascript
// 1. Klik na "Spustit"
POST /admin/llm-generate  { user_id, week_number, year, force, csrf_token }
→ { ok: true, job_id: 42 }

// 2. Otevřít SSE spojení
const es = new EventSource('/admin/llm-stream?job_id=42')

es.onmessage = (evt) => {
    const msg = JSON.parse(evt.data)

    if (msg.type === 'chunk') {
        outputEl.textContent += msg.text   // live append
        outputEl.scrollTop = outputEl.scrollHeight
        infoChunks.textContent = msg.count
    }

    if (msg.type === 'done') {
        es.close()
        if (msg.status === 'done') {
            planLink.href = `/plan/week?week=${week}&year=${year}`
            doneActions.hidden = false   // zobrazí tlačítko → jídelníček
        } else {
            showError(msg.error)
        }
    }
}
```

---

## 6. Kubernetes deployment

### Sidecar v `helm/aidelnicek/templates/deployment.yaml`

```yaml
containers:
  - name: aidelnicek          # stávající PHP kontejner
    # ...
    volumeMounts:
      - name: data
        mountPath: /var/www/html/data

  - name: llm-worker           # nový Python sidecar
    image: "ghcr.io/l-ra/aidelnicek-llm-worker:{{ tag }}"
    envFrom:
      - secretRef:
          name: aidelnicek-llm   # stejný K8s Secret jako PHP kontejner
          optional: true
    env:
      - name: DB_PATH
        value: /data/aidelnicek.sqlite
    ports:
      - containerPort: 8001
    resources:
      requests: { cpu: 100m, memory: 128Mi }
      limits:   { cpu: 500m, memory: 256Mi }
    volumeMounts:
      - name: data
        mountPath: /data          # Python vidí SQLite jako /data/aidelnicek.sqlite
    livenessProbe:
      httpGet: { path: /health, port: 8001 }
      initialDelaySeconds: 10
      periodSeconds: 30
    readinessProbe:
      httpGet: { path: /health, port: 8001 }
      initialDelaySeconds: 5
      periodSeconds: 10
```

Oba kontejnery sdílejí:
- **Síťový namespace** — PHP volá `localhost:8001`
- **PVC volume** — PHP zapisuje do `/var/www/html/data/`, Python čte/zapisuje do `/data/`
  (obě cesty míří na stejný PVC mount)

### `helm/aidelnicek/values.yaml` — nová sekce

```yaml
llmWorker:
  image:
    repository: ghcr.io/l-ra/aidelnicek-llm-worker
    pullPolicy: IfNotPresent
    tag: ""
  resources:
    requests: { cpu: 100m, memory: 128Mi }
    limits:   { cpu: 500m, memory: 256Mi }
```

---

## 7. CI/CD (`deploy.yml`, `release.yml`)

Do stávajícího jobu `build-staging-image` jsou přidány dva kroky:

```yaml
- name: Extract metadata for LLM worker (staging)
  id: meta-worker
  uses: docker/metadata-action@v5
  with:
    images: ghcr.io/${{ github.repository }}-llm-worker
    tags: |
      type=raw,value=staging-${{ github.sha }}
      type=raw,value=staging-latest

- name: Build and push LLM worker Docker image
  uses: docker/build-push-action@v5
  with:
    context: ./llm_worker   # ← buildcontext je llm_worker/
    push: true
    platforms: linux/amd64,linux/arm64
    tags: ${{ steps.meta-worker.outputs.tags }}
```

Helm deploy krok dostane nový parametr:
```
--set llmWorker.image.tag=staging-${{ github.sha }}
```

Totéž platí pro `release.yml` s `type=semver` tagy.

---

## 8. Přehled prostředí (env proměnné)

Všechny proměnné jsou sdíleny přes stejný K8s Secret `aidelnicek-llm`:

| Proměnná             | Kdo čte | Popis                                            |
|----------------------|---------|--------------------------------------------------|
| `OPENAI_AUTH_BEARER` | Python  | Bearer token (API klíč nebo OAuth token)         |
| `OPENAI_MODEL`       | PHP + Python | Model (výchozí: `gpt-4o`)                  |
| `OPENAI_BASE_URL`    | Python  | Vlastní endpoint (výchozí: `https://api.openai.com/v1`) |
| `DB_PATH`            | Python  | Cesta k SQLite souboru (výchozí: `/data/aidelnicek.sqlite`) |

PHP čte `OPENAI_MODEL` pro předání do Python workeru. Python čte `OPENAI_AUTH_BEARER`,
`OPENAI_BASE_URL` a `DB_PATH` přímo.

---

## 9. Chybové scénáře

| Scénář | Chování |
|--------|---------|
| Python worker není dostupný (curl timeout) | PHP vrátí `{"ok":false,"error":"LLM worker nedostupný: ..."}` — admin vidí chybovou hlášku, nestartuje SSE |
| OpenAI API chyba (rate limit, token) | Python nastaví `status='error'`, `error_message='...'` — SSE pošle `{"type":"done","status":"error"}`, UI zobrazí detail chyby |
| Nevalidní JSON odpověď | Generator provede jeden retry s correction promptem (stejná logika jako původní PHP) |
| Browser odpojení | PHP smyčka detekuje `connection_aborted()` a ukončí se — job v DB pokračuje normálně, výsledek se uloží |
| Timeout generování (> 10 min) | PHP SSE smyčka ukončí spojení, Python task pokračuje. Browser může znovu otevřít SSE na stejné `job_id` |

---

## 10. Adresářová struktura po M7/M8

```
llm_worker/
  main.py               ← FastAPI app (POST /generate, POST /complete, GET /health)
  generator.py          ← async streaming + sync complete + INSERT meal_plans + logging
  logger.py             ← LLM call logging do per-day SQLite (přidáno v M8)
  database.py           ← aiosqlite helpers, WAL mode
  requirements.txt
  Dockerfile

src/
  MealGenerator.php     ← +getPromptsForWeek() (public)
  Database.php          ← +WAL mode, +generation_jobs migrace

templates/
  admin_generate.php    ← nová stránka se streaming UI
  admin.php             ← +karta "Generování jídelníčku (AI streaming)"

public/
  index.php             ← +POST /admin/llm-generate
                           +GET  /admin/llm-stream

helm/aidelnicek/
  templates/
    deployment.yaml     ← +llm-worker sidecar
  values.yaml           ← +llmWorker sekce

.github/workflows/
  deploy.yml            ← +build llm-worker image + Helm tag
  release.yml           ← +build llm-worker image + Helm tag

docs/
  M7_LLM_STREAMING.md  ← tento soubor
```
