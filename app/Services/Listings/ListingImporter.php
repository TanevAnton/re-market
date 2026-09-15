<?php

namespace App\Services\Listings;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Enums\MiningUse;
use App\Enums\ModerationTrigger;
use App\Models\City;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\Part;
use App\Models\User;
use App\Services\Images\ImageProcessor;
use App\Services\Moderation\ListingScreener;
use App\Services\Moderation\ModerationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * A CSV of stock, turned into listings.
 *
 * Twenty-five evenings of wizard for the RE-Tech inventory, and the wizard is
 * the right tool for one listing and the wrong one for three hundred. Supply is
 * the only thing standing between this site and being useful, so the cost of
 * getting the first few hundred listings on is the cost of the whole project
 * starting.
 *
 * THREE RULES SHAPE EVERYTHING BELOW.
 *
 * 1. A failed row must not take the run down. Two hundred rows will not all be
 *    right the first time — a missing photograph, a misspelt category, a price
 *    with a comma in it — and an importer that aborts on row 140 leaves the
 *    operator with 139 listings, no report, and no idea which is which. Every
 *    row is its own transaction and its own verdict.
 *
 * 2. Re-running must be safe. The natural response to a bad report is to fix
 *    the file and run it again, so a row already imported is SKIPPED by default
 *    (matched on external_ref) rather than duplicated. `$update` opts into
 *    correcting them instead, and goes through ListingService so the price
 *    guards and the people who favourited it are handled properly.
 *
 * 3. NO FUZZY CATALOGUE MATCHING. The search box may guess, because a person is
 *    reading the result and will notice. Nothing reads an import: a fuzzy match
 *    attaches three hundred cards to the wrong model page, sets the wrong price
 *    band and the wrong specs, and looks entirely fine. An exact alias or model
 *    match, or the string goes to `custom_part` and the promotion queue picks
 *    it up — which is the same outcome a seller typing it by hand would get.
 */
class ListingImporter
{
    /** Columns a row cannot do without. */
    private const REQUIRED = ['ref', 'category', 'title', 'description', 'price', 'condition'];

    public function __construct(
        private readonly ImageProcessor $images = new ImageProcessor(),
    ) {}

    /**
     * @param  string  $csv        path to the file
     * @param  string  $photoDir   folder the `images` column is resolved against
     * @return array{rows: list<array<string, mixed>>, created: int, skipped: int, failed: int, updated: int}
     */
    public function import(
        string $csv,
        User $seller,
        string $photoDir,
        bool $dryRun = false,
        bool $update = false,
    ): array {
        $rows    = [];
        $tallies = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'updated' => 0];

        foreach ($this->read($csv) as $line => $record) {
            $result = $this->handle($record, $seller, $photoDir, $dryRun, $update);

            $rows[] = ['line' => $line] + $result;
            $tallies[$result['status']]++;
        }

        return ['rows' => $rows] + $tallies;
    }

    // --- reading ----------------------------------------------------------

    /**
     * Rows as maps, keyed by header, with the file's line number.
     *
     * Two accommodations for the tool this file will actually come out of,
     * which is Excel on a Bulgarian Windows machine:
     *
     *  - the UTF-8 BOM, which Excel writes and which otherwise becomes part of
     *    the first header's NAME, so `ref` silently does not exist and every
     *    row fails for a missing column that is plainly there;
     *  - the semicolon delimiter, which Excel uses wherever the list separator
     *    is a semicolon — most of Europe, Bulgaria included. Sniffed from the
     *    header rather than configured, because an operator who has to know
     *    which one their spreadsheet chose will get it wrong once and lose an
     *    evening to it.
     *
     * @return \Generator<int, array<string, string>>
     */
    private function read(string $csv): \Generator
    {
        $handle = fopen($csv, 'rb');

        if ($handle === false) {
            throw new \RuntimeException("Файлът {$csv} не може да бъде отворен.");
        }

        try {
            $first = fgets($handle);

            if ($first === false) {
                return;
            }

            $first     = preg_replace('/^\x{FEFF}/u', '', $first);
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            $headers   = array_map(
                fn ($h) => mb_strtolower(trim((string) $h)),
                str_getcsv(rtrim($first, "\r\n"), $delimiter, '"', '\\'),
            );

            $line = 1;

            while (($record = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $line++;

                // A trailing newline reads as a row of one empty cell.
                if ($record === [null] || $record === ['']) {
                    continue;
                }

                // Padded rather than refused: a short row is a missing optional
                // column, and the required-field check below is the place that
                // decides whether it matters.
                $record = array_pad($record, count($headers), '');

                yield $line => array_combine($headers, array_slice($record, 0, count($headers)));
            }
        } finally {
            fclose($handle);
        }
    }

    // --- one row ----------------------------------------------------------

    /** @param  array<string, string>  $record */
    private function handle(array $record, User $seller, string $photoDir, bool $dryRun, bool $update): array
    {
        $ref = trim($record['ref'] ?? '');

        try {
            $existing = $ref === ''
                ? null
                : Listing::where('user_id', $seller->id)->where('external_ref', $ref)->first();

            if ($existing && ! $update) {
                return ['ref' => $ref, 'status' => 'skipped', 'message' => 'вече е импортирана'];
            }

            $data = $this->prepare($record, $seller, $photoDir);

            if ($dryRun) {
                return [
                    'ref'     => $ref,
                    'status'  => $existing ? 'updated' : 'created',
                    'message' => 'пробно: '.$data['title'].' — '
                        .number_format($data['attributes']['price_cents'] / 100, 2, ',', ' ').' €'
                        .($data['attributes']['part_id'] ? ' → каталог' : ' → '.$data['attributes']['custom_part']),
                ];
            }

            if ($existing) {
                /*
                 * Through the service, not a bare fill(): it holds the offer
                 * floor guard, releases pending offers when the price moves,
                 * and tells the people who favourited it about a cut.
                 *
                 * It also refuses to change `part_id` or `category`, which is
                 * the site's own rule and stays true here. A re-import cannot
                 * re-attach a listing to a catalogue row that was created since
                 * — that is what the promotion queue at /katalog is for, and it
                 * carries the alias learning an import never would.
                 */
                app(ListingService::class)->update($existing, $data['attributes'], $seller);

                return ['ref' => $ref, 'status' => 'updated', 'message' => $existing->title,
                    'listing_id' => $existing->id];
            }

            $listing = $this->create($data, $seller);

            return ['ref' => $ref, 'status' => 'created', 'message' => $listing->title,
                'listing_id' => $listing->id];
        } catch (Throwable $e) {
            /*
             * Caught per row and reported, never rethrown. The operator needs a
             * list of what went wrong far more than they need a stack trace for
             * the first thing that did.
             */
            return ['ref' => $ref, 'status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * Turn a record into validated listing attributes and resolved photo paths.
     *
     * @param  array<string, string>  $record
     * @return array{title: string, attributes: array<string, mixed>, photos: list<string>}
     */
    private function prepare(array $record, User $seller, string $photoDir): array
    {
        foreach (self::REQUIRED as $column) {
            if (trim($record[$column] ?? '') === '') {
                throw new \RuntimeException("липсва „{$column}\u{201C}");
            }
        }

        $category = mb_strtolower(trim($record['category']));

        if (! config("catalog.categories.{$category}")) {
            throw new \RuntimeException("непозната категория „{$category}\u{201C}");
        }

        $validator = Validator::make([
            'title'       => trim($record['title']),
            'description' => trim($record['description']),
            'price'       => $this->number($record['price'] ?? ''),
            'min_offer'   => $this->number($record['min_offer'] ?? ''),
            'condition'   => mb_strtolower(trim($record['condition'])),
            'quantity'    => trim($record['quantity'] ?? '') ?: 1,
            'mining_use'  => mb_strtolower(trim($record['mining_use'] ?? '')) ?: 'no',
        ], [
            'title'       => ['required', 'string', 'min:10', 'max:120'],
            'description' => ['required', 'string', 'min:30', 'max:5000'],
            'price'       => ['required', 'numeric', 'min:1', 'max:100000'],
            'min_offer'   => ['nullable', 'numeric', 'min:1', 'lte:price'],
            'condition'   => ['required', 'string'],
            'quantity'    => ['integer', 'min:1', 'max:99'],
            'mining_use'  => ['string'],
        ]);

        if ($validator->fails()) {
            throw new \RuntimeException(implode(' ', $validator->errors()->all()));
        }

        $clean     = $validator->validated();
        $condition = ListingCondition::tryFrom($clean['condition'])
            ?? throw new \RuntimeException("непознато състояние „{$clean['condition']}\u{201C}");
        $mining    = MiningUse::tryFrom($clean['mining_use'])
            ?? throw new \RuntimeException("непозната стойност за mining_use");

        $part  = $this->matchPart($category, trim($record['model'] ?? ''));
        $model = trim($record['model'] ?? '');

        $photos = $this->resolvePhotos($record['images'] ?? '', $photoDir);

        return [
            'title'  => $clean['title'],
            'photos' => $photos,
            'attributes' => [
                'category'     => $category,
                'part_id'      => $part?->id,
                // Only when there is no catalogue row: a listing that found its
                // model does not also need the seller's spelling of it, and
                // leaving it set would keep the row in the promotion queue
                // forever.
                'custom_part'  => $part ? null : ($model ?: null),
                'external_ref' => trim($record['ref']),
                'city_id'      => $this->matchCity($record['city'] ?? '')?->id ?? $seller->city_id,
                'title'        => $clean['title'],
                'description'  => $clean['description'],
                'condition'    => $condition,
                'quantity'     => (int) $clean['quantity'],
                'price_cents'  => (int) round((float) $clean['price'] * 100),
                'min_offer_cents' => $clean['min_offer'] !== null
                    ? (int) round((float) $clean['min_offer'] * 100)
                    : null,
                'offers_enabled'       => $this->bool($record['offers_enabled'] ?? '', true),
                'warranty_until'       => trim($record['warranty_until'] ?? '') ?: null,
                'has_receipt'          => $this->bool($record['has_receipt'] ?? '', false),
                'mining_use'           => $mining,
                'mining_months'        => $mining === MiningUse::Yes
                    ? ((int) trim($record['mining_months'] ?? '') ?: null)
                    : null,
                'validation_url'       => trim($record['validation_url'] ?? '') ?: null,
                'accepts_inspect_test' => $this->bool($record['accepts_inspect_test'] ?? '', true),
                'specs'                => $this->specs($record, $category),
                'delivery_options'     => $this->delivery($record['delivery'] ?? ''),
            ],
        ];
    }

    /**
     * @param  array{title: string, attributes: array<string, mixed>, photos: list<string>}  $data
     */
    private function create(array $data, User $seller): Listing
    {
        // The same gate the wizard applies. An importer that waved its listings
        // straight past it would be the one route onto the site that skips the
        // best anti-spam measure there is - and the account doing the importing
        // is precisely the kind an attacker would want.
        $reviewed = $seller->listings()->count()
            < config('remarket.antispam.moderated_listings_for_new_accounts', 2);

        $listing = DB::transaction(function () use ($data, $seller, $reviewed) {
            $listing = Listing::create(['user_id' => $seller->id] + $data['attributes']);

            foreach ($data['photos'] as $i => $source) {
                $stored = $this->images->storeFile($source);

                ListingImage::create([
                    'listing_id' => $listing->id,
                    'path'       => $stored['path'],
                    'width'      => $stored['width'],
                    'height'     => $stored['height'],
                    'bytes'      => $stored['bytes'],
                    'phash'      => $stored['phash'],
                    'position'   => $i,
                ]);
            }

            // forceFill for the same reason the wizard uses it: status and the
            // published/expiry stamps are deliberately not mass-assignable.
            $listing->forceFill([
                'status'       => $reviewed ? ListingStatus::PendingReview : ListingStatus::Active,
                'published_at' => now(),
                'bumped_at'    => now(),
                'expires_at'   => now()->addDays(config('remarket.listings.expire_after_days', 60)),
            ])->save();

            if ($reviewed) {
                app(ModerationService::class)->enqueue(
                    $listing,
                    ModerationTrigger::NewAccount,
                    ['listings_so_far' => $seller->listings()->count()],
                );
            }

            return $listing;
        });

        // After the transaction, as in the wizard: it reads the images back and
        // compares them against every other listing on the site.
        app(ListingScreener::class)->screen($listing);

        return $listing;
    }

    // --- resolving --------------------------------------------------------

    /**
     * An exact alias or an exact model name. Never a guess.
     *
     * See the class note: a fuzzy match in an unattended import is three
     * hundred listings quietly attached to the wrong model, with the wrong
     * price band and the wrong specifications, and nothing on any page that
     * looks wrong.
     */
    private function matchPart(string $category, string $model): ?Part
    {
        if ($model === '') {
            return null;
        }

        return Part::where('category', $category)
            ->where('is_published', true)
            ->where(function ($q) use ($model) {
                $q->matchingAlias($model)
                  ->orWhereRaw('LOWER(model) = ?', [mb_strtolower($model)])
                  ->orWhereRaw("LOWER(manufacturer || ' ' || model) = ?", [mb_strtolower($model)]);
            })
            ->first();
    }

    private function matchCity(string $city): ?City
    {
        $city = trim($city);

        return $city === '' ? null : City::where('slug', mb_strtolower($city))
            ->orWhere('name_bg', $city)
            ->orWhere('name_en', $city)
            ->first();
    }

    /**
     * Photo filenames, resolved against the folder next to the CSV.
     *
     * A listing with no photograph does not sell, so a row without one is a
     * failed row rather than a quiet publication. realpath() and the prefix
     * check keep `../../.env` in a spreadsheet cell from becoming a listing
     * photograph.
     *
     * @return list<string>
     */
    private function resolvePhotos(string $names, string $photoDir): array
    {
        $root  = realpath($photoDir);
        $found = [];

        if ($root === false) {
            throw new \RuntimeException("папката със снимки {$photoDir} не съществува");
        }

        foreach (preg_split('/[;,|]/', $names) ?: [] as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $path = realpath($root.DIRECTORY_SEPARATOR.$name);

            if ($path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException("липсва снимка „{$name}\u{201C}");
            }

            $found[] = $path;
        }

        $min = (int) config('remarket.listings.min_images', 1);
        $max = (int) config('remarket.listings.max_images', 12);

        if (count($found) < $min) {
            throw new \RuntimeException('няма снимки — обява без снимка не се продава');
        }

        return array_slice($found, 0, $max);
    }

    /**
     * `spec:power_on_hours` columns, checked against the category's schema.
     *
     * An unknown key FAILS the row rather than being dropped. A silently
     * ignored column is a spreadsheet the operator believes is being imported
     * and a facet that never matches anything — the exact failure the catalogue
     * seed validator was written to catch, arriving through a different door.
     *
     * @param  array<string, string>  $record
     * @return array<string, mixed>
     */
    private function specs(array $record, string $category): array
    {
        $schema = config("catalog.categories.{$category}.specs", []);
        $out    = [];

        foreach ($record as $column => $value) {
            if (! str_starts_with($column, 'spec:')) {
                continue;
            }

            $key   = substr($column, 5);
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            if (! isset($schema[$key])) {
                throw new \RuntimeException("„{$key}\u{201C} не е характеристика на {$category}");
            }

            if (($schema[$key]['scope'] ?? 'part') !== 'listing') {
                throw new \RuntimeException("„{$key}\u{201C} е характеристика на модела, не на бройката");
            }

            $out[$key] = match ($schema[$key]['type'] ?? 'string') {
                'int'         => (int) $value,
                'decimal'     => (float) $value,
                'bool'        => $this->bool($value, false),
                'multiselect' => array_values(array_filter(array_map('trim', explode(';', $value)))),
                default       => $value,
            };
        }

        return $out;
    }

    /** @return list<string> */
    private function delivery(string $value): array
    {
        $allowed = ['econt', 'speedy', 'pickup'];
        $chosen  = array_values(array_intersect(
            array_map(fn ($v) => mb_strtolower(trim($v)), preg_split('/[;,|]/', $value) ?: []),
            $allowed,
        ));

        return $chosen ?: ['econt'];
    }

    /** Excel writes да/не, TRUE/FALSE, 1/0 and an empty cell; all four arrive here. */
    private function bool(string $value, bool $default): bool
    {
        $value = mb_strtolower(trim($value));

        return match ($value) {
            ''                                       => $default,
            '1', 'true', 'yes', 'да', 'x', 'y'       => true,
            '0', 'false', 'no', 'не', 'n'            => false,
            default                                  => $default,
        };
    }

    /** „1 299,50" and "1299.50" are the same price typed by different tools. */
    private function number(string $value): ?string
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($value));

        if ($value === '') {
            return null;
        }

        // A comma is a decimal separator here, not a thousands separator: a
        // Bulgarian spreadsheet writes 1299,50 and a English-locale one writes
        // 1299.50, and neither writes 1,299.50 into a price column.
        return str_replace(',', '.', $value);
    }
}
