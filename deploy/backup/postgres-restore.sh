#!/usr/bin/env bash
#
# Restore a base backup into a target data directory, optionally replaying
# archived WAL to a point in time.
#
# Deliberately refuses to touch a running or non-empty data directory. A
# restore script that can overwrite a live primary is a loaded gun, and the
# moment it is reached for is the moment nobody is thinking clearly.
#
# Usage:
#   postgres-restore.sh <backup-stamp|latest> <target-data-dir> [recovery-target-time]
set -Eeuo pipefail

STAMP="${1:?usage: postgres-restore.sh <backup-stamp|latest> <target-data-dir> [recovery-target-time]}"
TARGET_DIR="${2:?usage: postgres-restore.sh <backup-stamp|latest> <target-data-dir> [recovery-target-time]}"
RECOVERY_TARGET_TIME="${3:-}"

BACKUP_ROOT="${KAVO_BACKUP_DIR:-/var/backups/kavo}"
WAL_ARCHIVE="${KAVO_WAL_ARCHIVE_DIR:-/var/backups/kavo/wal}"

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }

if [ "$STAMP" = "latest" ]; then
  STAMP="$(find "${BACKUP_ROOT}/base" -mindepth 2 -maxdepth 2 -name '.complete' -printf '%h\n' \
    | sort | tail -1 | xargs -r basename)"
  [ -n "$STAMP" ] || { log "No completed backup found in ${BACKUP_ROOT}/base"; exit 1; }
  log "Resolved 'latest' to ${STAMP}"
fi

SOURCE="${BACKUP_ROOT}/base/${STAMP}"

[ -f "${SOURCE}/.complete" ] || { log "Backup ${STAMP} is missing its .complete marker; refusing to restore a partial backup"; exit 1; }

# Guard rails. Both of these are the difference between a drill and an outage.
if [ -f "${TARGET_DIR}/postmaster.pid" ]; then
  log "REFUSING: ${TARGET_DIR} has a postmaster.pid — a server is running there."
  exit 1
fi

if [ -d "$TARGET_DIR" ] && [ -n "$(ls -A "$TARGET_DIR" 2>/dev/null)" ]; then
  log "REFUSING: ${TARGET_DIR} is not empty. Restore into a fresh directory."
  exit 1
fi

mkdir -p "$TARGET_DIR"
chmod 0700 "$TARGET_DIR"

log "Restoring ${STAMP} → ${TARGET_DIR}"
tar -xzf "${SOURCE}/base.tar.gz" -C "$TARGET_DIR"

if [ -f "${SOURCE}/pg_wal.tar.gz" ]; then
  mkdir -p "${TARGET_DIR}/pg_wal"
  tar -xzf "${SOURCE}/pg_wal.tar.gz" -C "${TARGET_DIR}/pg_wal"
fi

# Put the cluster configuration back. On Debian/Ubuntu it lives outside the
# data directory and is therefore absent from the base backup; the restored
# cluster has no postgresql.conf and will not start without this.
if [ -f "${SOURCE}/config.tar.gz" ]; then
  log "Restoring cluster configuration"
  tar -xzf "${SOURCE}/config.tar.gz" -C "$TARGET_DIR"

  # Those files point at the *original* paths. Rewrite them so the restored
  # cluster is self-contained and cannot accidentally read — or write — the
  # live cluster's directories.
  {
    echo ""
    echo "# --- rewritten by postgres-restore.sh ---"
    echo "data_directory = '${TARGET_DIR}'"
    echo "hba_file = '${TARGET_DIR}/pg_hba.conf'"
    echo "ident_file = '${TARGET_DIR}/pg_ident.conf'"
    echo "external_pid_file = ''"
    # Archiving off: a restored cluster must never write into the archive
    # the live primary depends on.
    echo "archive_mode = off"
    # SSL certs are not in the backup, and a drill does not need them.
    echo "ssl = off"
  } >> "${TARGET_DIR}/postgresql.conf"
fi

# Re-tighten after extraction. Both tarballs carry a '.' entry whose mode is
# applied to the target, and the config directory is 0755 on Debian — Postgres
# refuses to start on a data directory that is not 0700 or 0750, so this must
# come after every extract, not before.
chmod 0700 "$TARGET_DIR"

# signal + restore_command is how Postgres 12+ enters archive recovery. The
# older recovery.conf has not existed for several major versions.
touch "${TARGET_DIR}/recovery.signal"

{
  echo "restore_command = 'cp ${WAL_ARCHIVE}/%f %p'"
  if [ -n "$RECOVERY_TARGET_TIME" ]; then
    echo "recovery_target_time = '${RECOVERY_TARGET_TIME}'"
    # Stop at the target and wait, rather than promoting automatically —
    # so the restored data can be inspected before anything depends on it.
    echo "recovery_target_action = 'pause'"
  fi
} >> "${TARGET_DIR}/postgresql.auto.conf"

log "Restored. Start the cluster on this directory to begin recovery."
[ -n "$RECOVERY_TARGET_TIME" ] && log "Recovery will pause at ${RECOVERY_TARGET_TIME}; promote with pg_wal_replay_resume()."

exit 0
