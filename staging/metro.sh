#!/usr/bin/env bash
# Start Metro for the STAGING app variant against the running local staging
# backend (HTTPS tunnel URL from staging/secrets/tunnel_url, or pass one).
# Refuses production hosts and checks the staging API answers before starting.
# Usage: staging/metro.sh [https://<tunnel-host>]
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
url="${1:-$(cat "$here/secrets/tunnel_url" 2>/dev/null || true)}"
url="${url%/}"
if [ -z "$url" ]; then echo "No staging URL: start the tunnel (staging/dc --profile tunnel up -d tunnel) first." >&2; exit 1; fi
case "$url" in *vanmalderstudio.be*) echo "Refusing a production host: $url" >&2; exit 1;; esac
code=$(curl -s -o /dev/null -w '%{http_code}' "$url/api/config" || true)
if [ "$code" != "200" ]; then echo "Staging API not reachable at $url/api/config (HTTP $code)." >&2; exit 1; fi
cd "$here/../mobile"
export APP_VARIANT=staging
export EXPO_PUBLIC_API_BASE_URL="$url/api" EXPO_PUBLIC_API_URL="$url/api" EXPO_PUBLIC_WEB_URL="$url"
echo "Metro → STAGING API $EXPO_PUBLIC_API_BASE_URL"
# --clear: EXPO_PUBLIC_* values are inlined at bundle time, so a new URL needs a fresh bundle.
exec npx expo start --port 8081 --clear "${@:2}"
