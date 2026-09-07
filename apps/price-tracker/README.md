# Re-Tech component price tracker

Daily Bulgaria PC-component observations for future configurator pricing and resale estimates. Python 3.12; GitHub Actions; SQLite history; CSV/JSON output. **This app never modifies Shopify prices.**

## Open the results

- [Latest report and downloadable files](https://github.com/TanevAnton/re-market/tree/price-data)
- [Daily run status and manual Run workflow button](https://github.com/TanevAnton/re-market/actions/workflows/price-tracker.yml)
- [Editable model catalog](config/catalog.json)
- [Source settings and delivery evidence](config/sources.json)

The `price-data` branch is created by the first completed collection attempt. The initial catalog covers **126 starter models across 19 categories**, not every product on the internet. Add models as Re-Tech's inventory and configurator change. There is no paid scraper subscription or store credential requirement.

## Sources and current limits

| Source | What it provides | Validation at implementation |
|---|---|---|
| OLX.bg | Asking prices, new/used condition, listing URL, delivery badge | Live DOM parser tested 2026-09-07; GitHub runner access checked by each run |
| Desktop.bg | Retail price, currency, availability, SKU, condition | Live search microdata tested 2026-09-07; [nationwide delivery](https://laptop.bg/pages/delivery) |
| Pazaruvaj.com | Structured product/aggregate offers when accessible | **Provisional**: browser security challenge prevented live adapter validation; failures are visible, not fabricated prices |
| Ardes.bg | Optional additional retailer | **Disabled** pending accessible product pages and parser validation; [Bulgaria delivery evidence](https://ardes.bg/periferiya/slushalki/steelseries) |

Requests respect robots.txt, use an identifying user agent and at least 3 seconds between same-host requests. A rejection, security challenge, unknown layout or robots error stops that source for the run. There are no proxy rotations, CAPTCHA bypasses or authenticated/private API scraping. When a source denies collection, obtain an approved feed/API or permission before adding an alternative adapter. No site is assumed accessible from GitHub solely because it loaded in a browser.

The default is at most two result pages per model/source, following observed next links. `source_status.csv` flags the page cap. This is a bounded market sample, not a complete catalog crawl or an unbiased sample of all offers. Retail products can also be mapped to exact URLs with `source_urls` in the catalog. Shipping evidence is dated in source settings and should be rechecked when retailer terms change.

## Price quality rules

- RTX 3060, 3060 Ti, and 8/12 GB variants remain separate. Missing VRAM goes to review.
- CPUs distinguish suffixes such as X, X3D, F and K. Packaging (tray/boxed/MPK), cooler inclusion and warranty still require review.
- Other categories require the configured model/specification tokens. `spec_match` is not a guarantee of identical MPN, revision, colour, kit size, keyboard layout or warranty.
- Full PCs, laptops, obviously broken parts, wanted ads and accessories are rejected. Possible bundles, missing condition, unconfirmed stock and unconfirmed delivery go to `review.csv`.
- Only OLX ads with a delivery badge enter benchmarks. Sellers without the badge remain in review; they may still ship after individual confirmation.
- Retail and used figures remain separate. OLX prices are **asking prices**, never sold prices or guaranteed achievable selling prices.
- Prices are normalized to EUR, retaining original amount/currency; historical BGN uses 1 EUR = 1.95583 BGN.
- Unknown shipping stays blank. A blank delivered price never means free shipping. Shipping is not currently extracted automatically.
- One observation per listing/model/day; repeated promoted listings are deduplicated by canonical URL. Known public merchant identities are deduplicated per model/condition; anonymous OLX cross-posting cannot always be detected.
- Only listings seen in the **current successful query** and within 36 hours enter the current summary. A failed or partly failed query produces no current benchmark; history remains intact. An absent listing is not labelled sold.
- At least three observations and a reasonable interquartile spread are needed for `eligible_for_review`. Confidence is a heuristic, not statistical certainty. Large outliers are screened only with five or more observations.
- `eligible_for_review` means worth human review, **not permission for automated repricing**. The future integration must check exact SKU, condition, delivery, warranty, source health, freshness, costs and margins.

## Run locally

```bash
cd apps/price-tracker
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
python -m unittest discover -s tests -v
python -m tracker --model rtx-3060-12gb --max-pages 1
python -m tracker
```

Offline smoke test (saved as **fixture**, never a live result):

```bash
python -m tracker --fixtures tests/fixtures --model rtx-3060-12gb --source olx --database /tmp/fixture-prices.sqlite --output /tmp/fixture-output
```

## Scheduling and persistence

The workflow runs at **04:23 UTC daily** (07:23 Bulgarian summer time / 06:23 winter), on a relevant push to `main`, or manually. Pull requests only run tests. [GitHub schedules can be delayed](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#schedule); they are not an exact-time SLA.

Each run restores the SQLite database from `price-data`, collects observations, and commits results back to that branch. Code remains on `main`. CSV/JSON reports, the database and workflow artifacts make data portable. The repository is public: published observations are public; never add purchasing costs, private notes, customer information or secrets to these exports.

If all sources fail, the run publishes diagnostic output and then fails visibly. Partial source failures are recorded in `status.json`, the report and per-model source status. Unexpected process termination retains the previous data branch; consumers must check timestamps. GitHub Actions must allow workflow writes to `price-data`; branch protection or an organization policy may require an administrator to allow that write.

## Export contract

| File | Use |
|---|---|
| `summary.csv` / `summary.json` | New/used min, quartiles, median, sample counts, freshness and review eligibility |
| `listings.csv` / `listings.json` | Latest accepted/review observations with original source links and timestamps |
| `review.csv` | Ambiguous or delivery-unconfirmed listings |
| `history.csv` / `prices.sqlite` | Daily history; same-day reruns replace, rather than append duplicates |
| `source_status.csv` / `status.json` | Coverage, pages, partial failures, blocked sources, run completion time |
| `catalog.json` | Stable component IDs for future integration |

An integration should first fetch `status.json`, reject `mode != live`, and verify `finished_at`. Join on `model_id`; preserve the separate `new` and `used` rows. Never replace an existing price with null/zero when data is missing. These observations cannot replace actual distributor procurement quotes or condition-based appraisal.

## Google Sheets

CSV files can be imported directly into Google Sheets. For a daily pull without service-account keys, use the included [Apps Script](sheets/Code.gs): open a Google Sheet → Extensions → Apps Script, paste the file, and run `setupPriceTracker` once. It creates tracker tabs, pulls public GitHub results and installs a daily refresh trigger. The script requests access to that spreadsheet and public HTTP fetching. No GitHub token is needed for this public repository. First collection must finish before the initial pull can succeed.

The script writes only its own `Prices`, `Source status`, and `Tracker run` tabs. Those tabs are generated output: put personal notes on a separate tab. It fetches/validates all data before replacing any generated cells and records fetch errors without clearing old prices. Review `Tracker run` before using figures.

## Tests and maintenance

Run the unit suite after parser/configuration changes. Fixtures contain a small set of real public listing cards captured 2026-09-07, stripped of photos, contact/location information and forms. They are parser regression evidence, not current production observations. Tests cover currency parsing, exact chip/CPU distinctions, bundles, source blocks, duplicate persistence, stale/failed source exclusion and spreadsheet formula injection.
