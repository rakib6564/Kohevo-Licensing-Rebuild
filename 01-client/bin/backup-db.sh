#!/usr/bin/env bash
#
# Slate — nightly database backup.
#
# Credentials come from the app's own .env, so there is only ever one copy of
# them and this script carries none. The app directory is a parameter rather
# than a hardcoded path: the previous version of this script on the server was
# pinned to one install, which meant a second app deployed alongside it
# silently had no backups at all.
#
# Usage:
#   bin/backup-db.sh                      # backs up the install it lives in
#   bin/backup-db.sh /path/to/app         # or a named one
#
# Environment:
#   BACKUP_DIR    where dumps are written (default ~/slate-backups)
#   BACKUP_KEEP   days to retain          (default 14)
#   BACKUP_LABEL  filename prefix         (default: the app directory's name)
#
# Cron (nightly at 03:25):
#   25 3 * * * /path/to/app/bin/backup-db.sh >> ~/slate-backups/backup.log 2>&1

set -euo pipefail

APP="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
OUT="${BACKUP_DIR:-$HOME/slate-backups}"
KEEP="${BACKUP_KEEP:-14}"
LABEL="${BACKUP_LABEL:-$(basename "$APP")}"

[ -f "$APP/.env" ] || { echo "backup: no .env at $APP" >&2; exit 1; }

mkdir -p "$OUT"

# shellcheck disable=SC1091
set -a; . "$APP/.env"; set +a
: "${DB_USER:?backup: DB_USER missing from $APP/.env}"
: "${DB_NAME:?backup: DB_NAME missing from $APP/.env}"

f="$OUT/${LABEL}_$(date +%F_%H%M%S).sql.gz"

# -p"$DB_PASS" on the command line is visible to any local user via `ps` for
# the life of the mysqldump process. A --defaults-extra-file keeps the
# password out of argv; mktemp's default 0600 mode (reinforced here) keeps it
# out of other users' reach on disk, and the trap removes it on every exit
# path, success or failure alike.
CNF="$(mktemp)"
trap 'rm -f "$CNF"' EXIT
chmod 600 "$CNF"
printf '[client]\nuser=%s\npassword=%s\n' "$DB_USER" "$DB_PASS" > "$CNF"

# --single-transaction keeps InnoDB consistent without locking the site out;
# --quick streams rather than buffering the whole table in memory.
mysqldump --defaults-extra-file="$CNF" "$DB_NAME" --single-transaction --quick | gzip > "$f"

# A dump that died midway still leaves a structurally valid .gz, so a non-zero
# file size proves nothing. mysqldump writes a "Dump completed" trailer only on
# a clean finish — that is the thing worth checking.
if ! zcat "$f" | tail -2 | grep -q "Dump completed"; then
    echo "backup: TRUNCATED dump, keeping $f for inspection" >&2
    exit 1
fi

# Prune only this label's dumps. A shared backup directory holds other apps'
# files, and a bare '*.sql.gz' here would delete theirs on our retention clock.
find "$OUT" -name "${LABEL}_*.sql.gz" -mtime +"$KEEP" -delete

echo "backup: $(basename "$f") ($(du -h "$f" | cut -f1)), keeping ${KEEP}d"
