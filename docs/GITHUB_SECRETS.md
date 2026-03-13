# GitHub Secrets pro nasazení na Kubernetes

Tento dokument popisuje všechny položky, které je nutné vyplnit v **GitHub Secrets** pro zajištění fungování CI/CD pipeline a nasazení aplikace na staging a produkční prostředí.

## Seznam povinných secrets

| Secret | Popis | Použito v |
|--------|-------|-----------|
| `KUBE_CONFIG_STAGING` | Kubeconfig pro připojení ke Kubernetes clusteru pro **staging**. Může být limitovaný token s plnými právy pouze v cílovém namespace – workflow se nesnaží vytvářet namespace. | Staging |
| `KUBE_CONFIG_PRODUCTION` | Kubeconfig pro připojení ke Kubernetes clusteru pro **produkci**. Může být limitovaný token s plnými právy pouze v cílovém namespace – workflow se nesnaží vytvářet namespace. | Production |
| `K8S_STAGING_NAMESPACE` | Název Kubernetes namespace pro staging prostředí (např. `aidelnicek-staging`). Namespace musí již existovat – workflow nepoužívá práva na vytváření namespace. | Staging |
| `K8S_PRODUCTION_NAMESPACE` | Název Kubernetes namespace pro produkční prostředí (např. `aidelnicek-production`). Namespace musí již existovat – workflow nepoužívá práva na vytváření namespace. | Production |
| `K8S_STAGING_HOST` | Hostname (doména) pro staging prostředí, pod kterou bude aplikace dostupná (např. `staging.aidelnicek.example.com`). | Staging |
| `K8S_PRODUCTION_HOST` | Hostname (doména) pro produkční prostředí, pod kterou bude aplikace dostupná (např. `aidelnicek.example.com`). | Production |
| `K8S_INGRESS_CLASS` | Název IngressController třídy v clusteru (např. `nginx`). Musí odpovídat `ingressClassName` vašeho Ingress controlleru. | Staging, Production |
| `K8S_CERT_ISSUER` | Název ClusterIssuer pro cert-manager (Let's Encrypt), např. `letsencrypt-prod` nebo `letsencrypt-staging`. Certifikáty se vydávají automaticky. | Staging, Production |
| `K8S_STORAGE_CLASS` | Název StorageClass pro provisioning PersistentVolumeClaim (PVC). Třída musí být v clusteru dostupná a ověřená pro persistentní úložiště. | Staging, Production |

## Jak přidat secrets do GitHubu

1. Otevřete váš repozitář na GitHubu.
2. Přejděte do **Settings** → **Secrets and variables** → **Actions**.
3. Klikněte na **New repository secret**.
4. Zadejte **Name** (přesně podle tabulky výše) a **Value** (hodnotu secretu).
5. Pro `KUBE_CONFIG_STAGING` a `KUBE_CONFIG_PRODUCTION` zkopírujte celý výstup z `cat ~/.kube/config` včetně všech řádků (každé prostředí může mít jiný cluster a tedy jiný kubeconfig).

## Doporučení pro prostředí (Environments)

Pro lepší správu oprávnění doporučujeme vytvořit v GitHubu **Environments**:

1. **Settings** → **Environments** → **New environment**
2. Vytvořte prostředí `staging` a `production`
3. U production můžete zapnout **Required reviewers** pro schválení před nasazením
4. Secrets lze definovat na úrovni environment – pak budou dostupné pouze pro dané prostředí

## M5 — OpenAI LLM integrace

Secrets pro AI generátor jídelníčků. Jsou předávány do Kubernetes přes
`kubectl create secret generic aidelnicek-llm` (viz `.github/workflows/deploy.yml`
a `release.yml`) a namontovány do podu jako env proměnné přes `envFrom.secretRef`.

| Secret                | Povinný   | Popis                                                                            |
|-----------------------|-----------|----------------------------------------------------------------------------------|
| `OPENAI_AUTH_BEARER`  | **Ano**   | Bearer token pro OpenAI API. Může být:<br>• **API klíč** — z [platform.openai.com/api-keys](https://platform.openai.com/api-keys) (začíná `sk-...`)<br>• **OAuth access token** — vydaný poskytovatelem identity při enterprise/SSO přihlášení<br>Kód obě varianty posílá identicky jako `Authorization: Bearer <hodnota>`. |
| `OPENAI_MODEL`        | Ne        | Název modelu (výchozí: `gpt-4o`). Příklady: `gpt-4o`, `gpt-4o-mini`, `gpt-4.1`  |
| `OPENAI_BASE_URL`     | Ne        | Vlastní endpoint (výchozí: `https://api.openai.com/v1`). Použijte pro Azure OpenAI nebo proxy. |
| `LLM_PROVIDER`        | Ne        | Provider (výchozí: `openai`). Rezerva pro budoucí přidání dalších providerů.     |
| `LLM_MAX_COMPLETION_TOKENS` | Ne        | Maximální počet výstupních tokenů na jedno volání (výchozí: `16000`). Plný 7denní jídelníček potřebuje ~5 000–6 000 tokenů; hodnota nižší než ~8 000 způsobí předčasné ukončení generování (`finish_reason='length'`). Zvyšte pro větší modely nebo bohatší jídelníčky. |
| `AI_REGEN_UI_ENABLED`       | Ne        | `true` = zobrazí tlačítko **Přegenerovat AI** v UI jídelníčku (výchozí: skryto). Lze nastavit odlišně pro staging a production. |

**Doporučení pro prostředí:**
- Pro staging nastavte `AI_REGEN_UI_ENABLED=true` a levnější model (`gpt-4o-mini`)
- Pro production nechte `AI_REGEN_UI_ENABLED` prázdné (skrytý button) nebo `true` dle potřeby

---

## Triggering nasazení

- **Staging**: Automaticky při push do větve `main` (workflow `deploy.yml`)
- **Production**: Automaticky při vytvoření tagu `v*` (např. `v1.0.0`) – workflow `release.yml`

## Volitelné: Image Pull Secret (pro privátní registry)

Pokud je Docker image v GHCR privátní, je nutné vytvořit v každém namespace Kubernetes secret pro pull:

```bash
kubectl create secret docker-registry ghcr-secret \
  --namespace=<K8S_NAMESPACE> \
  --docker-server=ghcr.io \
  --docker-username=<GITHUB_USERNAME> \
  --docker-password=<GITHUB_PAT>
```

Poté přidejte do GitHub Secrets:

| Secret | Popis |
|--------|-------|
| `K8S_IMAGE_PULL_SECRET_NAME` | Název vytvořeného secretu (např. `ghcr-secret`) pro povolené pullnutí image z privátního registry |

*Poznámka: Pokud používáte tento secret, bude nutné upravit Helm deploy příkazy o `--set imagePullSecrets[0].name=${{ secrets.K8S_IMAGE_PULL_SECRET_NAME }}`.*
