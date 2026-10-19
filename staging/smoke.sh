#!/usr/bin/env bash
# Smoke + isolation checks for the LOCAL staging stack (docs/clubs-staging-runbook.md §3).
# Usage: staging/smoke.sh [https://<tunnel-host>]
# Reads credentials from staging/secrets/ (gitignored); prints no secrets, tokens or codes.
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
LOCAL="http://127.0.0.1:8088"
TUNNEL="${1:-}"
MAILPIT="http://127.0.0.1:8025"
QAPW="$(cat "$here/secrets/qa_password")"
BASICPW="$(cat "$here/secrets/basic_password")"
JAR="$(mktemp -d)"
pass=0; fail=0
ok()   { echo "PASS  $1"; pass=$((pass+1)); }
bad()  { echo "FAIL  $1"; fail=$((fail+1)); }
check(){ if [ "$2" = "$3" ]; then ok "$1 ($3)"; else bad "$1 (expected $2, got $3)"; fi; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
json() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1]))" "$1"; }

echo "== Local (127.0.0.1, this Mac only)"
check "local /api/health"      200 "$(code "$LOCAL/api/health")"
[ "$(curl -s "$LOCAL/api/config" | json 'd.get("features",{}).get("clubs")')" = "True" ] && ok "features.clubs = true" || bad "features.clubs = true"
check "local /admin/login reachable" 200 "$(code "$LOCAL/admin/login")"

# Platform admin login over the local server (CSRF form flow).
tok=$(curl -s -c "$JAR/a" "$LOCAL/admin/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
st=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR/a" -c "$JAR/a" -X POST "$LOCAL/admin/login" \
      --data-urlencode "_token=$tok" --data-urlencode "email=qa_admin@staging.ballpicker.test" --data-urlencode "password=$QAPW")
check "admin login redirects" 302 "$st"
check "admin clubs index after login" 200 "$(code -b "$JAR/a" "$LOCAL/admin/clubs")"

BASE="${TUNNEL:-$LOCAL}"
if [ -n "$TUNNEL" ]; then
  echo "== Tunnel restrictions ($TUNNEL)"
  check "tunnel /api/config"            200 "$(code "$TUNNEL/api/config")"
  check "tunnel /admin blocked"         404 "$(code "$TUNNEL/admin")"
  check "tunnel /admin/login blocked"   404 "$(code "$TUNNEL/admin/login")"
  check "tunnel /up blocked"            404 "$(code "$TUNNEL/up")"
  check "tunnel /.env blocked"          404 "$(code "$TUNNEL/.env")"
  check "tunnel /sanctum/csrf-cookie blocked" 404 "$(code "$TUNNEL/sanctum/csrf-cookie")"
  check "tunnel / (landing) blocked"    404 "$(code "$TUNNEL/")"
  check "tunnel /club needs basic auth" 401 "$(code "$TUNNEL/club/login")"
  check "tunnel /club with basic auth"  200 "$(code -u "qa:$BASICPW" "$TUNNEL/club/login")"
  check "tunnel /privacy reachable"     200 "$(code "$TUNNEL/privacy")"
  check "tunnel storage PUT refused"    403 "$(code -X PUT "$TUNNEL/storage/x.txt")"
  scheme=$(curl -s "$TUNNEL/api/config" -o /dev/null -w '%{scheme}' | tr '[:lower:]' '[:upper:]'); check "tunnel uses https" "HTTPS" "$scheme"
fi

echo "== Player API ($BASE)"
TOKEN=$(curl -s -X POST "$BASE/api/login" -H 'Accept: application/json' -H 'Content-Type: application/json' \
         -d "{\"email\":\"qa_player_01@staging.ballpicker.test\",\"password\":\"$QAPW\"}" | json 'd.get("token","")')
[ -n "$TOKEN" ] && ok "player login returns a token" || bad "player login returns a token"
H=(-H "Authorization: Bearer $TOKEN" -H 'Accept: application/json')
clubs=$(curl -s "${H[@]}" "$BASE/api/clubs")
CLUB=$(echo "$clubs" | json '[c["id"] for c in d["data"] if c["name"]=="QA FC Alpha"][0]' 2>/dev/null)
[ -n "$CLUB" ] && ok "My Clubs lists QA FC Alpha" || bad "My Clubs lists QA FC Alpha"
check "club detail"            200 "$(code "${H[@]}" "$BASE/api/clubs/$CLUB")"
check "club leaderboard"       200 "$(code "${H[@]}" "$BASE/api/clubs/$CLUB/leaderboard")"
check "club competitions"      200 "$(code "${H[@]}" "$BASE/api/clubs/$CLUB/competitions?phase=active")"
packs=$(curl -s "${H[@]}" "$BASE/api/clubs/$CLUB/packs")
SLUG=$(echo "$packs" | json '[p["slug"] for p in d["data"] if p["name"]=="QA Training Ground"][0]' 2>/dev/null)
detail=$(curl -s "${H[@]}" "$BASE/api/clubs/$CLUB/packs/$SLUG")
IMG=$(echo "$detail" | json 'd["data"]["challenges"][0]["hidden_image_url"]' 2>/dev/null)
case "$IMG" in "$BASE"/media/clubs/*) ok "club image is a signed $BASE/media/clubs URL";; *) bad "club image URL host/scheme ($(echo "$IMG" | cut -c1-40)…)";; esac
check "signed club image loads" 200 "$(code "$IMG")"
check "unsigned club image refused" 403 "$(code "${IMG%%\?*}")"
OUT_TOKEN=$(curl -s -X POST "$BASE/api/login" -H 'Accept: application/json' -H 'Content-Type: application/json' \
         -d "{\"email\":\"qa_outsider@staging.ballpicker.test\",\"password\":\"$QAPW\"}" | json 'd.get("token","")')
check "outsider gets 404 on the club" 404 "$(code -H "Authorization: Bearer $OUT_TOKEN" -H 'Accept: application/json' "$BASE/api/clubs/$CLUB")"
pub=$(curl -s "${H[@]}" "$BASE/api/packs" | json '" ".join(p["name"] for p in d["data"])')
case "$pub" in *"QA "*) bad "public /api/packs leaks club packs";; *) ok "public /api/packs has no club packs";; esac

echo "== Club portal 2FA via Mailpit ($BASE)"
AUTH=(); [ -n "$TUNNEL" ] && AUTH=(-u "qa:$BASICPW")
curl -s -X DELETE "$MAILPIT/api/v1/messages" >/dev/null
ptok=$(curl -s "${AUTH[@]}" -c "$JAR/p" "$BASE/club/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
st=$(curl -s -o /dev/null -w '%{http_code}' "${AUTH[@]}" -b "$JAR/p" -c "$JAR/p" -X POST "$BASE/club/login" \
      --data-urlencode "_token=$ptok" --data-urlencode "email=qa_owner_alpha@staging.ballpicker.test" --data-urlencode "password=$QAPW")
check "portal password step redirects to the code step" 302 "$st"
check "portal dashboard still locked before the code" 302 "$(code "${AUTH[@]}" -b "$JAR/p" "$BASE/club")"
sleep 2
msg=$(curl -s "$MAILPIT/api/v1/messages")
to=$(echo "$msg" | json '",".join(t["Address"] for m in d["messages"] for t in m["To"])')
[ "$to" = "qa_owner_alpha@staging.ballpicker.test" ] && ok "portal code mail captured by Mailpit for the QA owner only" || bad "Mailpit recipients ($to)"
id=$(echo "$msg" | json 'd["messages"][0]["ID"]')
pcode=$(curl -s "$MAILPIT/api/v1/message/$id" | json '__import__("re").search(r"\b(\d{6})\b", d["Text"]).group(1)')
vtok=$(curl -s "${AUTH[@]}" -b "$JAR/p" -c "$JAR/p" "$BASE/club/verify" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
st=$(curl -s -o /dev/null -w '%{http_code}' "${AUTH[@]}" -b "$JAR/p" -c "$JAR/p" -X POST "$BASE/club/verify" \
      --data-urlencode "_token=$vtok" --data-urlencode "code=$pcode")
check "portal code accepted" 302 "$st"
final=$(curl -s -L -o /dev/null -w '%{http_code} %{url_effective}' "${AUTH[@]}" -b "$JAR/p" -c "$JAR/p" "$BASE/club")
case "$final" in "200 $BASE/club/"*) ok "portal dashboard after 2FA (single-club owner → ${final#200 $BASE})";; *) bad "portal dashboard after 2FA ($final)";; esac

echo "== Mail isolation"
nonqa=$(curl -s "$MAILPIT/api/v1/messages?limit=500" | json 'sum(1 for m in d["messages"] for t in m["To"] if not t["Address"].endswith("@staging.ballpicker.test"))')
check "every captured mail goes to @staging.ballpicker.test" 0 "$nonqa"

rm -rf "$JAR"
echo "== $pass passed, $fail failed"
[ "$fail" -eq 0 ]
