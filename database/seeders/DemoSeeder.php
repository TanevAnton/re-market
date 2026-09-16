<?php

namespace Database\Seeders;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Enums\MiningUse;
use App\Enums\SellerType;
use App\Models\City;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\Part;
use App\Models\PartPricePoint;
use App\Models\User;
use App\Services\Images\ImageProcessor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Test data — a site you can actually look at.
 *
 * WHAT WAS WRONG WITH THE OLD ONE. It made 140 listings by calling the factory
 * 140 times with a random part each. Two consequences, both of which made the
 * site look broken rather than empty:
 *
 *  - Categories came out lopsided by luck. Nineteen categories, 140 draws:
 *    some got twenty listings and some got none, so half the browse pages were
 *    empty and there was no way to tell a bug from the dice.
 *  - NOTHING HAD A PHOTOGRAPH. The factory sets no images at all, so every
 *    card in the grid was a grey box. That alone is why demo data never looked
 *    like a marketplace.
 *
 * And a subtler one: random parts means no part collects enough listings for
 * the features that only exist above a threshold. A price band needs three
 * listings on ONE part; the deal badge needs five. Spreading five listings
 * across five models shows neither — you would be looking at a site with the
 * interesting half switched off and no indication why.
 *
 * So this seeds per category, concentrated: one „hero" part per category gets
 * enough listings to earn a band AND a badge, the rest spread out for variety.
 *
 * @see \Database\Seeders\AppleCatalogueSeeder for the catalogue this draws on
 */
class DemoSeeder extends Seeder
{
    private ImageProcessor $images;

    /**
     * Ten by default, not the five that get asked for.
     *
     * Five per category is five per CATEGORY, and every threshold on this site
     * counts listings per PART. Five spread across five models gives no band,
     * no badge and no trend — a demo of the site with its best features
     * invisible. Ten is one part that shows everything plus four listings of
     * variety around it.
     */
    private int $perCategory;

    /**
     * Derived, never hard-coded: one above whichever threshold is higher.
     *
     * Writing `6` here works right up until somebody raises
     * `deal_badge_min_listings` to 8, at which point the badge quietly stops
     * appearing in the demo and looks like a bug in the badge.
     */
    private int $heroListings;

    private int $photosMax;

    private int $historyDays;

    /** @var list<int> */
    private array $sellerIds = [];

    /** @var list<int> */
    private array $cityIds = [];

    /**
     * The parts carrying each category's band and badge.
     *
     * Kept so the tombstone pass can leave them alone. Marking listings sold at
     * random would occasionally take two of a hero part's six and drop it under
     * `deal_badge_min_listings`, so the badge would be missing from one random
     * category per seed — intermittent, cosmetic, and indistinguishable from a
     * bug in the badge itself. Exactly the class of flake the test notes in the
     * status doc warn about.
     *
     * @var list<int>
     */
    private array $heroPartIds = [];

    public function run(): void
    {
        /*
         * THE GUARD IS `SEO_INDEXABLE`, NOT `APP_ENV`.
         *
         * APP_ENV is production on the LAN box too — that is documented in
         * remarket:doctor and it is the whole reason the doctor checks
         * SEO_INDEXABLE instead. Guarding on APP_ENV therefore did the wrong
         * thing in both directions: it refused to run on the staging box, which
         * is the one place demo data is actually wanted, and it would happily
         * run against a live database that happened to be configured as
         * anything else.
         *
         * SEO_INDEXABLE is this site's own answer to „is this the real public
         * one", so it is the right question to ask here as well.
         */
        if (config('remarket.seo.indexable')) {
            $this->command?->error(
                'DemoSeeder отказва да работи при SEO_INDEXABLE=true — това е публичният сайт.'
            );

            return;
        }

        $this->images = app(ImageProcessor::class);

        $this->perCategory  = max(1, (int) config('remarket.demo.per_category', 10));
        $this->photosMax    = max(1, (int) config('remarket.demo.photos_max', 3));
        $this->historyDays  = max(
            (int) config('remarket.parts.history_min_days', 14) + 1,
            (int) config('remarket.demo.history_days', 45),
        );
        $this->heroListings = min($this->perCategory, max(
            (int) config('remarket.parts.deal_badge_min_listings', 5) + 1,
            (int) config('remarket.parts.price_band_min_listings', 3) + 1,
        ));

        $this->cityIds = City::pluck('id')->all();

        if ($this->cityIds === []) {
            $this->command?->error('Няма градове. Пусни CitySeeder преди този.');

            return;
        }

        $this->sellerIds = $this->sellers();

        $categories = array_keys(config('catalog.categories', []));
        $made       = 0;

        /*
         * Progress, because this is slow and silence reads as a crash.
         *
         * Every photograph goes through the real ImageProcessor - four decode
         * and encode passes each, for the full image, the thumbnail and the
         * perceptual hash's own reduction - so a default seed is a couple of
         * minutes of work with nothing on screen. The first version printed
         * nothing until it finished, and the first thing anyone did was kill it.
         */
        $this->command?->line("  {$this->perCategory} обяви x ".count($categories).' категории, до '
            .$this->photosMax.' снимки на обява. Това отнема минута-две.');

        foreach ($categories as $category) {
            $made += $this->seedCategory($category);
            $this->command?->line(sprintf('  %-14s %3d обяви', $category, $made));
        }

        $this->tombstones();
        $this->refreshStats();

        $this->command?->info(
            "Готово: {$made} обяви в ".count(config('catalog.categories', [])).' категории, '
            .ListingImage::count().' снимки, '.PartPricePoint::count().' точки история.'
        );
        $this->command?->warn('Демо профилите са с имейл @demo.invalid и парола „password".');
        $this->command?->warn('Изчистване:  php artisan remarket:demo-clear');
    }

    // --- people -----------------------------------------------------------

    /**
     * Sellers that can be told apart from real ones later.
     *
     * The `@demo.invalid` domain is the marker, and `.invalid` is reserved by
     * RFC 2606 precisely so it can never be delivered to — which matters on a
     * box where the queue worker is running and notifications are real.
     *
     * @return list<int>
     */
    private function sellers(): array
    {
        $existing = User::where('email', 'like', '%@demo.invalid')->pluck('id')->all();

        if ($existing !== []) {
            $this->command?->line('  Демо профилите вече съществуват, преизползвам ги.');

            return $existing;
        }

        /*
         * BUILT BY HAND, NOT BY THE FACTORY — and this is not a style choice.
         *
         * `deploy.sh` runs `composer install --no-dev`, which is correct: faker,
         * phpunit and pint have no business on a server. But factories ARE
         * faker, so `User::factory()` dies there with „Call to a member function
         * unique() on null" — a message that says nothing about the actual
         * cause. This seeder is explicitly meant to run on the staging box, so
         * it cannot use a single dev-only tool.
         *
         * Nothing here is random for the same reason: a fixed set of sellers
         * means two runs on two machines produce the same people, which is what
         * you want when comparing a page locally against the server.
         */
        $names = [
            ['plamen',   'Пламен Тодоров'],
            ['georgi',   'Георги Иванов'],
            ['nikolay',  'Николай Стоянов'],
            ['dimitar',  'Димитър Петров'],
            ['stoyan',   'Стоян Колев'],
            ['viktoria', 'Виктория Илиева'],
            ['kaloyan',  'Калоян Митев'],
            ['radoslav', 'Радослав Ганчев'],
            ['yana',     'Яна Кирилова'],
            ['boris',    'Борис Ангелов'],
        ];

        $ids = [];

        foreach ($names as $i => [$handle, $name]) {
            $user = User::create([
                'name'        => $name,
                'username'    => "demo-{$handle}",
                'email'       => "{$handle}@demo.invalid",
                'password'    => Hash::make('password'),
                'city_id'     => $this->cityIds[array_rand($this->cityIds)],

                // The first two are shops, so the trader badge and the
                // trader-only legal copy have somewhere to appear.
                'seller_type' => $i < 2 ? SellerType::Trader : SellerType::Private,
                'trader_details' => $i < 2 ? [
                    'company' => $i === 0 ? 'РЕ-ТЕХ ДЕМО ЕООД' : 'ПИСИ СЕРВИЗ ДЕМО ЕООД',
                    'uic'     => (string) (200000000 + $i),
                    'address' => 'гр. Горна Оряховица, ул. Демо 1',
                ] : null,
            ]);

            /*
             * Verification and the reputation figures are not fillable — they
             * are earned, and a mass-assignable `deals_completed` would be a
             * seller able to award themselves a track record. forceFill is the
             * seeder saying it knows that and is placing the state directly.
             */
            $deals = [0, 3, 7, 12, 19, 24, 31, 5, 16, 41][$i];

            $user->forceFill([
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'phone_hash'        => hash('sha256', "demo-{$handle}"),
                'phone_last4'       => (string) (1000 + $i * 111),
                'phone_country'     => 'BG',
                'deals_completed'   => $deals,
                'deals_abandoned'   => (int) floor($deals * 0.12),
                'rating_count'      => $deals,
                'rating_avg'        => $deals > 0 ? round(4.2 + ($i % 8) * 0.1, 2) : null,
                'remember_token'    => Str::random(10),
            ])->save();

            $ids[] = $user->id;
        }

        return $ids;
    }

    // --- listings ---------------------------------------------------------

    private function seedCategory(string $category): int
    {
        $parts = Part::where('category', $category)
            ->where('is_published', true)
            ->inRandomOrder()
            ->limit(4)
            ->get();

        // laptop, prebuilt and other have no catalogue by design. Their
        // listings carry a typed model name instead, which also gives /katalog
        // something real to cluster.
        if ($parts->isEmpty()) {
            for ($i = 0; $i < $this->perCategory; $i++) {
                $this->listing($category, null, $this->uncataloguedPrice($category));
            }

            return $this->perCategory;
        }

        $hero   = $parts->shift();
        $median = $this->estimate($hero);

        $this->heroPartIds[] = $hero->id;

        /*
         * The hero part, priced around one median so the band is tight enough
         * to be believable — and ONE listing deliberately 22% under it, which
         * is comfortably past `deal_badge_min_percent` (7) so the badge is
         * visible on the browse page without having to hunt for it.
         */
        for ($i = 0; $i < $this->heroListings; $i++) {
            $this->listing(
                $category,
                $hero,
                $i === 0
                    ? (int) round($median * 0.78)
                    : (int) round($median * (mt_rand(94, 112) / 100)),
            );
        }

        $remaining = $this->perCategory - $this->heroListings;

        foreach (range(1, max(1, $remaining)) as $n) {
            $part = $parts->isNotEmpty() ? $parts[($n - 1) % $parts->count()] : $hero;

            $this->listing($category, $part, (int) round(
                $this->estimate($part) * (mt_rand(88, 115) / 100)
            ));
        }

        $this->history($hero, $median);

        return $this->heroListings + max(1, $remaining);
    }

    private function listing(string $category, ?Part $part, int $priceCents): Listing
    {
        $condition = $this->condition();
        $published = now()->subDays(mt_rand(0, 40))->subHours(mt_rand(0, 23));

        $listing = Listing::create([
            'user_id'              => $this->sellerIds[array_rand($this->sellerIds)],
            'part_id'              => $part?->id,
            'custom_part'          => $part ? null : $this->uncataloguedName($category),
            'category'             => $category,
            'city_id'              => $this->cityIds[array_rand($this->cityIds)],
            'title'                => $this->title($category, $part),
            'description'          => $this->description($category, $condition),
            'condition'            => $condition,
            'quantity'             => 1,
            'price_cents'          => $priceCents,
            'offers_enabled'       => mt_rand(1, 100) <= 85,
            'min_offer_cents'      => mt_rand(1, 100) <= 35
                ? (int) round($priceCents * (mt_rand(75, 93) / 100))
                : null,
            'has_receipt'          => mt_rand(1, 100) <= 40,
            'accepts_inspect_test' => mt_rand(1, 100) <= 55,
            'mining_use'           => $category === 'gpu'
                ? [MiningUse::No, MiningUse::No, MiningUse::No, MiningUse::Yes, MiningUse::Unknown][mt_rand(0, 4)]
                : MiningUse::No,
            'specs'                => $this->specs($category),
        ]);

        /*
         * The lifecycle columns are NOT fillable, and that is correct — status,
         * published_at, bumped_at, expires_at and sold_at belong to
         * ListingService, which is the only thing allowed to move a listing
         * between states. With preventSilentlyDiscardingAttributes() on, passing
         * them to create() throws rather than quietly dropping them.
         *
         * A seeder is the one legitimate caller that wants to place a row
         * directly in a state without walking it through publication — so it
         * says so with forceFill instead of widening $fillable, which would
         * open the same door to every other caller forever.
         */
        $listing->forceFill([
            'status'       => ListingStatus::Active,
            'published_at' => $published,
            'bumped_at'    => $published,
            'expires_at'   => $published->copy()->addDays(60),
        ])->save();

        $this->photograph($listing, mt_rand(1, $this->photosMax));

        return $listing;
    }

    /**
     * Real JPEGs through the real pipeline.
     *
     * Not flat colour blocks: a difference hash compares each pixel with its
     * neighbour, so a flat image of ANY colour hashes to zero and the screener
     * reads every one of them as the same stolen photograph — which is exactly
     * how a demo seed ends up with 180 listings in the moderation queue. The
     * blocks below are seeded per image so every hash is different, the same
     * fix the bulk-import tests needed.
     */
    private function photograph(Listing $listing, int $count): void
    {
        for ($n = 0; $n < $count; $n++) {
            $file = sys_get_temp_dir().'/demo-'.Str::uuid()->toString().'.jpg';
            $seed = crc32($listing->id.'-'.$n.'-'.$listing->title);

            $img = imagecreatetruecolor(900, 675);

            for ($x = 0; $x < 900; $x += 45) {
                for ($y = 0; $y < 675; $y += 45) {
                    $v = ($seed >> ((($x + $y) / 45) % 24)) & 0xFF;
                    imagefilledrectangle($img, $x, $y, $x + 44, $y + 44,
                        imagecolorallocate($img, $v, ($v * 7) % 256, ($v * 13) % 256));
                }
            }

            imagejpeg($img, $file, 90);
            imagedestroy($img);

            try {
                $stored = $this->images->storeFile($file);

                ListingImage::create([
                    'listing_id'         => $listing->id,
                    'path'               => $stored['path'],
                    'width'              => $stored['width'],
                    'height'             => $stored['height'],
                    'bytes'              => $stored['bytes'],
                    'phash'              => $stored['phash'],
                    'is_timestamp_photo' => $n === 0 && mt_rand(1, 100) <= 30,
                    'position'           => $n,
                ]);
            } finally {
                @unlink($file);
            }
        }
    }

    /**
     * Sold and expired listings, because the tombstone is a page with its own
     * rules — noindex, a canonical onto the catalogue page, live alternatives
     * instead of a 404, and no deal badge — and none of that is visible on a
     * site where every listing is active.
     */
    private function tombstones(): void
    {
        /*
         * Anything except a hero part - see $heroPartIds.
         *
         * The null branch is load-bearing, not tidiness: `NOT IN` is false for
         * NULL in SQL, so a bare whereNotIn would silently exclude every
         * uncatalogued listing. Those are the ones whose tombstone has no model
         * page to canonicalise onto, which is the edge worth being able to look
         * at.
         */
        $notHero = fn ($q) => $q->whereNull('part_id')
            ->orWhereNotIn('part_id', $this->heroPartIds ?: [0]);

        Listing::query()->where('status', ListingStatus::Active)
            ->where($notHero)
            ->inRandomOrder()->limit(8)
            ->get()
            ->each(fn (Listing $l) => $l->forceFill([
                'status'  => ListingStatus::Sold,
                'sold_at' => now()->subDays(mt_rand(1, 20)),
            ])->save());

        Listing::query()->where('status', ListingStatus::Active)
            ->where($notHero)
            ->inRandomOrder()->limit(4)
            ->get()
            ->each(fn (Listing $l) => $l->forceFill([
                'status'     => ListingStatus::Expired,
                'expires_at' => now()->subDays(mt_rand(1, 10)),
            ])->save());
    }

    // --- price history ----------------------------------------------------

    /**
     * Backfilled directly, and this is the one thing here that could not
     * happen on its own.
     *
     * `Part::priceTrend()` refuses to draw below four points spanning fourteen
     * days, which is correct — a sparkline from two points a day apart is a
     * shape, not information — and it means a fresh database shows no trend
     * for a fortnight no matter how many listings it has. Writing the series
     * is the only way to see the feature today.
     *
     * The curve drifts downward, because used hardware does.
     */
    private function history(Part $part, int $median): void
    {
        $rows  = [];
        $drift = mt_rand(6, 18) / 100;

        for ($day = $this->historyDays; $day >= 0; $day -= 1) {
            // Older points sit higher; noise keeps it from looking drawn.
            $factor = 1 + ($drift * $day / $this->historyDays) + (mt_rand(-15, 15) / 1000);
            $mid    = (int) round($median * $factor);

            $rows[] = [
                'part_id'      => $part->id,
                'captured_on'  => now()->subDays($day)->toDateString(),
                'p25_cents'    => (int) round($mid * 0.91),
                'median_cents' => $mid,
                'p75_cents'    => (int) round($mid * 1.12),
                'sample_size'  => mt_rand(3, 9),
                'created_at'   => now(),
            ];
        }

        DB::table('part_price_points')->upsert(
            $rows,
            ['part_id', 'captured_on'],
            ['p25_cents', 'median_cents', 'p75_cents', 'sample_size'],
        );
    }

    /**
     * The real command, not a reimplementation of it — so what the demo shows
     * is what the nightly job would produce, and a bug in the aggregation
     * shows up here rather than hiding behind seeder maths.
     */
    private function refreshStats(): void
    {
        $this->command?->line('  Преизчислявам ценовите диапазони…');

        \Illuminate\Support\Facades\Artisan::call('remarket:refresh-part-stats');
    }

    // --- copy -------------------------------------------------------------

    private function condition(): ListingCondition
    {
        return [
            ListingCondition::Used, ListingCondition::Used, ListingCondition::Used,
            ListingCondition::LikeNew, ListingCondition::LikeNew,
            ListingCondition::New, ListingCondition::ForParts,
        ][mt_rand(0, 6)];
    }

    private function title(string $category, ?Part $part): string
    {
        $suffix = [
            'с кутията и документите', 'от лична машина', 'малко ползвана',
            'с гаранция до края на годината', 'пълен комплект', 'втора употреба, тествана',
            'сменена при ъпгрейд', 'работи без забележки',
        ][mt_rand(0, 7)];

        $name = $part
            ? $part->fullName()
            : $this->uncataloguedName($category);

        return Str::limit("{$name} — {$suffix}", 118, '');
    }

    private function description(string $category, ListingCondition $condition): string
    {
        $opening = match ($condition) {
            ListingCondition::New      => 'Чисто нова, неразопакована. Купена е за проект, който не се случи.',
            ListingCondition::LikeNew  => 'Ползвана е няколко месеца в домашна машина и е като нова.',
            ListingCondition::ForParts => 'Продава се за части — не тръгва стабилно и не го крия.',
            default                    => 'Ползвана е в машина за ежедневна работа и игри.',
        };

        $middle = [
            'Държана е в добре проветрена кутия, без пушене в стаята.',
            'Изваждам я при ъпгрейд, не заради проблем.',
            'Тествана е преди снимките, температурите са в нормата.',
            'Има оригиналната кутия и всички аксесоари, с които дойде.',
        ][mt_rand(0, 3)];

        $close = [
            'Мога да я пусна пред теб при вземане на място.',
            'Пращам с Еконт за моя сметка при цена без пазарлък.',
            'Питай за снимки на каквото те интересува, ще ги добавя.',
            'Отговарям бързо, но не по телефон — само тук.',
        ][mt_rand(0, 3)];

        return "{$opening} {$middle} {$close}";
    }

    /**
     * Listing-scoped specs only, taken from the category's own schema — so a
     * demo listing populates the same facets a real one would and the filter
     * sidebar has something to filter on.
     *
     * @return array<string, mixed>
     */
    private function specs(string $category): array
    {
        $schema = config("catalog.categories.{$category}.specs", []);
        $out    = [];

        foreach ($schema as $key => $spec) {
            if (($spec['scope'] ?? 'part') !== 'listing') {
                continue;
            }

            // A quarter left blank on purpose: „без отговор" in the buyer
            // checklist is a state worth being able to see.
            if (mt_rand(1, 100) <= 25) {
                continue;
            }

            $out[$key] = match ($spec['type'] ?? 'string') {
                'bool'    => mt_rand(0, 1) === 1,
                'int'     => isset($spec['options'])
                    ? $spec['options'][array_rand($spec['options'])]
                    : mt_rand(1, 95),
                'decimal' => mt_rand(10, 900) / 10,
                'select'  => $spec['options'][array_rand($spec['options'])] ?? null,
                default   => isset($spec['options'])
                    ? $spec['options'][array_rand($spec['options'])]
                    : 'Не е посочено',
            };
        }

        return array_filter($out, fn ($v) => $v !== null);
    }

    private function uncataloguedName(string $category): string
    {
        return match ($category) {
            'laptop'   => ['Lenovo ThinkPad T480', 'HP EliteBook 840 G6', 'Dell Latitude 5400',
                           'ASUS TUF Gaming F15', 'Acer Nitro 5'][mt_rand(0, 4)],
            'prebuilt' => ['Сглобен Ryzen 5 + RTX 3060', 'Office машина i5 10400',
                           'Гейминг конфигурация 1440p', 'HP ProDesk 600 G5',
                           'Работна станция Xeon'][mt_rand(0, 4)],
            default    => ['USB-C докинг станция', 'Кабел DisplayPort 1.4 2m',
                           'Термопаста Arctic MX-6', 'Стойка за два монитора',
                           'Разклонител с защита'][mt_rand(0, 4)],
        };
    }

    private function uncataloguedPrice(string $category): int
    {
        return match ($category) {
            'laptop'   => mt_rand(180, 900) * 100,
            'prebuilt' => mt_rand(400, 2200) * 100,
            default    => mt_rand(8, 120) * 100,
        };
    }

    /**
     * A plausible asking price for a part, in cents.
     *
     * Derived from the catalogue rather than random, for the reason the old
     * factory already gave: a 4090 must not come out cheaper than a 1050 Ti or
     * every screenshot looks broken.
     */
    private function estimate(Part $part): int
    {
        $base = match ($part->category) {
            'gpu'         => 250, 'cpu' => 180, 'motherboard' => 120, 'ram' => 60,
            'psu'         => 90,  'storage' => 70, 'monitor' => 200, 'cooler' => 55,
            'case'        => 80,  'keyboard' => 70, 'mouse' => 45, 'headset' => 60,
            'console'     => 350, 'iphone' => 500, 'ipad' => 350, 'macbook' => 800,
            default       => 120,
        };

        // Newer models cost more; anything without a year sits at the base.
        // The column is `launch_year` — `year` does not exist and would read as
        // null, which silently flattens every price in the demo to the base.
        $year = (int) ($part->launch_year ?: 2020);
        $age  = max(0, 2026 - max(2012, $year));

        return (int) round($base * 100 * (1 + max(0, (10 - $age)) * 0.18));
    }
}
