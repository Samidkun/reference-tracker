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
./vendor/bin/phpunit --quiet >/dev/null 2>&1 || { echo "REFUSING: PHP tests fail"; exit 1; }
echo "  ok: PHP tests pass"

# 4. built assets present
[ -f public/build/manifest.json ] || { echo "REFUSING: run npm run build first"; exit 1; }
echo "  ok: vite manifest present"

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
