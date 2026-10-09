#!/usr/bin/env bash
#
# RE-MARKET — nightly backup.
#
#   sudo cp deploy/remarket-backup.* /etc/systemd/system/
#   sudo systemctl daemon-reload
#   sudo systemctl enable --now remarket-backup.timer
#
# WHAT GOES WRONG WITHOUT THIS, and it is not the obvious one. A disk failure is
# rare and loud. What is common and quiet: a bad migration, a mistyped `DELETE`,
# a seeder run against the wrong database, or `remarket:demo-clear` pointed at
# the box that holds real listings. All of those are Tuesday afternoon, and all
# of them are survivable with a dump from Monday night and unsurvivable without.
#
# TWO THINGS ARE BACKED UP, AND MISSING THE SECOND IS THE CLASSIC MISTAKE.
# Listing photographs are NOT in the database — they are files under
# storage/app/public, and the rows only carry their paths. A database-only
# backup restores a site where every listing exists, every price is right, and
# every photograph is a broken image. On a marketplace where the photograph IS
# the listing, that is barely better than nothing.
#
# WHAT IS DELIBERATELY NOT BACKED UP: `.env`. It holds the database password,
# the SMTP password and the Telegram bot token, and a copy of it sitting next to
# a database dump turns one stolen archive into a full compromise. Keep it in a
# password manager. The restore procedure in DEPLOY.md assumes you have it.
#
# A BACKUP NOBODY HAS RESTORED IS A FILE, NOT A BACKUP. This script verifies
# every dump it writes and refuses to rotate anything away until it has. That
# catches truncation and a half-written file; it does NOT prove the data is
# usable. Only deploy/restore.sh does that, and it is meant to be run on purpose,
# by a person, more than once a year.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/remarket}"
DEST="${BACKUP_DIR:-/var/backups/remarket}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"

# Where a copy goes so that losing the machine does not lose the backups. A
# dump on the same disk as the database it came from protects you from mistakes
# but not from hardware. rsync target, scp target, or a mounted NAS path.
OFFSITE="${BACKUP_OFFSITE:-}"

STAMP="$(date +%Y-%m-%d_%H%M)"
LOG_TAG="remarket-backup"

say()  { echo "[$LOG_TAG] $*"; }
die()  { echo "[$LOG_TAG] FAILED: $*" >&2; exit 1; }

# --- read the credentials from .env, without printing them -------------------
#
# Parsed rather than sourced: `source .env` executes whatever is in the file,
# and a password containing a backtick or $( would run as a command.

env_value() {
    local key="$1"
    sed -n "s/^${key}=//p" "$APP_DIR/.env" \
        | head -n1 \
        | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

[ -f "$APP_DIR/.env" ] || die "no .env at $APP_DIR — is APP_DIR right?"

DB_NAME="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"
DB_HOST="$(env_value DB_HOST)"
DB_PORT="$(env_value DB_PORT)"

[ -n "$DB_NAME" ] || die "DB_DATABASE is empty in .env"

PGPASSWORD="$(env_value DB_PASSWORD)"
export PGPASSWORD

mkdir -p "$DEST"
chmod 700 "$DEST"

DUMP="$DEST/db_${STAMP}.dump"
FILES="$DEST/storage_${STAMP}.tar.zst"

# --- the database ------------------------------------------------------------
#
# -Fc (custom format) rather than plain SQL: it is compressed, and pg_restore
# can read it selectively — one table back without replaying the whole file is
# the difference between a five-minute fix and an evening.

say "dumping $DB_NAME"

pg_dump \
    --format=custom \
    --compress=9 \
    --no-owner \
    --no-privileges \
    --host="${DB_HOST:-127.0.0.1}" \
    --port="${DB_PORT:-5432}" \
    --username="$DB_USER" \
    --dbname="$DB_NAME" \
    --file="$DUMP" \
    || die "pg_dump failed"

# Verify before anything is rotated away. pg_restore --list reads the archive's
# table of contents end to end, so a truncated or half-written dump fails here
# rather than on the night it is needed.
TABLES="$(pg_restore --list "$DUMP" | grep -c 'TABLE DATA' || true)"

if [ "$TABLES" -lt 10 ]; then
    # MOVED ASIDE, NOT LEFT WHERE IT IS. Found by testing this script against a
    # deliberately tiny database: the check fired correctly, said "refusing to
    # trust it" and exited — and left the bad file in the backup directory,
    # where it was now the NEWEST dump and therefore the one restore.sh picks
    # by default. A verification that rejects a file and then leaves it as the
    # default restore candidate is worse than no verification at all, because
    # it reads like the problem was handled.
    #
    # Kept rather than deleted, under a name that cannot match `db_*.dump`:
    # why a dump came out wrong is worth being able to look at.
    mv "$DUMP" "${DUMP}.rejected"
    die "dump lists only $TABLES tables — refusing to trust it (kept as ${DUMP##*/}.rejected)"
fi

say "dump verified: $TABLES tables, $(du -h "$DUMP" | cut -f1)"

# --- the photographs ---------------------------------------------------------

if [ -d "$APP_DIR/storage/app/public" ]; then
    say "archiving listing images"

    # zstd if present, gzip otherwise: a backup script must not fail because a
    # compressor is missing on a machine somebody rebuilt.
    if command -v zstd >/dev/null 2>&1; then
        tar -C "$APP_DIR/storage/app" -I 'zstd -10' -cf "$FILES" public \
            || die "tar of storage/app/public failed"
    else
        FILES="${FILES%.zst}.gz"
        tar -C "$APP_DIR/storage/app" -czf "$FILES" public \
            || die "tar of storage/app/public failed"
    fi

    tar -tf "$FILES" >/dev/null || die "image archive will not read back"

    say "images verified: $(du -h "$FILES" | cut -f1)"
else
    say "WARNING: $APP_DIR/storage/app/public does not exist — no images backed up"
fi

chmod 600 "$DEST"/*_"${STAMP}".* 2>/dev/null || true

# --- off the machine ---------------------------------------------------------

if [ -n "$OFFSITE" ]; then
    say "copying to $OFFSITE"
    rsync -a --chmod=F600 "$DUMP" "$FILES" "$OFFSITE/" \
        || say "WARNING: off-site copy FAILED — the local copy is still good"
else
    say "WARNING: BACKUP_OFFSITE is not set. These backups are on the same"
    say "         machine as the database, so they survive a mistake but not"
    say "         a dead disk."
fi

# --- rotation ----------------------------------------------------------------
#
# Last, and only after everything above succeeded: a script that deletes old
# backups before proving it wrote a new one is a script that will one day leave
# you with none at all.

find "$DEST" -maxdepth 1 -name 'db_*.dump'      -mtime "+$KEEP_DAYS" -delete
find "$DEST" -maxdepth 1 -name 'storage_*.tar.*' -mtime "+$KEEP_DAYS" -delete

REMAINING="$(find "$DEST" -maxdepth 1 -name 'db_*.dump' | wc -l)"

say "done — $REMAINING dumps kept in $DEST"
