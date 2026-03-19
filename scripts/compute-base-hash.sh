#!/bin/bash
# Compute deterministic hash for base image dependencies.
# Used to detect when base image needs to be rebuilt.
# Usage: ./compute-base-hash.sh [main|llm-worker]
# Outputs: short hash (12 chars) to stdout

set -e

IMAGE_TYPE="${1:?Usage: $0 main|llm-worker}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

compute_hash() {
  # Deterministic SHA-256, take first 12 chars
  sha256sum | awk '{print substr($1,1,12)}'
}

case "$IMAGE_TYPE" in
  main)
    {
      cat Dockerfile.base 2>/dev/null || head -n 30 Dockerfile  # fallback to first 30 lines
      cat composer.json
      [[ -f composer.lock ]] && cat composer.lock
      echo "php:8.2-apache"
    } | compute_hash
    ;;
  llm-worker)
    {
      cat llm_worker/Dockerfile.base 2>/dev/null || head -n 10 llm_worker/Dockerfile
      cat llm_worker/requirements.txt
      echo "python:3.12-slim"
    } | compute_hash
    ;;
  *)
    echo "Unknown image type: $IMAGE_TYPE" >&2
    exit 1
    ;;
esac
