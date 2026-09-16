#!/usr/bin/env bash
#
# Base backup. Pairs with continuous WAL archiving: the base is the floor,
# the WAL stream is everything since. Neither is a backup on its own.
#
# Uses pg_basebackup so it works with nothing beyond the Postgres client
# tools. pgbackrest.conf is the better production path — incrementals,
# parallel compression, point-in-time restore — but this one always runs.
set -Eeuo pipefail

BACKUP_ROOT="${KAVO_BACKUP_DIR:-/var/backups/kavo}"
RETAIN_DAYS="${KAVO_BACKUP_RETAIN_DAYS:-7}"
S3_BUCKET="${BACKUP_S3_BUCKET:-}"
S3_PREFIX="${BACKUP_S3_PREFIX:-kavo}"
PGHOST="${PGHOST:-127.0.0.1}"
PGPORT="${PGPORT:-5432}"
PGUSER="${PGUSER:-kavo_backup}"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="${BACKUP_ROOT}/base/${STAMP}"

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }

mkdir -p "$TARGET"

# An incomplete backup directory that looks complete is worse than none, so a
# failure removes it rather than leaving it to be restored from later.
cleanup_failed() {
  log "FAILED — removing partial backup ${STAMP}"
  rm -rf "$TARGET"
  exit 1
}
trap cleanup_failed ERR

log "Base backup ${STAMP} → ${TARGET}"

# -X stream copies the WAL generated *during* the backup alongside it, so the
# base is self-consistent without depending on the archive to replay it.
# -C -S creates a slot so the server retains WAL until this finishes.
pg_basebackup \
  --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" \
  --pgdata="$TARGET" \
  --format=tar --gzip --compress=9 \
  --wal-method=stream \
  --checkpoint=fast \
  --progress --no-password

# Debian and Ubuntu keep postgresql.conf, pg_hba.conf and pg_ident.conf in
# /etc/postgresql/<ver>/<cluster>/, NOT in the data directory — so
# pg_basebackup does not capture them. A restore onto a fresh box without
# them has no configuration at all, and the cluster refuses to start.
#
# Found by an actual restore drill, which is the only way this surfaces.
CONFIG_DIR="${KAVO_PG_CONFIG_DIR:-/etc/postgresql/${KAVO_PG_VERSION:-16}/main}"

if [ -d "$CONFIG_DIR" ]; then
  log "Capturing cluster configuration from ${CONFIG_DIR}"
  tar -czf "${TARGET}/config.tar.gz" -C "$CONFIG_DIR" .
else
  # Red Hat-style layouts keep the config inside PGDATA, where the base
  # backup already has it. Anything else is worth knowing about.
  log "NOTE: ${CONFIG_DIR} not found — assuming configuration lives inside the data directory."
fi

echo "$STAMP" > "${TARGET}/.complete"

SIZE="$(du -sh "$TARGET" | cut -f1)"
log "Base backup complete (${SIZE})"

if [ -n "$S3_BUCKET" ]; then
  log "Uploading to s3://${S3_BUCKET}/${S3_PREFIX}/base/${STAMP}"
  aws s3 sync --only-show-errors "$TARGET" "s3://${S3_BUCKET}/${S3_PREFIX}/base/${STAMP}"
  log "Upload complete"
else
  # Not fatal — a local backup is still better than none — but it must be
  # loud, because an operator who thinks backups are off-box and finds out
  # otherwise has already lost the data.
  log "WARNING: BACKUP_S3_BUCKET is unset. This backup lives only on this machine."
fi

trap - ERR

# Prune only *completed* local backups. The local copy is a cache; the
# off-box one is the backup, and its retention is the bucket's lifecycle rule.
log "Pruning local base backups older than ${RETAIN_DAYS} days"
find "${BACKUP_ROOT}/base" -mindepth 1 -maxdepth 1 -type d -mtime "+${RETAIN_DAYS}" \
  -exec test -f '{}/.complete' \; -print -exec rm -rf '{}' \; || true

log "Done"
