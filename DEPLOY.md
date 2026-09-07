# Deploying RE-MARKET to an Ubuntu server on your LAN

Assumes a clean Ubuntu 22.04 or 24.04 box you can SSH into, and that the code
is on GitHub at `TanevAnton/re-market`.

Everything below is run **on the server** unless it says otherwise.

---

## 0. First, push from Windows

The repository has no remote yet. From `C:\Users\Matrix\Projects\remarket\REMARKET`:

```powershell
# junk from the PowerShell quoting mishaps, if still present
Remove-Item ".\'active'])", ".\forceDelete()" -ErrorAction SilentlyContinue
Move-Item env-example.txt .env.example -Force -ErrorAction SilentlyContinue

# this must print NOTHING - if .env appears, stop and rotate your salt first
git ls-files | Select-String -Pattern '^\.env$|^vendor/|^node_modules/'

git add -A
git commit -m "RE-MARKET: catalogue, listings, offers, deals"
git remote add origin https://github.com/TanevAnton/re-market.git
git branch -M main
git push -u origin main
```

## 1. Packages

Ubuntu ships PHP 8.3; this project wants 8.4, so add the PPA.

```bash
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update

sudo apt install -y nginx git unzip curl \
  php8.4-fpm php8.4-cli php8.4-pgsql php8.4-gd php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-intl php8.4-bcmath \
  postgresql postgresql-contrib

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 22 (only needed to build assets)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

`deploy.sh` verifies every extension it needs and names any that are missing,
so if one of these package names differs on your release you will get a clear
message rather than a blank 500.

## 2. Database

The schema needs `pg_trgm` and `unaccent`. Both live in `postgresql-contrib`,
installed above. Creating them as the superuser now means the migration does
not need elevated rights later.

```bash
sudo -u postgres psql <<'SQL'
CREATE USER remarket WITH PASSWORD 'pick-a-real-password';
CREATE DATABASE remarket OWNER remarket;
\c remarket
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
SQL
```

**sqlite will not work here**, and not as a preference: the schema uses jsonb
with GIN indexes, tsvector, partial unique indexes and CHECK constraints. The
migrations do not run at all without Postgres.

## 3. Deploy key and clone

A private repo needs a key the server can read with. Four commands:

```bash
ssh-keygen -t ed25519 -C "remarket-deploy" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

Copy that line into GitHub → the `re-market` repo → **Settings → Deploy keys →
Add deploy key**. Read-only is enough. Then:

```bash
sudo mkdir -p /var/www
sudo chown "$USER":"$USER" /var/www
git clone git@github.com:TanevAnton/re-market.git /var/www/remarket
cd /var/www/remarket
```

## 4. Configure

```bash
cp .env.example .env
php artisan key:generate
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # for PHONE_HASH_SALT
```

Edit `.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=http://192.168.x.x          # this server's LAN address

DB_CONNECTION=pgsql
DB_DATABASE=remarket
DB_USERNAME=remarket
DB_PASSWORD=the-password-from-step-2

PHONE_HASH_SALT=the-random-string-you-just-generated
```

`APP_DEBUG=false` matters more than it looks: with it on, any uncaught error
renders a page listing every environment variable — database password and
phone salt included — to whoever triggered it.

The salt is effectively permanent. Changing it later orphans every stored phone
hash and lets banned numbers register again.

Listing photos are served by a root-relative `/storage/...` URL, so they follow
whatever address you reach the site on. `APP_URL` only needs to be right for
links generated outside a request, such as e-mails.

## 5. PHP and nginx

```bash
sudo cp deploy/php-remarket.ini /etc/php/8.4/fpm/conf.d/99-remarket.ini
sudo systemctl restart php8.4-fpm

sudo cp deploy/nginx.conf /etc/nginx/sites-available/remarket
sudo ln -sf /etc/nginx/sites-available/remarket /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Check `ls /run/php/` and fix the socket path in the nginx file if your PHP is
not 8.4.

Both files set an upload ceiling, and **nginx must not be the smaller one**.
Its default is 1M, so a phone photo fails with a bare 413 before PHP is
reached — nothing appears in the Laravel log, because Laravel never ran.

## 6. Deploy

```bash
./deploy.sh
```

Installs dependencies, builds assets, migrates, seeds the cities and parts
catalogue, links storage, caches config and routes, and fixes permissions.
It is safe to re-run.

## 7. The scheduler

```bash
sudo cp deploy/remarket-scheduler.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now remarket-scheduler.timer
systemctl list-timers remarket-scheduler.timer
```

**Do not skip this.** Until it runs, `remarket:expire-offers` and
`remarket:lapse-deals` never fire. Offers sit "pending" past their TTL forever,
and — worse — no deal is ever marked abandoned, which means the completion rate
on every profile silently stops being true.

## 8. Open the port

```bash
sudo ufw allow 80/tcp
sudo ufw allow OpenSSH
sudo ufw enable
```

Then browse to `http://<server-ip>` from any machine on the network.

---

## Updating

```bash
cd /var/www/remarket
git pull
./deploy.sh
```

## First admin account

Register through the site, then:

```bash
php artisan tinker --execute="\App\Models\User::where('username','ivan')->update(['is_admin'=>true]);"
```

## New accounts' first listings are held for review

By design, the first two listings from a new account go to `pending_review`,
and there is **no moderation UI yet** — so they will not appear publicly and
the only way to approve one is Tinker. While testing, either turn the gate off:

```
NEW_ACCOUNT_MODERATED_LISTINGS=0
```

(then `php artisan config:cache`), or approve by hand:

```bash
php artisan tinker --execute="\App\Models\Listing::where('status','pending_review')->get()->each->forceFill(['status'=>'active'])->each->save();"
```

Note `forceFill`, not `update`: `status` is deliberately not mass-assignable,
and `update()` on it is a **silent no-op**. That bug already shipped once here
and left every published listing invisible.

## When something is wrong

```bash
tail -f storage/logs/laravel.log
sudo tail -f /var/log/nginx/remarket-error.log
sudo systemctl status php8.4-fpm
journalctl -u remarket-scheduler.service -n 50
```

| Symptom | Almost always |
|---|---|
| 500 on every page, blank log | `storage/` not writable by `www-data` — re-run `deploy.sh` with sudo available |
| Page loads, no styling | assets not built, or `public/build` missing — re-run `deploy.sh` |
| Photos 404 | `public/storage` symlink missing — `php artisan storage:link` |
| Uploads fail with 413 | `client_max_body_size` in nginx below `post_max_size` |
| Config changes ignored | config is cached — `php artisan config:cache` after every `.env` edit |
| Offers never expire | the scheduler timer is not enabled (step 7) |

## Not covered here

No HTTPS — this is a LAN deployment reached by IP, and a certificate needs a
real domain. No queue worker: nothing queues jobs yet, but the moment e-mail
notifications land you will need `php artisan queue:work` under systemd, or
they will be sent synchronously inside the web request. No backups — before
this holds anything you would miss, add a `pg_dump` cron.
