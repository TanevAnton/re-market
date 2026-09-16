<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\Part;
use App\Models\PartPricePoint;
use App\Models\User;
use Database\Seeders\AppleCatalogueSeeder;
use Database\Seeders\CitySeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PartCatalogueSeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The demo seed — the only fixture anybody actually looks at.
 *
 * Worth testing because its failures are all silent and all cosmetic-looking.
 * The version this replaced drew a random part 140 times, which meant some
 * categories came out empty by luck and NOTHING had a photograph — and both of
 * those read as „the site is broken" rather than „the fixture is thin".
 *
 * The thresholds are the real subject. A price band needs three listings on one
 * part and the deal badge needs five; a seed that spreads its listings evenly
 * satisfies neither, and you end up demonstrating the site with its most
 * distinctive features invisible and no clue why.
 *
 * WHY THE ASSERTIONS ARE GROUPED, against the usual advice. Seeding is genuinely
 * expensive: every photograph goes through the real ImageProcessor, which
 * decodes and re-encodes four times. One assertion per test meant fourteen full
 * seeds and a suite that sat silent for a quarter of an hour, which is a test
 * nobody runs — and a test nobody runs is the one the status doc already calls
 * a test that does not exist. So the seed happens four times here, the scale is
 * turned down through config, and every assertion carries a message specific
 * enough to name what broke.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config([
            'remarket.seo.indexable'  => false,

            // Small on purpose. Six per category still clears the badge
            // threshold (the seeder derives the hero count from the config
            // rather than hard-coding it), and one photo per listing is enough
            // to prove photos exist and hash differently.
            'remarket.demo.per_category' => 6,
            'remarket.demo.photos_max'   => 1,
        ]);

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);
        $this->seed(PartCatalogueSeeder::class);
        $this->seed(AppleCatalogueSeeder::class);
    }

    /**
     * The guard is SEO_INDEXABLE, not APP_ENV.
     *
     * APP_ENV is production on the LAN box too, so guarding on it refused to run
     * in the one place demo data is wanted while telling you nothing about
     * whether the database you are pointed at is the real one. Cheap test: the
     * seeder returns before doing any work.
     */
    public function test_it_refuses_to_run_against_the_public_site(): void
    {
        config(['remarket.seo.indexable' => true]);

        $this->seed(DemoSeeder::class);

        $this->assertSame(0, Listing::count(),
            'DemoSeeder wrote listings to a site configured as publicly indexable');
        $this->assertSame(0, User::count());
    }

    /**
     * One seed, every property that makes the fixture usable.
     */
    public function test_the_seed_produces_a_site_worth_looking_at(): void
    {
        $this->seed(DemoSeeder::class);

        // --- coverage: no category empty by luck --------------------------
        $counts = Listing::query()
            ->selectRaw('category, count(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        foreach (array_keys(config('catalog.categories')) as $category) {
            $this->assertGreaterThanOrEqual(5, $counts[$category] ?? 0,
                "[{$category}] has too few listings to look like a category");
        }

        // --- photographs: the thing the old seeder never did at all -------
        $this->assertSame(0, Listing::doesntHave('images')->count(),
            'a listing with no photo renders as a grey box, which is what made the old fixture useless');

        $hashes = ListingImage::pluck('phash');

        $this->assertGreaterThan(0, $hashes->count());
        $this->assertSame(0, $hashes->filter(fn ($h) => (int) $h === 0)->count(),
            'a phash of 0 means a flat image, and every flat image reads as the same stolen photograph');

        // Not strict uniqueness: two block patterns CAN collide, and a fixture
        // that fails once a fortnight is worse than one that checks the
        // property that actually matters.
        $this->assertGreaterThan($hashes->count() * 0.9, $hashes->unique()->count(),
            'the photographs are not varied enough to survive the duplicate screen');

        // --- thresholds: the whole reason this seeder concentrates --------
        $badgeMin = (int) config('remarket.parts.deal_badge_min_listings', 5);

        $busiest = Listing::query()
            ->where('status', ListingStatus::Active)
            ->whereNotNull('part_id')
            ->selectRaw('category, part_id, count(*) as total')
            ->groupBy('category', 'part_id')
            ->get()
            ->groupBy('category')
            ->map(fn ($rows) => $rows->max('total'));

        foreach (array_keys(config('catalog.categories')) as $category) {
            // laptop, prebuilt and other have no catalogue by design.
            if (! Part::where('category', $category)->exists()) {
                continue;
            }

            $this->assertGreaterThanOrEqual($badgeMin, $busiest[$category] ?? 0,
                "[{$category}] has no part with {$badgeMin} live listings, so neither the band nor the badge appears");
        }

        $this->assertGreaterThan(10, Part::whereNotNull('price_median_cents')->count(),
            'no part ended up with a price band, so every model page shows the empty state');

        // --- the trend, which cannot happen on its own for a fortnight ----
        $this->assertGreaterThan(0, PartPricePoint::count());

        $part = Part::whereNotNull('price_median_cents')->whereHas('pricePoints')->firstOrFail();

        $this->assertNotNull($part->priceTrend(),
            'a part with a full backfilled history still refused to draw a trend');

        // --- pages that only exist in a state ----------------------------
        $this->assertGreaterThan(0, Listing::where('status', ListingStatus::Sold)->count(),
            'no sold listing, so the tombstone page has nothing to render');
        $this->assertGreaterThan(0, Listing::where('status', ListingStatus::Expired)->count());

        $this->assertGreaterThan(0, Listing::whereNotNull('custom_part')->count(),
            'nothing typed a model name, so the catalogue promotion queue is empty');

        // --- every account removable later -------------------------------
        $this->assertSame(User::count(), User::where('email', 'like', '%@demo.invalid')->count(),
            'a demo user without the @demo.invalid marker cannot be cleaned up later');
    }

    /**
     * Re-running must not double the cast. Seeded twice, so it is deliberately
     * the second most expensive test here.
     */
    public function test_running_it_twice_reuses_the_same_sellers(): void
    {
        $this->seed(DemoSeeder::class);
        $users = User::count();

        $this->seed(DemoSeeder::class);

        $this->assertSame($users, User::count(),
            'a second run created a second set of demo sellers');
    }

    /**
     * The clear-down is what makes seeding a box that later becomes real safe,
     * so it has to remove the photographs as well as the rows.
     */
    public function test_the_clear_command_removes_everything_it_made(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertGreaterThan(0, Listing::count());
        $this->assertGreaterThan(0, ListingImage::count());

        $this->artisan('remarket:demo-clear', ['--force' => true])->assertSuccessful();

        // withTrashed: listings are soft-deleted, and a tombstone of a listing
        // that never existed is worse than no row at all.
        $this->assertSame(0, Listing::withTrashed()->count());
        $this->assertSame(0, ListingImage::count());
        $this->assertSame(0, User::where('email', 'like', '%@demo.invalid')->count());
    }

    public function test_the_clear_command_says_so_when_there_is_nothing_to_clear(): void
    {
        $this->artisan('remarket:demo-clear', ['--force' => true])->assertSuccessful();
    }
}
