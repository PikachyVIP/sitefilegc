#!/usr/bin/env bash
# End-to-end smoke test for a running GC Files instance.
# Usage: tests/smoke.sh [base-url] [login] [password]
set -euo pipefail

base="${1:-http://127.0.0.1:8099}"
login="${2:-admin}"
password="${3:-CiPass12345}"
jar="$(mktemp)"
trap 'rm -f "$jar"' EXIT

fail() {
    echo "smoke: $*" >&2
    exit 1
}

# 1. The login page is reachable and carries a CSRF token.
html="$(curl -fsS -c "$jar" "$base/login.php")"
csrf="$(printf '%s' "$html" | sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' | head -n 1)"
[ -n "$csrf" ] || fail "login page has no CSRF token"

# 2. The seeded administrator can sign in.
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$jar" -c "$jar" \
    --data-urlencode "csrf=$csrf" \
    --data-urlencode "login=$login" \
    --data-urlencode "password=$password" \
    "$base/login.php")"
[ "$status" = "302" ] || fail "login returned HTTP $status"

# 3. The main storage page renders for the authenticated user.
body="$(curl -fsS -b "$jar" "$base/storage.php")"
printf '%s' "$body" | grep -q 'gc_drive' || fail "storage page did not render"

# 4. The legacy files page still works and sends the hardened headers.
headers="$(mktemp)"
trap 'rm -f "$jar" "$headers"' EXIT
curl -fsS -D "$headers" -o /dev/null -b "$jar" "$base/files.php"
grep -qi '^Content-Security-Policy:' "$headers" || fail "files.php has no CSP header"
grep -qi '^X-Content-Type-Options: *nosniff' "$headers" || fail "files.php has no nosniff header"

# 5. Unauthenticated storage access is redirected to login.
status="$(curl -sS -o /dev/null -w '%{http_code}' "$base/storage.php")"
[ "$status" = "302" ] || fail "anonymous storage returned HTTP $status"

# 6. The public release API returns valid JSON.
api="$(curl -fsS "$base/update_api.php")"
printf '%s' "$api" | grep -q '"ok":true' || fail "update_api.php did not return ok"

# 7. The admin upload-log page is reachable for a user with view_logs.
status="$(curl -sS -o /dev/null -w '%{http_code}' -b "$jar" "$base/upload_logs.php")"
[ "$status" = "200" ] || fail "upload_logs.php returned HTTP $status"

# 8. The media hosting page renders for an authenticated user.
body="$(curl -fsS -b "$jar" "$base/hosting.php")"
printf '%s' "$body" | grep -q 'gc_hosting' || fail "hosting page did not render"

echo "smoke: ok"
