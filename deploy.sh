#!/usr/bin/env bash
#
# RE-MARKET deploy. Run from the project root on the server, after a git pull.
#
#   ./deploy.sh
#
# Idempotent: safe to run again after a failed run, and safe to run when
# nothing changed. It never touches .env - that file is the one thing that
# differs per machine and it is not in the repository.

set -euo pipefail

cd "$(dirname "$0")"

green() { printf '\033[32m%s\033[0m\n' "$1"; }
warn()  { printf '\033[33m%s\033[0m\n' "$1"; }
die()   { printf '\033[31m%s\033[0m\n' "$1" >&2; exit 1; }

[ -f artisan ] || die "No artisan file here - run this from the project root."
[ -f .env ]    || die "No .env. Copy .env.example to .env and fill it in first."

# --- the checks that turn a confusing 500 into a clear message -------------

for cmd in php composer npm; do
    command -v "$cmd" >/dev/null || die "'$cmd' is not installed."
done

# Named so the error says which one. A missing pdo_pgsql presents as a
# database connection failure; a missing gd presents as photo uploads dying
# with no useful log line.
MISSING=""
for ext in pdo_pgsql gd exif mbstring xml curl zip intl bcmath fileinfo; do
    php -m | grep -qi "^${ext}$" || MISSING="$MISSING $ext"
done
[ -z "$MISSING" ] || die "Missing PHP extensions:$MISSING
Install them, e.g.  sudo apt install php8.4-{pgsql,gd,mbstring,xml,curl,zip,intl,bcmath}"

green "==> Installing PHP dependencies"
# --no-dev because faker, phpunit and pint have no business on a server.
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

green "==> Building assets"
# npm ci, not npm install: it installs exactly what package-lock.json says and
# fails loudly if the lockfile disagrees, rather than quietly resolving to
# something the version you tested was never built against.
npm ci
npm run build

green "==> Permissions"
#
# BEFORE THE FIRST ARTISAN CALL, NOT AFTER THE LAST ONE.
#
# This block used to sit near the end, which meant it could not do its job:
# `php artisan migrate` runs above it, and the very first thing any artisan
# command does on an error is write to storage/logs/laravel.log. If that file
# is owned by www-data and the deploying user is not in that group, the command
# dies with "failed to open stream: Permission denied" - and the fix for that
# was three steps further down the same script.
#
# php-fpm writes logs, compiled views, sessions and uploads as www-data.
# Everything else stays owned by the deploying user so git pull keeps working.
if [ "$(id -u)" -eq 0 ] || sudo -n true 2>/dev/null; then
    sudo chgrp -R www-data storage bootstrap/cache
    sudo chmod -R ug+rwX storage bootstrap/cache

    # setgid on the directories, which is the part that makes it STAY fixed.
    # Without it every file created afterwards - a new day's log, a compiled
    # view, an uploaded photo - gets the creating user's primary group instead,
    # and whichever of php-fpm or the deploying user did not create it cannot
    # write to it. That is why this keeps coming back after being "fixed".
    sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;

    # And the deploying user needs to BE in the group for any of it to help.
    # Takes effect on the next login, so it is reported rather than assumed.
    if ! id -nG "$(id -un)" | tr ' ' '\n' | grep -qx www-data; then
        sudo usermod -aG www-data "$(id -un)" \
            && warn "Added $(id -un) to the www-data group. Log out and back in for it to take effect."
    fi
else
    warn "No sudo - skipping the ownership fix. If artisan dies with"
    warn "'failed to open stream: Permission denied', run:"
    warn "  sudo chgrp -R www-data storage bootstrap/cache"
    warn "  sudo chmod -R ug+rwX storage bootstrap/cache"
    warn "  sudo find storage bootstrap/cache -type d -exec chmod g+s {} \\;"
fi

green "==> Database"
php artisan migrate --force

# Cities and the parts catalogue are reference data, not demo data - the site
# cannot filter or search without them. Seeding is safe to repeat.
#
# ALL FOUR, in this order. This listed only the first two for a while, which is
# a failure with no symptom: the site comes up, the pages render, and eleven
# categories simply have no catalogue behind them - no model pages, no price
# bands, no spec filters - while the two that were seeded look perfect. Nothing
# errors, nothing is logged, and the deploy prints green.
#
# The same shape as the bugs in the status doc: a queue nothing filled, a field
# that went nowhere. If a seeder is added to DatabaseSeeder, it belongs here too.
php artisan db:seed --force --class=Database\\Seeders\\CitySeeder
php artisan db:seed --force --class=Database\\Seeders\\PartSeeder
php artisan db:seed --force --class=Database\\Seeders\\PartCatalogueSeeder
php artisan db:seed --force --class=Database\\Seeders\\AppleCatalogueSeeder

green "==> Storage"
# Listing photos live in storage/app/public and are served through this link.
# Without it every image on the site 404s while the files sit there untouched.
[ -L public/storage ] || php artisan storage:link

green "==> Caches"
# optimize:clear first: a cached config written before .env changed is the
# classic "why is it still using the old database".
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

green "==> Restarting the queue worker"
# Not optional. The worker boots the framework once and holds it in memory, so
# an unrestarted worker goes on running the code from before this pull -
# including the old notification classes. Skipping this is how a deploy
# "works" while notifications keep being sent from last week's code.
if systemctl list-unit-files 2>/dev/null | grep -q '^remarket-queue.service'; then
    # restart, not `queue:restart`: that only asks the worker to exit and
    # relies on systemd to bring it back, which is the same thing with an
    # extra way to fail.
    sudo systemctl restart remarket-queue || warn "Could not restart remarket-queue."
    sleep 1
    systemctl is-active --quiet remarket-queue \
        || warn "remarket-queue is NOT running. Notifications will queue up and never send.
Check:  journalctl -u remarket-queue -n 50"
else
    warn "remarket-queue.service is not installed. Every notification will be"
    warn "written to the jobs table and never sent - silently. See DEPLOY.md §7b:"
    warn "  sudo cp deploy/remarket-queue.service /etc/systemd/system/"
    warn "  sudo systemctl daemon-reload && sudo systemctl enable --now remarket-queue"
fi

green "==> Reloading PHP-FPM"
if systemctl list-units --type=service 2>/dev/null | grep -q php.*fpm; then
    sudo systemctl reload "$(systemctl list-units --type=service --plain --no-legend \
        | grep -o 'php[0-9.]*-fpm.service' | head -1)" || warn "Could not reload php-fpm."
else
    warn "php-fpm service not found - reload it yourself if you use one."
fi

green ""
green "Done. If this is the first deploy, check DEPLOY.md for the nginx site,"
green "the scheduler timer, and the firewall rule."
