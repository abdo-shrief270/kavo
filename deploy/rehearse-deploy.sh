#!/usr/bin/env bash
#
# Rehearses deploy.sh against a scratch directory.
#
# Exercises the parts that only matter when something goes wrong: the atomic
# swap, the health gate, rollback after a bad release goes live, and pruning
# that never deletes the release currently serving.
#
# It stubs the host commands (php-fpm, supervisor, composer, pnpm) because
# those are not what breaks. What breaks is the symlink logic and the failure
# paths, and those run for real here.
set -Eeuo pipefail

ROOT="${1:-/var/tmp/kavo-deploy-rehearsal}"
SCRIPT="$(cd "$(dirname "$0")" && pwd)/deploy.sh"
FAKE_REPO="${ROOT}/fake-repo"
DEPLOY_PATH="${ROOT}/deploy"
HEALTH_FILE="${ROOT}/healthy"

pass=0; fail=0
ok()   { printf '  \033[32mPASS\033[0m %s\n' "$*"; pass=$((pass+1)); }
bad()  { printf '  \033[31mFAIL\033[0m %s\n' "$*"; fail=$((fail+1)); }
head_() { printf '\n\033[1m%s\033[0m\n' "$*"; }

rm -rf "$ROOT"; mkdir -p "$FAKE_REPO/apps/api" "$DEPLOY_PATH/shared/storage"
: > "$DEPLOY_PATH/shared/.env"

# A repository with the shape deploy.sh expects, and nothing else.
( cd "$FAKE_REPO"
  git init -q . && git config user.email d@e.test && git config user.name d
  mkdir -p apps/api && echo "app" > apps/api/artisan
  git add -A && git commit -qm "release one" && git branch -M main ) >/dev/null 2>&1

# Health is a file the rehearsal controls, so the gate can be failed on demand.
printf 'ok' > "$HEALTH_FILE"

run_deploy() {
  KAVO_DEPLOY_PATH="$DEPLOY_PATH" \
  REPO_URL="$FAKE_REPO" \
  HEALTH_URL="file://${HEALTH_FILE}" \
  KAVO_KEEP_RELEASES="${KEEP:-3}" \
  KAVO_FPM_RELOAD="true" \
  KAVO_REVERB_RESTART="true" \
  KAVO_COMPOSER_INSTALL="true" \
  KAVO_ASSET_BUILD="true" \
  KAVO_ARTISAN="true" \
  bash "$SCRIPT" rehearsal main
}

current_target() { readlink -f "${DEPLOY_PATH}/current"; }
release_count()  { find "${DEPLOY_PATH}/releases" -mindepth 1 -maxdepth 1 -type d | wc -l; }

head_ "1. First deploy"
if run_deploy >/dev/null 2>&1; then ok "deploy succeeded"; else bad "deploy failed"; fi
[ -L "${DEPLOY_PATH}/current" ] && ok "current is a symlink" || bad "current is not a symlink"
[ -d "$(current_target)" ] && ok "current points at a real release" || bad "current dangles"
grep -q '^APP_RELEASE=' "${DEPLOY_PATH}/shared/.env" && ok "APP_RELEASE written for Sentry tagging" || bad "APP_RELEASE missing"
[ -L "$(current_target)/apps/api/.env" ] && ok "shared .env linked into the release" || bad ".env not linked"

head_ "2. Second deploy swaps atomically"
FIRST="$(current_target)"
sleep 1
if run_deploy >/dev/null 2>&1; then ok "second deploy succeeded"; else bad "second deploy failed"; fi
SECOND="$(current_target)"
[ "$FIRST" != "$SECOND" ] && ok "current moved to the new release" || bad "current did not move"
[ -d "$FIRST" ] && ok "previous release kept on disk for rollback" || bad "previous release was deleted"

head_ "3. A failing health check rolls back"
BEFORE_ROLLBACK="$(current_target)"
rm -f "$HEALTH_FILE"   # the new release comes up unhealthy
sleep 1
set +e
run_deploy >/dev/null 2>&1
STATUS=$?
set -e
[ "$STATUS" -ne 0 ] && ok "deploy exited non-zero" || bad "deploy reported success despite failing health"
[ "$(current_target)" = "$BEFORE_ROLLBACK" ] && ok "current rolled back to the previous release" || bad "current left on the broken release"
[ -d "$(current_target)" ] && ok "rolled-back release still intact" || bad "rolled-back release is gone"

head_ "4. Pruning keeps the live release"
printf 'ok' > "$HEALTH_FILE"
for _ in 1 2 3 4; do sleep 1; run_deploy >/dev/null 2>&1 || true; done
COUNT="$(release_count)"
[ "$COUNT" -le 5 ] && ok "old releases pruned (${COUNT} on disk)" || bad "pruning did not run (${COUNT} on disk)"
[ -d "$(current_target)" ] && ok "live release survived pruning" || bad "pruning deleted the live release"

head_ "Result"
printf '  %d passed, %d failed\n\n' "$pass" "$fail"
[ "$fail" -eq 0 ] || exit 1
