#!/usr/bin/env bash
#
# First-time setup for a Kaabosh app box. Run once, as a sudoer, on a fresh
# Ubuntu 24.04 host. It is safe to re-run: every step checks before acting.
#
# What it deliberately does NOT do: install Postgres. ADR 0001 §8 puts the
# database on its own box, because app, queue workers, Reverb and Postgres on
# one VPS means a memory spike anywhere OOM-kills the one process that must
# never be OOM-killed. Provision the data box with provision-data.sh.
#
# Every host command is overridable and nothing runs under --dry-run, so this
# can be read, rehearsed and diffed before it touches a server — the same
# reason deploy.sh is built that way.
#
# Usage:
#   sudo ./provision.sh production
#   ./provision.sh production --dry-run
set -Eeuo pipefail

ENVIRONMENT="${1:?usage: provision.sh <environment> [--dry-run]}"
DRY_RUN=0
[ "${2:-}" = "--dry-run" ] && DRY_RUN=1

DEPLOY_PATH="${KAABOSH_DEPLOY_PATH:-/var/www/kaabosh-${ENVIRONMENT}}"
APP_USER="${KAABOSH_APP_USER:-kaabosh}"
ROOT_DOMAIN="${KAABOSH_ROOT_DOMAIN:-kaabosh.tech}"
PHP_VERSION="${KAABOSH_PHP_VERSION:-8.4}"
NODE_MAJOR="${KAABOSH_NODE_MAJOR:-22}"

log()  { printf '\n==> %s\n' "$*"; }
skip() { printf '    (already) %s\n' "$*"; }

run() {
  # Joined into one string and evalled as a string: these commands carry
  # pipes and redirections, so they are not an argv array pretending to be one.
  local command="$*"

  if [ "$DRY_RUN" -eq 1 ]; then
    printf '    would run: %s\n' "$command"
    return 0
  fi

  eval "$command"
}

[ "$DRY_RUN" -eq 1 ] || [ "$(id -u)" -eq 0 ] || {
  echo "Run as root, or pass --dry-run to see what it would do." >&2
  exit 1
}

# --------------------------------------------------------------- packages

log "System packages"
run "apt-get update -qq"
run "apt-get install -y -qq software-properties-common curl git unzip acl ufw"

# PHP from Ondřej's PPA: Ubuntu ships a PHP too old for Laravel 13.
if ! command -v "php${PHP_VERSION}" >/dev/null 2>&1; then
  run "add-apt-repository -y ppa:ondrej/php"
  run "apt-get update -qq"
  run "apt-get install -y -qq php${PHP_VERSION}-cli php${PHP_VERSION}-common \
       php${PHP_VERSION}-pgsql php${PHP_VERSION}-redis php${PHP_VERSION}-mbstring \
       php${PHP_VERSION}-xml php${PHP_VERSION}-curl php${PHP_VERSION}-zip \
       php${PHP_VERSION}-bcmath php${PHP_VERSION}-intl php${PHP_VERSION}-gd"
else
  skip "php${PHP_VERSION}"
fi

# No php-fpm. FrankenPHP embeds the PHP runtime inside Caddy — installing FPM
# as well would give you two PHP installations and a reload command pointing
# at the one that is not serving anything.

command -v composer >/dev/null 2>&1 \
  && skip "composer" \
  || run "curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer"

command -v node >/dev/null 2>&1 \
  && skip "node $(node -v 2>/dev/null || true)" \
  || run "curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR}.x | bash - && apt-get install -y -qq nodejs"

command -v pnpm >/dev/null 2>&1 && skip "pnpm" || run "corepack enable && corepack prepare pnpm@latest --activate"

# ---------------------------------------------------------------- valkey

log "Valkey (cache, queue, sessions, usage counters)"
command -v valkey-server >/dev/null 2>&1 || command -v redis-server >/dev/null 2>&1 \
  && skip "valkey/redis present" \
  || run "apt-get install -y -qq valkey-server"

# Bound to loopback. It holds sessions and usage counters and has no auth by
# default; exposing it is handing over every signed-in session.
run "sed -i 's/^# *bind .*/bind 127.0.0.1 ::1/' /etc/valkey/valkey.conf 2>/dev/null || true"
run "systemctl enable --now valkey-server 2>/dev/null || systemctl enable --now redis-server"

# ------------------------------------------------------------ frankenphp

log "FrankenPHP (serves the API and terminates TLS)"
if [ -x /usr/local/bin/frankenphp ]; then
  skip "frankenphp"
else
  run "curl -sSL https://github.com/php/frankenphp/releases/latest/download/frankenphp-linux-x86_64 -o /usr/local/bin/frankenphp"
  run "chmod +x /usr/local/bin/frankenphp"
fi

# Binding 80/443 as a non-root service.
run "setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp"

# ------------------------------------------------------------ app user

log "Application user and directories"
id "$APP_USER" >/dev/null 2>&1 && skip "user ${APP_USER}" || run "adduser --system --group --shell /bin/bash --home /home/${APP_USER} ${APP_USER}"

for dir in "${DEPLOY_PATH}/releases" "${DEPLOY_PATH}/shared" "/var/log/caddy" "/etc/kaabosh"; do
  run "mkdir -p ${dir}"
done

run "chown -R ${APP_USER}:${APP_USER} ${DEPLOY_PATH} /var/log/caddy"

# The .env lives in shared/ and is symlinked into each release, so a rollback
# never takes the configuration back with it.
if [ -f "${DEPLOY_PATH}/shared/.env" ]; then
  skip ".env exists — not touching it"
else
  log "No .env yet. Generate one with:"
  printf '    deploy/generate-secrets.sh %s > %s/shared/.env\n' "$ENVIRONMENT" "$DEPLOY_PATH"
  printf '    chmod 600 %s/shared/.env && chown %s:%s %s/shared/.env\n' "$DEPLOY_PATH" "$APP_USER" "$APP_USER" "$DEPLOY_PATH"
fi

# deploy.sh is the only thing GitHub Actions calls over SSH, so it lives in
# shared/ where a broken release cannot take it down with it.
run "install -m 0755 -o ${APP_USER} -g ${APP_USER} deploy/deploy.sh ${DEPLOY_PATH}/shared/deploy.sh"
run "install -m 0644 deploy/Caddyfile /etc/kaabosh/Caddyfile"

# ------------------------------------------------------------- services

log "systemd units"

write_unit() {
  local path="$1" body="$2"

  if [ "$DRY_RUN" -eq 1 ]; then
    printf '    would write %s\n' "$path"
    return 0
  fi

  printf '%s\n' "$body" > "$path"
}

write_unit /etc/systemd/system/kaabosh-web.service "$(cat <<UNIT
[Unit]
Description=Kaabosh web (FrankenPHP)
After=network.target

[Service]
Type=simple
User=${APP_USER}
WorkingDirectory=${DEPLOY_PATH}/current
EnvironmentFile=${DEPLOY_PATH}/shared/.env
ExecStart=/usr/local/bin/frankenphp run --config /etc/kaabosh/Caddyfile
# A reload re-reads the config and swaps workers without dropping connections,
# which is what makes the deploy's symlink swap zero-downtime.
ExecReload=/usr/local/bin/frankenphp reload --config /etc/kaabosh/Caddyfile
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
UNIT
)"

write_unit /etc/systemd/system/kaabosh-queue.service "$(cat <<UNIT
[Unit]
Description=Kaabosh queue worker
After=network.target

[Service]
Type=simple
User=${APP_USER}
WorkingDirectory=${DEPLOY_PATH}/current/apps/api
ExecStart=/usr/bin/php${PHP_VERSION} artisan queue:work redis --sleep=1 --tries=3 --max-time=3600
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
UNIT
)"

write_unit /etc/systemd/system/kaabosh-reverb.service "$(cat <<UNIT
[Unit]
Description=Kaabosh Reverb websocket server
After=network.target

[Service]
Type=simple
User=${APP_USER}
WorkingDirectory=${DEPLOY_PATH}/current/apps/api
ExecStart=/usr/bin/php${PHP_VERSION} artisan reverb:start --host=127.0.0.1 --port=8080
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
UNIT
)"

write_unit /etc/systemd/system/kaabosh-scheduler.service "$(cat <<UNIT
[Unit]
Description=Kaabosh scheduler

[Service]
Type=oneshot
User=${APP_USER}
WorkingDirectory=${DEPLOY_PATH}/current/apps/api
ExecStart=/usr/bin/php${PHP_VERSION} artisan schedule:run
UNIT
)"

write_unit /etc/systemd/system/kaabosh-scheduler.timer "$(cat <<UNIT
[Unit]
Description=Run the Kaabosh scheduler every minute

[Timer]
OnCalendar=*:0/1
AccuracySec=1s

[Install]
WantedBy=timers.target
UNIT
)"

run "systemctl daemon-reload"
run "systemctl enable kaabosh-web kaabosh-queue kaabosh-reverb kaabosh-scheduler.timer"

# ---------------------------------------------------------------- firewall

log "Firewall"
run "ufw allow OpenSSH"
run "ufw allow 80/tcp"
run "ufw allow 443/tcp"
# 8080 (Reverb) and 5432 (Postgres) are deliberately absent: Caddy proxies to
# Reverb over the loopback, and the database is reached over the private
# network from this box only.
run "ufw --force enable"

log "Done"
cat <<NEXT

Next, in order:

  1. Generate the environment file if you have not:
       deploy/generate-secrets.sh ${ENVIRONMENT} > ${DEPLOY_PATH}/shared/.env
       chmod 600 ${DEPLOY_PATH}/shared/.env

  2. Fill the third-party values in it, then prove none is still blank:
       deploy/generate-secrets.sh ${ENVIRONMENT} --check ${DEPLOY_PATH}/shared/.env

  3. Point DNS at this box — api, ws and a WILDCARD for tenant subdomains:
       api.${ROOT_DOMAIN}   A  <this IP>
       ws.${ROOT_DOMAIN}    A  <this IP>
       *.${ROOT_DOMAIN}     A  <this IP>
     Behind Cloudflare keep the wildcard DNS-only. Proxied terminates TLS at
     Cloudflare, so Caddy never sees the handshake for a merchant's custom
     domain and on-demand issuance silently never fires.

  4. Add the deploy secrets to GitHub (Settings → Secrets → Actions):
       DEPLOY_HOST, DEPLOY_USER, DEPLOY_SSH_KEY, REPO_URL,
       HEALTH_URL=https://api.${ROOT_DOMAIN}/up

  5. Push to main. The workflow calls ${DEPLOY_PATH}/shared/deploy.sh.

NEXT
