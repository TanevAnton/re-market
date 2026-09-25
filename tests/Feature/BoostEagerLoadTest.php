<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Favorites\MyFavorites;
use App\Models\City;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\User;
use App\Support\Boosted;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * THE ONE THAT WATCHES EVERY GRID, not just the one that was being written.
 *
 * `partials.listing-card` asks App\Support\Boosted two questions per card, and
 * Boosted falls back to loading the relation when a query forgot to eager-load
 * it. That fallback is deliberate — answering „no boost" would put a site
 * nobody has paid for on the screen — but it means a forgotten `eagerLoad()`
 * costs a query per card instead of failing.
 *
 * This is exactly what happened: browse got its own N+1 test, and the other
 * SIX grids that render the same partial were shipped lazy-loading, live, for
 * a whole release. A per-page test only ever guards the page it was written
 * for. This one walks every route that renders a card.
 *
 * The counts are deliberately loose. The point is not „this page makes nine
 * queries", which would break on every unrelated join anyone adds — it is that
 * the number does not grow with the number of cards.
 */
class BoostEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    private const CARDS = 12;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
            'username'          => 'prodavach',
        ]);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Listing> */
    private function listings(int $count)
    {
        return Listing::factory()->count($count)->create([
            'user_id'   => $this->seller->id,
            'city_id'   => City::first()->id,
            'category'  => 'gpu',
            'status'    => ListingStatus::Active,
            'view_count' => 10,
        ]);
    }

    private function queriesFor(callable $hit): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $hit();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * The measurement that makes the rest meaningful.
     *
     * Two card counts, one route: if the query count grows with the cards, the
     * page is lazy-loading something per row. This catches a missing
     * eagerLoad() without hard-coding what „normal" is for any page, which is
     * the part that usually rots.
     */
    private function assertFlat(string $url, string $what): void
    {
        $this->listings(3);
        $few = $this->queriesFor(fn () => $this->get($url)->assertOk());

        $this->listings(self::CARDS);
        $many = $this->queriesFor(fn () => $this->get($url)->assertOk());

        $this->assertLessThanOrEqual(
            $few + 3,
            $many,
            "{$what}: {$few} queries for 3 cards, {$many} for ".(self::CARDS + 3)
            .' — the count grows per card, so a grid is missing Boosted::eagerLoad()',
        );
    }

    public function test_the_home_page_does_not_query_per_card(): void
    {
        $this->assertFlat(route('home'), 'home');
    }

    public function test_browse_does_not_query_per_card(): void
    {
        $this->assertFlat(route('browse'), 'browse');
    }

    public function test_a_seller_profile_does_not_query_per_card(): void
    {
        $this->assertFlat(route('profile', $this->seller->username), 'profile');
    }

    public function test_the_part_page_does_not_query_per_card(): void
    {
        $part = $this->listings(1)->first()->part;

        $this->assertFlat(route('part', $part), 'part page');
    }

    /**
     * The favourites page is measured differently, and the reason is a SECOND
     * N+1 that is nothing to do with boosts.
     *
     * Every card on a signed-in page renders `favorites.favorite-button`, and
     * its mount() runs one `exists()` query per card. So the query count on
     * any authenticated grid already grows per card, and a flat-count
     * assertion here would either fail for the wrong reason or — worse — be
     * loosened until it encodes that bug as expected behaviour.
     *
     * So this asserts the thing this file is actually about: the listings the
     * component hands the view arrive with `boosts` already loaded.
     * (The favourite-button query is real and worth fixing; it needs the
     * parent to resolve the saved set once and pass it down, which is its own
     * piece of work rather than a line in this one.)
     */
    public function test_the_favourites_page_loads_the_relation_for_every_card(): void
    {
        $buyer = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        foreach ($this->listings(self::CARDS) as $listing) {
            Favorite::create(['user_id' => $buyer->id, 'listing_id' => $listing->id]);
        }

        $favorites = Livewire::actingAs($buyer)
            ->test(MyFavorites::class)
            ->viewData('favorites');

        $this->assertNotEmpty($favorites);

        foreach ($favorites as $favorite) {
            $this->assertTrue(
                $favorite->listing->relationLoaded('boosts'),
                'a favourited listing reached the card without its boosts loaded',
            );
        }
    }

    /**
     * And the guard on the guard: every view that renders a card must come
     * from a query that eager-loads the relation.
     *
     * A grep, because the alternative is remembering. When somebody adds the
     * eighth grid, this fails with the file name in the message rather than
     * with a performance report nobody runs.
     */
    public function test_every_view_that_renders_a_card_has_an_eager_loading_source(): void
    {
        $views = collect(glob(resource_path('views/**/*.blade.php')))
            ->merge(glob(resource_path('views/**/**/*.blade.php')))
            ->unique()
            ->filter(fn ($f) => str_contains((string) file_get_contents($f), 'partials.listing-card'))
            ->map(fn ($f) => basename($f))
            ->sort()
            ->values();

        // If this number changes, a grid was added or removed — go and check
        // that its query eager-loads, then update the list.
        $known = collect([
            'browse-listings.blade.php',
            'home.blade.php',
            'my-favorites.blade.php',
            'show-bundle.blade.php',
            'show-listing.blade.php',
            'show-part.blade.php',
            'show-profile.blade.php',
            // Added when „Търся" shipped — and this test is why the N+1 in
            // ShowWanted::render() was found before it reached the server
            // rather than a month later.
            'show-wanted.blade.php',
        ]);

        $this->assertSame(
            $known->all(),
            $views->all(),
            'a view renders partials.listing-card — make sure its query includes '
            .'Boosted::eagerLoad(), then add it to the list in this test',
        );

        // The partial reads the loaded relation and nothing else. If this ever
        // stops being true the fallback in Boosted:: is the only thing holding
        // the site up, and it holds it up one query at a time.
        $this->assertStringNotContainsString(
            '->boosts()',
            (string) file_get_contents(resource_path('views/partials/listing-card.blade.php')),
        );
    }

    /** my-listings renders its own row, not the shared card, and still asks. */
    public function test_my_listings_does_not_query_per_row(): void
    {
        $this->actingAs($this->seller);

        $this->listings(3);
        $few = $this->queriesFor(fn () => $this->get(route('listings.mine'))->assertOk());

        $this->listings(self::CARDS);
        $many = $this->queriesFor(fn () => $this->get(route('listings.mine'))->assertOk());

        $this->assertLessThanOrEqual($few + 3, $many, "my listings: {$few} then {$many}");
    }

    /** The fallback exists and works — a card outside any grid still answers. */
    public function test_a_listing_with_no_loaded_relation_still_answers(): void
    {
        $listing = $this->listings(1)->first();

        $this->assertFalse($listing->relationLoaded('boosts'));
        $this->assertFalse(Boosted::isLabelled($listing));
        $this->assertTrue($listing->relationLoaded('boosts'));
    }
}
