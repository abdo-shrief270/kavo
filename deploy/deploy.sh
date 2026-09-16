#!/usr/bin/env bash
#
# Atomic-release deploy for a single VPS.
#
# The zero-downtime moment is the symlink swap: it is atomic at the filesystem
# level, so no request is ever served from a half-built release. Everything
# before it is preparation; everything after is a graceful refresh.
#
# Every path and every command that touches the host is overridable. That is
# not a testing affordance bolted on — it is what makes the script runnable
# against a scratch directory, and a deploy script that cannot be rehearsed is
# one you debug for the first time during an outage.
#
# Usage: deploy.sh <environment> <git-ref>
set -Eeuo pipefail

ENVIRONMENT="${1:?usage: deploy.sh <environment> <git-ref>}"
REF="${2:?usage: deploy.sh <environment> <git-ref>}"

DEPLOY_PATH="${KAVO_DEPLOY_PATH:-/var/www/kavo-${ENVIRONMENT}}"
REPO_URL="${REPO_URL:?REPO_URL must be set}"
HEALTH_URL="${HEALTH_URL:?HEALTH_URL must be set}"
KEEP_RELEASES="${KAVO_KEEP_RELEASES:-5}"

# Host commands, overridable so the script can be rehearsed without root.
FPM_RELOAD="${KAVO_FPM_RELOAD:-sudo systemctl reload php8.4-fpm}"
REVERB_RESTART="${KAVO_REVERB_RESTART:-sudo supervisorctl restart kavo-${ENVIRONMENT}-reverb:*}"
COMPOSER_INSTALL="${KAVO_COMPOSER_INSTALL:-composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist}"
ASSET_BUILD="${KAVO_ASSET_BUILD:-pnpm install --frozen-lockfile && pnpm -r build}"
ARTISAN="${KAVO_ARTISAN:-php artisan}"

RELEASE="$(date +%Y%m%d%H%M%S)"
RELEASE_PATH="${DEPLOY_PATH}/releases/${RELEASE}"
CURRENT="${DEPLOY_PATH}/current"

PREVIOUS=""
[ -L "$CURRENT" ] && PREVIOUS="$(readlink -f "$CURRENT")"

SWAPPED=0

log() { printf '\n==> %s\n' "$*"; }

# Two different failures, handled differently.
#
# Before the swap, the live release was never touched: discard the partial
# directory and leave everything as it was. After the swap, the new release is
# live and broken, so the symlink has to go back and the processes reload
# against the old code.
rollback() {
  local exit_code=$?
  trap - ERR EXIT

  if [ "$SWAPPED" -eq 1 ] && [ -n "$PREVIOUS" ] && [ -d "$PREVIOUS" ]; then
    log "FAILED after swap — rolling back to $(basename "$PREVIOUS")"
    ln -sfn "$PREVIOUS" "$CURRENT"
    eval "$FPM_RELOAD" || true
    log "Rolled back. Release ${RELEASE} kept for inspection."
  else
    log "FAILED before swap — the live release was never touched"
    rm -rf "$RELEASE_PATH"
  fi

  exit "${exit_code:-1}"
}
trap rollback ERR

log "Building release ${RELEASE}"
mkdir -p "${DEPLOY_PATH}/releases" "${DEPLOY_PATH}/shared"
git clone --depth 1 --branch "$REF" "$REPO_URL" "$RELEASE_PATH"

log "Installing dependencies"
(cd "${RELEASE_PATH}/apps/api" && eval "$COMPOSER_INSTALL")
(cd "$RELEASE_PATH" && eval "$ASSET_BUILD")

log "Linking shared state"
ln -sfn "${DEPLOY_PATH}/shared/.env" "${RELEASE_PATH}/apps/api/.env"
rm -rf "${RELEASE_PATH}/apps/api/storage"
ln -sfn "${DEPLOY_PATH}/shared/storage" "${RELEASE_PATH}/apps/api/storage"

# Tags every error report and log line with the commit that produced it, so an
# issue traces to one deploy across backend and frontend alike.
APP_RELEASE="$(git -C "$RELEASE_PATH" rev-parse HEAD)"
if grep -q '^APP_RELEASE=' "${DEPLOY_PATH}/shared/.env" 2>/dev/null; then
  sed -i "s|^APP_RELEASE=.*|APP_RELEASE=${APP_RELEASE}|" "${DEPLOY_PATH}/shared/.env"
else
  echo "APP_RELEASE=${APP_RELEASE}" >> "${DEPLOY_PATH}/shared/.env"
fi

# Migrations run BEFORE the swap, and as the schema owner.
#
# Old and new code briefly coexist — queue workers on the previous release may
# still be finishing jobs — so the schema has to satisfy both. That is what the
# expand/contract rule exists for, and why CI rejects a destructive migration
# without explicit review. Note the asymmetry: the symlink rolls back, the
# schema does not.
log "Migrating (expand/contract only)"
(cd "${RELEASE_PATH}/apps/api" && eval "$ARTISAN migrate --force --database=pgsql_owner")

log "Warming caches"
(cd "${RELEASE_PATH}/apps/api" && eval "$ARTISAN config:cache" && eval "$ARTISAN route:cache" && eval "$ARTISAN view:cache" && eval "$ARTISAN event:cache")

log "Atomic swap"
ln -sfn "$RELEASE_PATH" "$CURRENT"
SWAPPED=1

# reload, never restart: in-flight requests finish on the old workers while new
# ones start on the new code. restart drops them.
log "Refreshing processes"
eval "$FPM_RELOAD"

# Signals workers to finish the current job then exit; Supervisor respawns them
# against the new code. Never kill -9 a worker — that drops in-flight jobs.
(cd "${RELEASE_PATH}/apps/api" && eval "$ARTISAN queue:restart") || true

eval "$REVERB_RESTART" || true

log "Verifying"
# /up is liveness only — is this release serving? The deep dependency check
# lives at /internal/health and alerts rather than gating rollback, so a blip
# in Redis cannot roll back a release that is serving fine.
HEALTHY=0
for attempt in 1 2 3 4 5; do
  if curl -fsS --max-time 10 "$HEALTH_URL" > /dev/null 2>&1; then
    log "Healthy on attempt ${attempt}"
    HEALTHY=1
    break
  fi
  sleep 3
done

[ "$HEALTHY" -eq 1 ] || { log "Health check failed after 5 attempts"; false; }

log "Pruning old releases (keeping ${KEEP_RELEASES})"
# Never prune the release currently linked, whatever the count says.
CURRENT_TARGET="$(readlink -f "$CURRENT")"
find "${DEPLOY_PATH}/releases" -mindepth 1 -maxdepth 1 -type d \
  | sort -r \
  | tail -n "+$((KEEP_RELEASES + 1))" \
  | while read -r old; do
      [ "$(readlink -f "$old")" = "$CURRENT_TARGET" ] && continue
      rm -rf "$old"
    done

trap - ERR
log "Deployed ${RELEASE} (${APP_RELEASE:0:8})"
