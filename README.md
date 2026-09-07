# RE-MARKET

A Bulgarian marketplace for used PC hardware and gaming gear — a vertical
alternative to OLX.bg, built around three things OLX cannot do:

- **A real parts catalogue.** Listings attach to known components, so you can
  filter by VRAM, TDP, socket, or the length that decides whether the card fits
  your case. Search works in Cyrillic, Latin and шльокавица — `ртх 4090`,
  `rtx4090`, `4090` and even `RTX 4O7O` all find the same card.
- **Offers instead of haggling.** The listing price is the only public number.
  The one way to propose a different one is a structured offer, and a seller
  can set a private floor below which offers are declined automatically and
  never reach their inbox.
- **Verified sellers.** Phone plus email, one phone per account, with new
  accounts' first listings held for review.

The platform never touches money. Buyer protection comes from the couriers'
open-and-test delivery (Еконт «Преглед и тест», Спиди «Отвори и тествай»),
which gives escrow-grade safety at zero regulatory cost.

**Status: pre-launch.** The core loop works end to end — register, post an ad
with photos, browse and filter, make and accept offers. Chat, ratings, the
moderation queue and courier integration are not built yet.

---

## Requirements

| | |
|---|---|
| PHP | 8.3+ (developed on 8.4) with `gd`, `exif`, `pdo_pgsql`, `intl` |
| PostgreSQL | **16 or newer** — see the note below |
| Node | 20+ |
| Composer | 2.x |

**PostgreSQL is not optional and sqlite will not do.** The schema uses `jsonb`
with GIN indexes, `tsvector` full-text search, `pg_trgm` fuzzy matching,
partial unique indexes and CHECK constraints. On sqlite the migrations do not
even run. The anti-spam rules in particular — one live offer per buyer per
listing, no self-offers, no floor above asking price — are enforced by database
constraints rather than application code, precisely so that a forgotten
validation call cannot bypass them.

On Windows, [Laravel Herd](https://herd.laravel.com) supplies PHP and Composer.
One catch that costs an hour if you hit it: Herd populates its `bin` folder on
**first launch**, not on install. If `php` is not found, open Herd once and
then open a new terminal.

## Setup

```bash
git clone https://github.com/TanevAnton/re-market.git
cd re-market

composer install
npm install

cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate
```

Then edit `.env`:

- `DB_PASSWORD` — your local Postgres password.
- `PHONE_HASH_SALT` — any long random string:
  `php -r "echo bin2hex(random_bytes(32));"`

Create the databases and run the migrations:

```bash
createdb -U postgres remarket
createdb -U postgres remarket_test

php artisan migrate --seed
php artisan storage:link
```

`--seed` loads 106 Bulgarian cities and 102 catalogue parts (GPUs and CPUs with
their aliases), plus demo users and listings so the browse page has something
to show.

## Running it

```powershell
.\dev.ps1        # or double-click dev.bat
```

That starts the PHP server and the Vite watcher together and stops both on
Ctrl+C. Then open http://127.0.0.1:8000.

Without the script: `php artisan serve` in one terminal, `npm run dev` in
another.

### Photo uploads on Windows

If uploads fail with "The photos failed to upload" and nothing in the log,
`upload_tmp_dir` is empty in your `php.ini`. PHP cannot create the temp file
even though the CLI writes to the same path fine. `fix-php-ini.ps1` in the repo
root sets that plus sane `upload_max_filesize`, `post_max_size` and
`memory_limit` values.

### Phone verification codes

With no SMS credentials configured, codes are written to the log instead of
being sent. `.\code.ps1` prints the latest one and copies it to the clipboard;
`.\code.ps1 -Watch` follows the log.

## Tests

```bash
php artisan test
```

111 tests against a real Postgres database (`remarket_test`), covering the
catalogue search, the browse facets, auth and phone verification, listing
visibility, and every offer rule — the floor, the cooldown, the per-listing
cap, the single counter, and what accepting does to everyone who did not win.

## Layout

```
app/
  Enums/                 listing / offer / deal states, each with its own rules
  Livewire/              one component per screen; they render, they do not
                         decide - see Services
  Models/
  Services/
    Images/              resize, thumbnail, EXIF strip, dHash fingerprint
    Offers/              every offer state transition, in one place
    Verification/        phone OTP with a Telegram -> Viber -> SMS cascade
  Support/SpecFilter     turns catalogue facets into SQL, safely
config/
  catalog.php            16 categories, 95 spec fields. Every filter in the app
                         is generated from this file.
  remarket.php           product rules that are decisions, not constants
database/migrations/     16 migrations; the constraints are the anti-spam layer
```

Two conventions worth knowing before you change anything:

**Money is always integer cents.** Never a float, never a decimal string. The
only place cents become euros is an accessor on the model.

**Listing and Offer state changes go through a service, not a component.**
`Listing.status` is deliberately not mass-assignable — a listing *is* built
from user input, and nobody should be able to post a pre-approved ad. Use
`forceFill([...])->save()`, and note that `update()` on a non-fillable
attribute is a silent no-op by default. That bug shipped once here and left
every published listing invisible.

## Licence

Private. All rights reserved.
