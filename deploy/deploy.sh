#!/usr/bin/env bash
#
# Atomic-release deploy for a single VPS.
#
# The zero-downtime moment is the symlink swap in step 6: it is atomic at the
# filesystem level, so no request is ever served from a half-built release.
# Everything before it is preparation; everything after is a graceful refresh.
#
# Usage: deploy.sh <environment> <git-ref>
set -Eeuo pipefail

ENVIRONMENT="${1:?usage: deploy.sh <environment> <git-ref>}"
REF="${2:?usage: deploy.sh <environment> <git-ref>}"

DEPLOY_PATH="/var/www/kavo-${ENVIRONMENT}"
RELEASE="$(date +%Y%m%d%H%M%S)"
RELEASE_PATH="${DEPLOY_PATH}/releases/${RELEASE}"
CURRENT="${DEPLOY_PATH}/current"
KEEP_RELEASES=5

PREVIOUS=""
[ -L "$CURRENT" ] && PREVIOUS="$(readlink -f "$CURRENT")"

log() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

# Any failure before the swap leaves the live release untouched; the partial
# directory is all that is discarded.
rollback() {
  if [ -n "$PREVIOUS" ] && [ -d "$PREVIOUS" ]; then
    log "FAILED — rolling back to $(basename "$PREVIOUS")"
    ln -sfn "$PREVIOUS" "$CURRENT"
    sudo systemctl reload php8.4-fpm || true
  fi
  rm -rf "$RELEASE_PATH"
  exit 1
}
trap rollback ERR

log "Building release ${RELEASE}"
mkdir -p "${DEPLOY_PATH}/releases"
git clone --depth 1 --branch "$REF" "$REPO_URL" "$RELEASE_PATH"

cd "${RELEASE_PATH}/apps/api"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

cd "$RELEASE_PATH"
pnpm install --frozen-lockfile
pnpm -r build

log "Linking shared state"
ln -sfn "${DEPLOY_PATH}/shared/.env" "${RELEASE_PATH}/apps/api/.env"
rm -rf "${RELEASE_PATH}/apps/api/storage"
ln -sfn "${DEPLOY_PATH}/shared/storage" "${RELEASE_PATH}/apps/api/storage"

# Migrations run BEFORE the swap, and as the schema owner.
#
# Old and new code briefly coexist — queue workers on the previous release may
# still be finishing jobs — so the schema has to satisfy both. That is what
# the expand/contract rule in ADR 0001 is for, and why CI rejects a migration
# that drops or renames a column without review.
log "Migrating (expand/contract only)"
cd "${RELEASE_PATH}/apps/api"
php artisan migrate --force --database=pgsql_owner

# Tags every error report and log line with the commit that produced it.
export APP_RELEASE
APP_RELEASE="$(git -C "$RELEASE_PATH" rev-parse HEAD)"
grep -q '^APP_RELEASE=' "${DEPLOY_PATH}/shared/.env" \
  && sed -i "s|^APP_RELEASE=.*|APP_RELEASE=${APP_RELEASE}|" "${DEPLOY_PATH}/shared/.env" \
  || echo "APP_RELEASE=${APP_RELEASE}" >> "${DEPLOY_PATH}/shared/.env"

log "Warming caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

log "Atomic swap"
ln -sfn "$RELEASE_PATH" "$CURRENT"

# reload, never restart: in-flight requests finish on the old workers while
# new ones start on the new code. restart drops them.
log "Refreshing processes"
sudo systemctl reload php8.4-fpm

# Signals workers to finish the current job then exit; Supervisor respawns
# them against the new code. Never kill -9 a worker — that drops in-flight jobs.
php artisan queue:restart

sudo supervisorctl restart "kavo-${ENVIRONMENT}-reverb:*" || true

log "Verifying"
# /up is liveness only — is this release serving? The deep dependency check
# lives at /health and alerts rather than triggering a rollback, so a blip in
# Redis cannot roll back a perfectly good deploy.
for attempt in 1 2 3 4 5; do
  if curl -fsS --max-time 10 "${HEALTH_URL}" > /dev/null; then
    log "Healthy on attempt ${attempt}"
    break
  fi
  [ "$attempt" = 5 ] && rollback
  sleep 3
done

log "Pruning old releases (keeping ${KEEP_RELEASES})"
cd "${DEPLOY_PATH}/releases"
ls -1dt ./*/ | tail -n "+$((KEEP_RELEASES + 1))" | xargs -r rm -rf

trap - ERR
log "Deployed ${RELEASE}"
