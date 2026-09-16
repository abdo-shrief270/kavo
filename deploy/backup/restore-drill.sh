#!/usr/bin/env bash
#
# Automated restore drill.
#
# Restores the most recent backup into a scratch cluster, starts it, and
# checks the data is actually there. Runs weekly.
#
# The reason this is a script and not a runbook paragraph: a drill that has
# been performed once is a drill that has stopped being true. Schema changes,
# permission changes, a silently-failing archive command — every one of them
# breaks recovery quietly, and the only honest way to know is to keep doing it.
# Scheduling it means the failure surfaces on a Sunday morning instead of
# during the incident.
set -Eeuo pipefail

DRILL_DIR="${KAVO_DRILL_DIR:-/var/tmp/kavo-restore-drill}"
DRILL_PORT="${KAVO_DRILL_PORT:-55432}"
PG_BIN="${KAVO_PG_BIN:-/usr/lib/postgresql/16/bin}"
DATABASE="${KAVO_DRILL_DATABASE:-kavo}"

# Below these, the restore is technically successful and practically empty —
# which is the failure mode a naive "did it start?" check misses entirely.
MIN_TENANTS="${KAVO_DRILL_MIN_TENANTS:-1}"

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }

# -w on every psql call, without exception. The restored cluster carries the
# live cluster's pg_hba, which requires a password — and an interactive psql
# with no terminal blocks forever rather than failing. A drill that hangs is
# worse than one that fails: cron never reports it and nobody finds out.
export PGCONNECT_TIMEOUT=10
psql_drill() { psql -w -h 127.0.0.1 -p "$DRILL_PORT" -U postgres "$@"; }
fail() { log "DRILL FAILED: $*"; cleanup; exit 1; }

cleanup() {
  if [ -f "${DRILL_DIR}/postmaster.pid" ]; then
    "${PG_BIN}/pg_ctl" -D "$DRILL_DIR" -m immediate stop >/dev/null 2>&1 || true
  fi
  # A previous run that died mid-drill leaves a cluster holding the port, and
# the resulting bind failure is opaque. Catch it here with a usable message.
if command -v ss >/dev/null 2>&1 && ss -ltn 2>/dev/null | grep -q ":${DRILL_PORT} "; then
  log "Port ${DRILL_PORT} is already in use — a previous drill may still be running."
  log "Stop it with: ${PG_BIN}/pg_ctl -D ${DRILL_DIR} -m immediate stop"
  exit 1
fi

rm -rf "$DRILL_DIR"
}
trap cleanup EXIT

rm -rf "$DRILL_DIR"

log "Restoring the latest backup into a scratch cluster"
"$(dirname "$0")/postgres-restore.sh" latest "$DRILL_DIR" || fail "restore script returned non-zero"

# Trust auth for this scratch cluster only. It listens on 127.0.0.1 on a
# non-standard port, holds a copy of data we already have, and is destroyed at
# the end — and the alternative is baking a password into the drill. The live
# pg_hba is left untouched in the restore itself.
log "Relaxing auth for the scratch cluster"
cat > "${DRILL_DIR}/pg_hba.conf" <<'HBA'
local   all   all                  trust
host    all   all   127.0.0.1/32   trust
host    all   all   ::1/128        trust
HBA

log "Starting the restored cluster on port ${DRILL_PORT}"
"${PG_BIN}/pg_ctl" -D "$DRILL_DIR" -o "-p ${DRILL_PORT} -c listen_addresses=127.0.0.1" \
  -l "${DRILL_DIR}/drill.log" -w -t 120 start \
  || { log "--- cluster log ---"; tail -40 "${DRILL_DIR}/drill.log" 2>/dev/null; fail "restored cluster did not start"; }

log "Waiting for recovery to finish"
for _ in $(seq 1 60); do
  if psql_drill -d postgres -tAc 'SELECT NOT pg_is_in_recovery()' 2>/dev/null | grep -q '^t$'; then
    break
  fi
  sleep 2
done

# Starting is not the same as being correct. These are the assertions that
# turn "it booted" into "the data is there".
log "Verifying contents"

TENANTS="$(psql_drill -d "$DATABASE" -tAc 'SELECT count(*) FROM tenants' 2>/dev/null || echo 'ERR')"
[ "$TENANTS" = "ERR" ] && fail "could not query the restored database"
[ "$TENANTS" -ge "$MIN_TENANTS" ] || fail "restored database has ${TENANTS} tenants, expected at least ${MIN_TENANTS}"

# Row-level security must survive a restore. A recovered database that has
# lost its policies is a cross-tenant data leak wearing a backup's clothes.
POLICIES="$(psql_drill -d "$DATABASE" -tAc \
  "SELECT count(*) FROM pg_policies WHERE policyname = 'tenant_isolation'" 2>/dev/null || echo 0)"
[ "$POLICIES" -ge 10 ] || fail "restored database has only ${POLICIES} tenant_isolation policies — isolation did not survive the restore"

log "DRILL PASSED — ${TENANTS} tenants, ${POLICIES} isolation policies intact"
exit 0
