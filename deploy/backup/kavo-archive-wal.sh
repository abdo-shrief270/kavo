#!/usr/bin/env bash
#
# Postgres archive_command. Called by the server for each completed WAL
# segment, as: kavo-archive-wal.sh <path> <filename>
#
# Two rules this must obey, both enforced below:
#
#   1. Never exit 0 unless the segment is durably stored. Postgres deletes
#      the local copy on success, so a lying archive command silently
#      destroys the recovery chain.
#   2. Never overwrite an existing archived segment with different content.
#      That is the signature of two servers archiving to one location, and
#      overwriting makes both unrecoverable.
#
# Install to /usr/local/bin/kavo-archive-wal.sh, owned by postgres, mode 0755.
set -Eeuo pipefail

WAL_PATH="${1:?usage: kavo-archive-wal.sh <path> <filename>}"
WAL_NAME="${2:?usage: kavo-archive-wal.sh <path> <filename>}"

ARCHIVE_DIR="${KAVO_WAL_ARCHIVE_DIR:-/var/backups/kavo/wal}"
S3_BUCKET="${BACKUP_S3_BUCKET:-}"
S3_PREFIX="${BACKUP_S3_PREFIX:-kavo}"

mkdir -p "$ARCHIVE_DIR"

DESTINATION="${ARCHIVE_DIR}/${WAL_NAME}"

# Already archived: succeed only if it is byte-identical. Postgres can legally
# re-archive a segment after a crash, but differing content means two clusters
# are writing here and the archive is no longer trustworthy.
if [ -f "$DESTINATION" ]; then
  if cmp -s "$WAL_PATH" "$DESTINATION"; then
    exit 0
  fi
  echo "kavo-archive-wal: ${WAL_NAME} already archived with different content; refusing to overwrite" >&2
  exit 1
fi

# Write beside the target then rename: a rename within one filesystem is
# atomic, so an interrupted copy can never look like a complete segment.
TEMP="${DESTINATION}.partial.$$"
trap 'rm -f "$TEMP"' EXIT

cp "$WAL_PATH" "$TEMP"
sync "$TEMP" 2>/dev/null || true
mv "$TEMP" "$DESTINATION"
trap - EXIT

# Off-box. A WAL archive on the same disk as the database protects against
# DROP TABLE and nothing else — losing the VPS is the failure that matters on
# a single-box deployment.
if [ -n "$S3_BUCKET" ]; then
  if ! aws s3 cp --only-show-errors "$DESTINATION" "s3://${S3_BUCKET}/${S3_PREFIX}/wal/${WAL_NAME}"; then
    # Exit non-zero so Postgres retains the segment and retries. Reporting
    # success here would delete the only copy that ever left the box.
    echo "kavo-archive-wal: upload of ${WAL_NAME} failed" >&2
    exit 1
  fi
fi

exit 0
