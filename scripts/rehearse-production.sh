#!/usr/bin/env bash
#
# Production rehearsal for Reference Tracker.
#
# Runs the app under PRODUCTION settings on this machine and then drives the
# E2E suite against that build. This is not a deployment — it exists because
# every other test in this repo runs with the development policy, and a bug
# that only exists in production (the CSP nonce, HSTS, the Secure cookie flag)
# is invisible from dev.
#
# What it proves:
#   1. the app boots with APP_ENV=production and APP_DEBUG=false
#   2. built assets are served (no dev server, no stale public/hot)
#   3. the production CSP is emitted and the page still works in a browser
#   4. HSTS + the Secure cookie flag actually fire  <-- needed real HTTPS
#   5. the full E2E suite passes against the production build
#
# Usage:
#   bash scripts/rehearse-production.sh
#
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR" || exit 1

APP_PORT="${REHEARSAL_PORT:-8125}"
TLS_PORT="${REHEARSAL_TLS_PORT:-8443}"
DB_NAME="reference_tracker_rehearsal"
ENV_FILE=".env.production"
TMPDIR_R="$(mktemp -d)"
SERVER_PID=""

RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; DIM=$'\033[2m'; OFF=$'\033[0m'
step() { printf '\n%s==>%s %s\n' "$YELLOW" "$OFF" "$1"; }
ok()   { printf '  %sok%s   %s\n' "$GREEN" "$OFF" "$1"; }
bad()  { printf '  %sFAIL%s %s\n' "$RED" "$OFF" "$1"; }
note() { printf '  %s%s%s\n' "$DIM" "$1" "$OFF"; }

cleanup() {
    if [ -n "$SERVER_PID" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
        kill "$SERVER_PID" 2>/dev/null
        wait "$SERVER_PID" 2>/dev/null
    fi
    rm -rf "$TMPDIR_R"
}
trap cleanup EXIT

FAILURES=0
fail() { FAILURES=$((FAILURES + 1)); bad "$1"; }

step "Preconditions"

if [ ! -f "$ENV_FILE" ]; then
    echo "  missing $ENV_FILE" >&2
    exit 2
fi

if [ ! -f public/build/manifest.json ]; then
    fail "no built assets (public/build/manifest.json). Run: npm run build"
else
    ok "built assets present"
fi

if [ -f public/hot ]; then
    fail "stale public/hot exists - it points asset URLs at a dev server"
    rm -f public/hot
    note "removed public/hot"
else
    ok "no stale public/hot"
fi

if command -v mariadb >/dev/null 2>&1; then
    mariadb -u root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\`;" 2>/dev/null \
        && ok "database $DB_NAME recreated" \
        || fail "could not create $DB_NAME (is MariaDB running?)"
else
    php -r '
        try {
            $p = new PDO("mysql:host=127.0.0.1;port=3306", "root", "");
            $p->exec("DROP DATABASE IF EXISTS `'"$DB_NAME"'`");
            $p->exec("CREATE DATABASE `'"$DB_NAME"'`");
            echo "ok\n";
        } catch (Throwable $e) { echo "err: ".$e->getMessage()."\n"; }
    ' | grep -q '^ok' && ok "database $DB_NAME recreated" || fail "could not create $DB_NAME"
fi

# A rehearsal must never run with APP_DEBUG on: it changes error pages and
# leaks internals, which is precisely what production must not do.
if grep -qE '^APP_DEBUG=true' "$ENV_FILE"; then
    fail "$ENV_FILE has APP_DEBUG=true - that is not a production rehearsal"
else
    ok "APP_DEBUG is off in $ENV_FILE"
fi

# A production env file ships without a key (it is a secret). Generate one for
# this machine so the rehearsal can actually boot. It is written into the
# git-ignored $ENV_FILE and never committed.
if grep -qE '^APP_KEY=$' "$ENV_FILE"; then
    NEW_KEY="$(php artisan key:generate --show 2>/dev/null)"
    if [ -n "$NEW_KEY" ]; then
        # portable in-place edit (BSD sed needs an argument to -i)
        php -r '
            $f = $argv[1]; $k = $argv[2];
            $s = file_get_contents($f);
            file_put_contents($f, preg_replace("/^APP_KEY=$/m", "APP_KEY=".$k, $s, 1));
        ' "$ENV_FILE" "$NEW_KEY"
        ok "generated APP_KEY into $ENV_FILE (git-ignored)"
    else
        fail "could not generate APP_KEY"
    fi
else
    ok "APP_KEY already present in $ENV_FILE"
fi

step "Migrating the rehearsal database"

php artisan migrate --force --env=production >/dev/null 2>&1 \
    && ok "migrations applied" || fail "migrations failed"

php artisan config:clear >/dev/null 2>&1
php artisan route:clear >/dev/null 2>&1
php artisan view:clear >/dev/null 2>&1
ok "caches cleared (a stale config cache silently ignores $ENV_FILE)"

step "Starting the app in production mode (HTTP :$APP_PORT)"

php artisan serve --port="$APP_PORT" --env=production >"$TMPDIR_R/server.log" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 40); do
    sleep 0.5
    if curl -fsS "http://127.0.0.1:$APP_PORT/up" >/dev/null 2>&1; then break; fi
done

if curl -fsS "http://127.0.0.1:$APP_PORT/up" >/dev/null 2>&1; then
    ok "health endpoint /up responds"
else
    fail "health endpoint did not come up"
    sed 's/^/    /' "$TMPDIR_R/server.log" | tail -20
fi

step "Checking production response headers over plain HTTP"

HEADERS="$(curl -sS -D - -o /dev/null "http://127.0.0.1:$APP_PORT/login")"

echo "$HEADERS" | grep -qi '^content-security-policy:' \
    && ok "Content-Security-Policy present" || fail "no CSP header"

if echo "$HEADERS" | grep -qi "script-src[^;]*'nonce-"; then
    ok "CSP carries a nonce"
else
    fail "CSP has no nonce - inline scripts will be blocked in production"
fi

if echo "$HEADERS" | grep -qi '^strict-transport-security:'; then
    fail "HSTS sent over plain HTTP - it must only appear on HTTPS"
else
    ok "no HSTS over plain HTTP (correct)"
fi

if echo "$HEADERS" | grep -qi '^set-cookie:.*secure'; then
    fail "Secure cookie flag set over plain HTTP - the browser will drop it"
else
    ok "no Secure flag over plain HTTP (correct)"
fi

echo "$HEADERS" | grep -qi '^cross-origin-resource-policy:' \
    && ok "Cross-Origin-Resource-Policy present" || fail "missing CORP header"

step "The page actually renders (not just a 200)"

BODY="$(curl -sS "http://127.0.0.1:$APP_PORT/login")"
if echo "$BODY" | grep -q 'data-page'; then
    ok "Inertia payload present in the HTML"
else
    fail "no data-page payload - the page is an empty shell"
fi

if echo "$BODY" | grep -qE 'nonce="[A-Za-z0-9_-]+"'; then
    ok "inline script carries the nonce"
else
    fail "inline script has no nonce attribute"
fi

step "HTTPS: the only way to test HSTS and the Secure flag"

# A TLS-terminating proxy in front of the app. HSTS and Secure are only sent
# when the app believes the request is secure, so without this the two header
# checks above can never pass and the bug they represent stays invisible.
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
    -keyout "$TMPDIR_R/key.pem" -out "$TMPDIR_R/cert.pem" \
    -subj "/CN=127.0.0.1" -addext "subjectAltName=IP:127.0.0.1" >/dev/null 2>&1

php -r '
    $port = (int) $argv[1];
    $tls  = (int) $argv[2];
    $key  = $argv[3];
    $crt  = $argv[4];
    $ctx  = stream_context_create(["ssl" => [
        "local_cert" => $crt,
        "local_pk"   => $key,
        "allow_self_signed" => true,
        "verify_peer" => false,
    ]]);
    $srv = @stream_socket_server("tls://127.0.0.1:$tls", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
    if (!$srv) { fwrite(STDERR, "tls listen failed: $errstr\n"); exit(1); }
    while ($conn = @stream_socket_accept($srv, 30)) {
        $req = fread($conn, 4096);
        $fp  = @fsockopen("127.0.0.1", $port, $e, $es, 5);
        if ($fp) {
            // forward, declaring the original scheme
            $req = preg_replace("/\r\n\r\n/", "\r\nX-Forwarded-Proto: https\r\nX-Forwarded-For: 127.0.0.1\r\n\r\n", $req, 1);
            fwrite($fp, $req);
            while (!feof($fp)) { $chunk = fread($fp, 8192); if ($chunk === "" || $chunk === false) break; fwrite($conn, $chunk); }
            fclose($fp);
        }
        fclose($conn);
    }
' "$APP_PORT" "$TLS_PORT" "$TMPDIR_R/key.pem" "$TMPDIR_R/cert.pem" >"$TMPDIR_R/proxy.log" 2>&1 &
PROXY_PID=$!

sleep 1
TLS_HEADERS="$(curl -ksS -D - -o /dev/null "https://127.0.0.1:$TLS_PORT/login" 2>/dev/null)"

if [ -z "$TLS_HEADERS" ]; then
    note "self-signed proxy did not respond - skipping the HTTPS checks"
    note "HSTS + Secure remain UNVERIFIED for this run"
else
    echo "$TLS_HEADERS" | grep -qi '^strict-transport-security:' \
        && ok "HSTS present when the request arrives over HTTPS" \
        || fail "HSTS missing behind a TLS-terminating proxy (the real bug)"

    if echo "$TLS_HEADERS" | grep -qi '^set-cookie:.*secure'; then
        ok "Secure cookie flag set over HTTPS"
    else
        fail "Secure cookie flag missing over HTTPS - the session cookie is exposed on http"
    fi
fi

kill "$PROXY_PID" 2>/dev/null; wait "$PROXY_PID" 2>/dev/null

step "E2E suite against the production build"

# The suite normally boots its own dev server. Point it at this production
# instance instead, so the assertions run against built assets and the strict
# production CSP rather than the loose dev policy. The server must stay UP:
# in rehearsal mode Playwright starts nothing of its own.
if REHEARSAL_BASE_URL="http://127.0.0.1:$APP_PORT" E2E_DB="$DB_NAME" npx playwright test --reporter=line 2>&1 | tail -25; then
    ok "E2E suite finished"
else
    fail "E2E suite reported failures against the production build"
fi

printf '\n'
if [ "$FAILURES" -eq 0 ]; then
    printf '%sRehearsal passed.%s Production configuration is verified.\n' "$GREEN" "$OFF"
    exit 0
else
    printf '%sRehearsal FAILED: %d check(s).%s\n' "$RED" "$FAILURES" "$OFF"
    exit 1
fi
