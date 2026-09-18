#!/usr/bin/env bash
#
# Circle — nightly backup.
#
#   ./scripts/backup.sh            take a backup now
#   ./scripts/backup.sh --verify   take one, then prove it restores
#
# Two things have to survive, and losing either one loses the record: the
# database, which holds the chain, and object storage, which holds the bytes the
# chain attests to. A backup of one without the other is a set of hashes with
# nothing to check, or a pile of files nobody can identify.
#
# Install as a cron entry on the host:
#   0 3 * * * cd /srv/circle && ./scripts/backup.sh >> /var/log/circle-backup.log 2>&1
#
# This writes to the same machine, which protects you from a bad migration and
# from nothing else. Copy ./backups off the box — to object storage in another
# region, or anywhere that is not this disk — or you do not have a backup, you
# have a second copy.

set -euo pipefail

COMPOSE="docker compose -f docker-compose.prod.yml"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${ROOT}/backups"
KEEP_DAYS="${CIRCLE_BACKUP_KEEP_DAYS:-14}"

mkdir -p "${OUT}"

say() { printf '\033[1m%s\033[0m\n' "$*"; }

# --- the database -----------------------------------------------------------
# Custom format rather than plain SQL: it restores selectively and compresses
# on the way out, and pg_restore will tell you the archive is damaged rather
# than failing halfway through replaying it.

say "Dumping Postgres"
DB_USER="$(${COMPOSE} exec -T postgres printenv POSTGRES_USER | tr -d '\r')"
DB_NAME="$(${COMPOSE} exec -T postgres printenv POSTGRES_DB | tr -d '\r')"

${COMPOSE} exec -T postgres pg_dump \
  --username="${DB_USER}" \
  --dbname="${DB_NAME}" \
  --format=custom \
  --compress=9 \
  > "${OUT}/circle-${STAMP}.dump"

DUMP_BYTES=$(wc -c < "${OUT}/circle-${STAMP}.dump")

# A dump that fails partway still leaves a file. Anything this small is not a
# database, and finding that out now beats finding out during a restore.
if [ "${DUMP_BYTES}" -lt 20000 ]; then
  echo "Dump is only ${DUMP_BYTES} bytes — treating as failed." >&2
  exit 1
fi

say "  ${OUT}/circle-${STAMP}.dump (${DUMP_BYTES} bytes)"

# --- the evidence vault -----------------------------------------------------
# Mirrored rather than archived: originals are immutable and a new version is a
# new object, so the vault only ever grows and a mirror is both cheaper and
# faster to restore from than a nightly tarball of the same unchanged bytes.

say "Mirroring object storage"
${COMPOSE} exec -T minio sh -c '
  mc alias set local http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null
  mc mirror --overwrite --quiet local/circle-evidence /data/.backup-mirror
' || { echo "Object storage mirror failed." >&2; exit 1; }

say "  mirrored inside the minio volume"

# --- retention --------------------------------------------------------------

say "Pruning dumps older than ${KEEP_DAYS} days"
find "${OUT}" -name 'circle-*.dump' -type f -mtime "+${KEEP_DAYS}" -print -delete || true

# --- proving it ------------------------------------------------------------
# An untested backup is a belief. This restores the dump into a scratch
# database and counts what came back, which is the cheapest thing that
# distinguishes a backup from a file.

if [ "${1:-}" = "--verify" ]; then
  say "Verifying the dump restores"

  ${COMPOSE} exec -T postgres psql --username="${DB_USER}" --dbname=postgres \
    -c 'DROP DATABASE IF EXISTS circle_verify;' -c 'CREATE DATABASE circle_verify;' >/dev/null

  ${COMPOSE} exec -T postgres pg_restore \
    --username="${DB_USER}" --dbname=circle_verify --no-owner \
    < "${OUT}/circle-${STAMP}.dump" >/dev/null 2>&1 || true

  EVENTS=$(${COMPOSE} exec -T postgres psql --username="${DB_USER}" --dbname=circle_verify \
    -tAc 'SELECT count(*) FROM audit_events;' | tr -d '\r')

  ${COMPOSE} exec -T postgres psql --username="${DB_USER}" --dbname=postgres \
    -c 'DROP DATABASE circle_verify;' >/dev/null

  if [ "${EVENTS}" -gt 0 ]; then
    say "  restored, ${EVENTS} audit events present"
  else
    echo "Restore produced no audit events — the dump is not usable." >&2
    exit 1
  fi
fi

say "Done."
