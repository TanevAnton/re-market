# Bulk import

Getting real stock onto the site without twenty-five evenings of wizard.

```bash
php artisan remarket:import-listings docs/bulk-import.csv --user=re-tech --dry-run
php artisan remarket:import-listings docs/bulk-import.csv --user=re-tech
```

**Always dry-run first.** It reads and validates every row and writes nothing,
which is how a misspelt category or a missing photograph gets found before two
hundred listings exist rather than after.

`--force` skips the confirmation. It is there for terminals where a prompt
cannot run at all — Symfony saves and restores the terminal with `stty -g`
around a question, and some shells reject that string with „invalid argument",
which kills the command before it can ask. Do **not** reach for
`--no-interaction` instead: that answers the question with its default, which is
*no*, so the import quietly does nothing and still exits successfully.

## The file

`docs/bulk-import.csv` is a working example. Semicolon or comma separated —
the delimiter is sniffed from the header, so whatever Excel wrote is fine, and
a UTF-8 BOM is stripped rather than becoming part of the first column's name.

**Inside a cell, list things with `|`.** Photos and delivery options can hold
several values, and `;` or `,` there would collide with whichever one the file
is using as its delimiter.

| column | required | notes |
|---|---|---|
| `ref` | ✔ | Your stock number. **This is what makes a re-run safe** — see below. |
| `category` | ✔ | A key from `config/catalog.php`: `gpu`, `cpu`, `psu`, … |
| `title` | ✔ | 10–120 characters. |
| `description` | ✔ | 30–5000 characters. |
| `price` | ✔ | Euros. `1299,50` and `1299.50` both work; spaces are ignored. |
| `condition` | ✔ | `new`, `like_new`, `used`, `for_parts`. |
| `images` | ✔ | Filenames in the photo folder, separated by `\|`. At least one. |
| `model` | | Matched against the catalogue. See matching, below. |
| `city` | | Slug or Bulgarian name. Falls back to the seller's own city. |
| `min_offer` | | The private offer floor. Must not exceed `price`. |
| `quantity`, `offers_enabled`, `has_receipt`, `accepts_inspect_test` | | `да`/`не`, `1`/`0`, `true`/`false`. |
| `mining_use`, `mining_months` | | `no` / `yes` / `unknown`. |
| `warranty_until` | | `2027-04-01`. |
| `validation_url` | | 3DMark / CPU-Z link. |
| `delivery` | | `econt`, `speedy`, `pickup`, separated by `\|`. Defaults to `econt`. |
| `spec:<key>` | | A **listing-scoped** spec of that category, e.g. `spec:power_on_hours`. |

Photos resolve against `--photos=`, defaulting to the folder the CSV is in.

## The three things worth understanding

**Re-running is safe, and `ref` is why.** Two hundred rows will not all be right
the first time, and the natural response to a bad report is to fix the file and
run it again. A row whose `ref` already exists for that seller is skipped, so
the second run creates only what is missing. `--update` corrects them instead,
going through `ListingService` so the offer-floor guard applies and anyone who
favourited the listing hears about a price cut.

`--update` deliberately cannot change the catalogue model or the category. That
is the site's own rule, not an oversight — re-attaching a listing to a model
row created since is what `/katalog` is for, and that route also teaches the
search box the spellings.

**Model matching is exact or nothing.** An alias, the model name, or
manufacturer + model. Never a fuzzy match: the search box may guess because a
person is reading the result and will notice, but nothing reads an import. A
fuzzy match quietly attaches three hundred cards to the wrong model page, with
the wrong price band and the wrong specifications, and no page looks wrong.

What does not match is not an error. The string goes into `custom_part` and
turns up in the promotion queue at `/katalog` — exactly where it would land if
a seller had typed it into the wizard.

**A row with no photograph fails.** It is not published quietly. A listing with
no photo does not sell, and a hundred of them would make the site look like a
scrape rather than a shop.

## What the import does not skip

The same new-account moderation gate the wizard applies, and the same perceptual
-hash screen against every other listing on the site. An importer that waved its
listings past both would be the one route onto the site that bypasses the best
anti-spam measure there is — and a bulk-import account is precisely what an
attacker would want.

In practice that means the first two listings from a fresh account land in the
moderation queue. Approve them and the rest publish directly.
