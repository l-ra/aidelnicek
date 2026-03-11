#!/usr/bin/env bash

set -e

REDIRECT_URL="$1"

CODE=$(echo "$REDIRECT_URL" | sed -n 's/.*code=\([^&]*\).*/\1/p')

if [ -z "$CODE" ]; then
  echo "Authorization code not found"
  exit 1
fi

VERIFIER=$(cat pkce_verifier.txt)

echo "Exchanging code for token..."

curl -s https://auth.openai.com/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=authorization_code" \
  -d "client_id=codex_cli" \
  -d "code=$CODE" \
  -d "code_verifier=$VERIFIER" \
  -d "redirect_uri=http://127.0.0.1:1455/callback" \
  | tee token.json

echo
echo "Token saved to token.json"
