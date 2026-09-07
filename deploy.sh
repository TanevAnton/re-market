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

green "==> Database"
php artisan migrate --force

# Cities and the parts catalogue are reference data, not demo data - the site
# cannot filter or search without them. Seeding is safe to repeat.
php artisan db:seed --force --class=Database\\Seeders\\CitySeeder
php artisan db:seed --force --class=Database\\Seeders\\PartSeeder

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

green "==> Permissions"
# php-fpm writes logs, compiled views, sessions and uploads as www-data.
# Everything else stays owned by the deploying user so git pull keeps working.
if [ "$(id -u)" -eq 0 ] || sudo -n true 2>/dev/null; then
    sudo chgrp -R www-data storage bootstrap/cache
    sudo chmod -R ug+rwX storage bootstrap/cache
else
    warn "No sudo - skipping ownership fix. If you see 'failed to open stream:"
    warn "Permission denied', run:  sudo chgrp -R www-data storage bootstrap/cache"
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
