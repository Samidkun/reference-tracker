#!/usr/bin/env bash
# Push this release and open a PR.
#
# Run this YOURSELF — it is deliberately not automated, because pushing is
# irreversible in the way that matters (a leaked key or a bad commit is
# public the moment it lands, and mirrors/caches are out of your control).
#
# Preconditions are checked below and the script refuses to continue if any
# fail. Do not bypass them.
set -euo pipefail

BRANCH="release/v1.0.0"
TAG="v1.0.0"

echo "=== preflight ==="

# 1. no secrets tracked
tracked_env=$(git ls-files | grep -E '^\.env' | grep -v '^\.env\.example$' || true)
if [ -n "$tracked_env" ]; then
  echo "REFUSING: env file(s) are tracked: $tracked_env"
  exit 1
fi
echo "  ok: only .env.example is tracked"

# 2. no secrets anywhere in history
hits=0
while read -r blob; do
  [ -z "$blob" ] && continue
  git cat-file -p "$blob" 2>/dev/null > /tmp/_blob || continue
  if ! python3 .githooks/scan_secrets.py /tmp/_blob >/dev/null 2>&1; then
    echo "REFUSING: secret found in history blob $blob"
    hits=$((hits+1))
  fi
done < <(git rev-list --objects --all | awk '{print $1}' | sort -u)
[ "$hits" -gt 0 ] && exit 1
echo "  ok: history is clean"

# 3. tests green
# NOTE: do NOT use `--quiet`. On PHPUnit 12 it exits 2 even when the suite is
# green (verified: exit 0 without it, exit 2 with it), so a `--quiet` check
# refuses to release a perfectly healthy tree. Silencing output and reading
# the real exit code is the reliable form.
if ! ./vendor/bin/phpunit >/tmp/_phpunit.log 2>&1; then
  echo "REFUSING: PHP tests fail (see /tmp/_phpunit.log)"
  tail -5 /tmp/_phpunit.log
  exit 1
fi
echo "  ok: PHP tests pass"

# 4. built assets present
[ -f public/build/manifest.json ] || { echo "REFUSING: run npm run build first"; exit 1; }
echo "  ok: vite manifest present"

# 4b. no stale dev-server marker (it makes the app serve an empty shell)
if [ -f public/hot ]; then
  echo "REFUSING: public/hot exists — the app would point at a dead dev server"
  exit 1
fi
echo "  ok: no stale public/hot"

# 4c. E2E green (skippable only by explicitly asking)
if [ "${SKIP_E2E:-0}" = "1" ]; then
  echo "  !! E2E SKIPPED on request — this is a weaker gate"
elif ! npx playwright test --reporter=line >/tmp/_e2e.log 2>&1; then
  echo "REFUSING: E2E tests fail (see /tmp/_e2e.log)"
  tail -8 /tmp/_e2e.log
  exit 1
else
  echo "  ok: E2E tests pass"
fi

# 5. working tree clean
[ -z "$(git status --porcelain)" ] || { echo "REFUSING: uncommitted changes"; exit 1; }
echo "  ok: working tree clean"

echo
echo "=== ready ==="
echo "Remote: ${1:-<not specified>}"
if [ $# -eq 0 ]; then
  echo "Usage: $0 <remote-url-or-name>"
  echo "Then:  gh pr create --base main --head $BRANCH --title 'Release v1.0.0' --body-file docs/release/PR_BODY.md"
  exit 0
fi

REMOTE="$1"
git remote get-url origin >/dev/null 2>&1 || git remote add origin "$REMOTE"
git push -u origin main
git push -u origin "$BRANCH"
git push origin "$TAG"

echo
echo "Pushed. Now open the PR:"
echo "  gh pr create --base main --head $BRANCH \\"
echo "    --title 'Release v1.0.0' \\"
echo "    --body-file docs/release/PR_BODY.md"
