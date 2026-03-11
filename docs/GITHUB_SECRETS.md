# GitHub Secrets pro nasazení na Kubernetes

Tento dokument popisuje všechny položky, které je nutné vyplnit v **GitHub Secrets** pro zajištění fungování CI/CD pipeline a nasazení aplikace na staging a produkční prostředí.

## Seznam povinných secrets

| Secret | Popis | Použito v |
|--------|-------|-----------|
| `KUBE_CONFIG` | Kompletní obsah kubeconfig souboru pro připojení ke Kubernetes clusteru. Získáte příkazem `cat ~/.kube/config` (nebo odpovídající konfigurace pro váš cluster). | Staging, Production |
| `K8S_STAGING_NAMESPACE` | Název Kubernetes namespace pro staging prostředí (např. `aidelnicek-staging`). | Staging |
| `K8S_PRODUCTION_NAMESPACE` | Název Kubernetes namespace pro produkční prostředí (např. `aidelnicek-production`). | Production |
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
5. Pro `KUBE_CONFIG` zkopírujte celý výstup z `cat ~/.kube/config` včetně všech řádků.

## Doporučení pro prostředí (Environments)

Pro lepší správu oprávnění doporučujeme vytvořit v GitHubu **Environments**:

1. **Settings** → **Environments** → **New environment**
2. Vytvořte prostředí `staging` a `production`
3. U production můžete zapnout **Required reviewers** pro schválení před nasazením
4. Secrets lze definovat na úrovni environment – pak budou dostupné pouze pro dané prostředí

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
