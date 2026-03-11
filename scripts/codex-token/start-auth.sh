#!/usr/bin/env bash

set -e

VERIFIER=$(openssl rand -base64 32 | tr '+/' '-_' | tr -d '=')
CHALLENGE=$(printf "%s" "$VERIFIER" | openssl dgst -sha256 -binary | openssl base64 | tr '+/' '-_' | tr -d '=')

STATE=$(openssl rand -hex 16)

echo "$VERIFIER" > pkce_verifier.txt

AUTH_URL="https://auth.openai.com/oauth/authorize?response_type=code&client_id=codex_cli&redirect_uri=http://127.0.0.1:1455/callback&scope=openid%20profile%20email%20offline_access&code_challenge=$CHALLENGE&code_challenge_method=S256&state=$STATE"

echo
echo "Open this URL in browser:"
echo
echo "$AUTH_URL"
echo
echo "After login copy the FULL redirect URL and run:"
echo
echo "./get_token.sh '<redirect_url>'"
