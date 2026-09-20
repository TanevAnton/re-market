#!/usr/bin/env bash
#
# RE-MARKET — restore, and the rehearsal of one.
#
#   ./deploy/restore.sh                       # rehearse into a scratch database
#   ./deploy/restore.sh --file db_2026-09-20_0310.dump
#   ./deploy/restore.sh --into remarket --i-mean-it   # the real thing
#
# THIS SCRIPT EXISTS TO BE RUN WHEN NOTHING IS WRONG. A backup that has never
# been restored is a file with a reassuring name: dumps that turn out to be
# empty, a pg_restore that needs an extension nobody installed, a photo archive
# that unpacks one directory deeper than expected — none of that shows up in the
# backup log, and all of it shows up at the worst possible moment, in a hurry,
# with real data already gone.
#
# So the DEFAULT is a rehearsal. It restores into a scratch database beside the
# real one, counts what came back, and drops it again. Nothing it does by
# default can hurt the live site, which is the point: a safe rehearsal is a
# rehearsal that actually gets done.
#
# Writing over the live database needs --into <name> AND --i-mean-it, spelled
# out, because that is the one command here that destroys data.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/remarket}"
DEST="${BACKUP_DIR:-/var/backups/remarket}"

SCRATCH="remarket_restore_test"
TARGET=""
DUMP=""
CONFIRMED=0

while [ $# -gt 0 ]; do
    case "$1" in
        --file)      DUMP="$2"; shift 2 ;;
        --into)      TARGET="$2"; shift 2 ;;
        --i-mean-it) CONFIRMED=1; shift ;;
        -h|--help)   sed -n '2,22p' "$0"; exit 0 ;;
        *)           echo "unknown argument: $1" >&2; exit 2 ;;
    esac
done

say() { echo "[restore] $*"; }
die() { echo "[restore] FAILED: $*" >&2; exit 1; }

env_value() {
    sed -n "s/^${1}=//p" "$APP_DIR/.env" | head -n1 \
        | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

[ -f "$APP_DIR/.env" ] || die "no .env at $APP_DIR"

LIVE_DB="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"
DB_HOST="$(env_value DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_value DB_PORT)"; DB_PORT="${DB_PORT:-5432}"

PGPASSWORD="$(env_value DB_PASSWORD)"
export PGPASSWORD

psql_do() { psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -v ON_ERROR_STOP=1 "$@"; }

# --- which dump --------------------------------------------------------------

if [ -z "$DUMP" ]; then
    DUMP="$(find "$DEST" -maxdepth 1 -name 'db_*.dump' | sort | tail -n1)"
    [ -n "$DUMP" ] || die "no dumps in $DEST — has backup.sh ever run?"
fi

[ -f "$DUMP" ] || die "$DUMP does not exist"

say "using $(basename "$DUMP") ($(du -h "$DUMP" | cut -f1), written $(date -r "$DUMP" '+%Y-%m-%d %H:%M'))"

# How old is it? A restore rehearsal that silently uses a dump from March is a
# rehearsal that proves nothing about last night's backup.
AGE_DAYS=$(( ( $(date +%s) - $(date -r "$DUMP" +%s) ) / 86400 ))
[ "$AGE_DAYS" -le 2 ] || say "WARNING: this dump is $AGE_DAYS days old. Is the timer running?"

# --- where to -----------------------------------------------------------------

REHEARSAL=1

if [ -n "$TARGET" ] && [ "$TARGET" != "$SCRATCH" ]; then
    REHEARSAL=0

    [ "$CONFIRMED" = 1 ] || die "restoring over '$TARGET' destroys what is in it. Add --i-mean-it."

    if [ "$TARGET" = "$LIVE_DB" ]; then
        say "*** THIS WILL REPLACE THE LIVE DATABASE '$LIVE_DB' ***"
        say "Stop the site first, or you will be restoring under live writes:"
        say "    sudo systemctl stop remarket-queue nginx"
        printf '[restore] type the database name to continue: '
        read -r typed
        [ "$typed" = "$TARGET" ] || die "not confirmed"
    fi
else
    TARGET="$SCRATCH"
fi

# --- do it -------------------------------------------------------------------

say "restoring into $TARGET"

# A rehearsal that fails halfway must not leave its scratch database lying
# around — the next person to look at the server finds `remarket_restore_test`
# beside `remarket` and has to work out which one is real. Only the scratch one
# is ever cleaned up this way; a failed restore into a named database is left
# exactly as it is, because somebody is standing there and needs to see it.
if [ "$REHEARSAL" = 1 ]; then
    trap 'psql_do -d postgres -c "DROP DATABASE IF EXISTS \"$SCRATCH\"" >/dev/null 2>&1 || true' EXIT
fi

psql_do -d postgres -c "DROP DATABASE IF EXISTS \"$TARGET\"" >/dev/null
psql_do -d postgres -c "CREATE DATABASE \"$TARGET\" OWNER \"$DB_USER\"" >/dev/null

# pg_trgm lives in the database, not in the dump's schema, and search silently
# stops matching anything without it. This is exactly the kind of thing only a
# real restore finds.
psql_do -d "$TARGET" -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm' >/dev/null

# --exit-on-error so a broken restore fails here rather than leaving a database
# that is 80% there and looks fine until somebody opens the wrong page.
pg_restore \
    --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USER" \
    --dbname="$TARGET" --no-owner --no-privileges --exit-on-error \
    "$DUMP" || die "pg_restore failed"

# --- did the data actually come back? ----------------------------------------
#
# The part that makes this a test rather than a command. „pg_restore exited 0"
# means the file parsed; these counts mean the site has something to show.

say ""
say "what came back:"

# Counted one table at a time, with an existence check in front of each, rather
# than as one UNION query.
#
# The UNION version aborted the whole script the moment any single table was
# missing, printing a Postgres syntax error and nothing else — so the run that
# most needed a diagnosis („which tables are missing?") produced the least
# information. It also meant that renaming a table in a future migration would
# silently break the restore script, and you would find that out during an
# actual restore.
MISSING=0

for table in users listings listing_images parts offers deals messages \
             threads bundles moderation_items; do

    exists="$(psql_do -d "$TARGET" -At -c "SELECT to_regclass('public.$table') IS NOT NULL")"

    if [ "$exists" != "t" ]; then
        printf '[restore]   %-20s MISSING\n' "$table"
        MISSING=$((MISSING + 1))
        continue
    fi

    count="$(psql_do -d "$TARGET" -At -c "SELECT count(*) FROM \"$table\"")"
    printf '[restore]   %-20s %s\n' "$table" "$count"
done

say ""

if [ "$MISSING" -gt 0 ]; then
    say "WARNING: $MISSING expected table(s) are not in this dump."
    say "         Either it is from an older schema, or it is not a RE-MARKET dump."
fi

if [ "$REHEARSAL" = 1 ]; then
    say "Compare those against the live database:"
    say "    psql -d $LIVE_DB -c 'SELECT count(*) FROM listings'"
    say ""

    # The photo archive is half the restore and the half people forget, so the
    # rehearsal checks it can be read and says how many files are in it — it
    # does NOT unpack it over the live storage directory.
    ARCHIVE="$(find "$DEST" -maxdepth 1 -name 'storage_*.tar.*' | sort | tail -n1)"

    if [ -n "$ARCHIVE" ]; then
        COUNT="$(tar -tf "$ARCHIVE" | wc -l)"
        say "image archive $(basename "$ARCHIVE") reads back, $COUNT entries"
    else
        say "WARNING: no image archive found. A database-only restore gives you"
        say "         every listing with a broken photograph."
    fi

    say ""
    say "dropping the scratch database"
    psql_do -d postgres -c "DROP DATABASE \"$TARGET\"" >/dev/null
    say "rehearsal complete — nothing on the live site was touched"
else
    say "Restored. Still to do, in this order:"
    say "  1. unpack the images:  tar -C $APP_DIR/storage/app -xf $DEST/storage_<stamp>.tar.zst"
    say "  2. fix ownership:      sudo chown -R www-data:www-data $APP_DIR/storage/app/public"
    say "  3. php artisan optimize:clear"
    say "  4. sudo systemctl start remarket-queue nginx"
    say "  5. php artisan remarket:doctor"
fi
