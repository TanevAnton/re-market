<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\ShowListing;
use App\Livewire\ShowPart;
use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „12% под средното за модела" — the one judgement the site makes out loud.
 *
 * Every other number on a listing is the seller's. This one is ours, printed
 * next to their price, and a buyer who learns it means nothing will never read
 * it again. So the tests here are mostly about the badge NOT appearing: the
 * thresholds, the thin markets, the stale band, and the direction it refuses
 * to run in.
 *
 * That last one is a business decision rather than a technical one. A listing
 * can be told it is cheap and never that it is expensive - the band is right
 * there for a buyer to work the rest out - because a marketplace that grades
 * its own sellers' prices in public runs out of sellers, and the buyers lose
 * more from that than the badge ever gave them.
 */
class DealBadgeTest extends TestCase
{
    use RefreshDatabase;

    private const BADGE = 'под средното за модела';

    private Part $part;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        $this->part = Part::create([
            'category'     => 'gpu',
            'manufacturer' => 'ASUS',
            'model'        => 'Strix Oracle 9999',
            'slug'         => 'asus-strix-oracle-9999',
            'specs'        => [],
            'is_published' => true,
        ]);

        $this->band();
    }

    /** A model with a fresh band and a market deep enough to speak about. */
    private function band(int $p25 = 85000, int $median = 100000, int $p75 = 115000, int $live = 9): void
    {
        $this->part->forceFill([
            'price_p25_cents'       => $p25,
            'price_median_cents'    => $median,
            'price_p75_cents'       => $p75,
            'price_stats_at'        => now(),
            'active_listings_count' => $live,
        ])->save();
    }

    /** @param  array<string, mixed>  $attributes */
    private function listing(int $cents, array $attributes = []): Listing
    {
        return Listing::factory()->create([
            'user_id'         => User::factory()->create()->id,
            'part_id'         => $this->part->id,
            'category'        => 'gpu',
            'status'          => ListingStatus::Active,
            'city_id'         => City::first()->id,
            'price_cents'     => $cents,
            // The factory derives a floor from the price; these tests move the
            // price around and a floor above it is a CHECK violation, not a
            // test failure worth reading.
            'min_offer_cents' => null,
            ...$attributes,
        ]);
    }

    // --- the arithmetic ---------------------------------------------------

    public function test_a_cheap_listing_knows_how_far_under_it_is(): void
    {
        $this->assertSame(20, $this->listing(80000)->priceAdvantage());
    }

    public function test_a_listing_at_the_median_claims_nothing(): void
    {
        $this->assertNull($this->listing(100000)->priceAdvantage());
    }

    /**
     * The direction the badge does not run in. An expensive listing gets
     * silence, not a warning - see the class docblock.
     */
    public function test_an_expensive_listing_is_not_labelled(): void
    {
        $listing = $this->listing(140000);

        $this->assertNull($listing->priceAdvantage());

        Livewire::test(ShowListing::class, ['listing' => $listing])
            ->assertDontSee(self::BADGE)
            ->assertDontSee('над средното');
    }

    /**
     * Bottom quartile is necessary but not sufficient. In a tight market p25
     * can sit 2% under the median, and a badge that fires at 2% is noise that
     * teaches people to stop reading the badge.
     */
    public function test_bottom_quartile_but_barely_cheaper_is_not_a_deal(): void
    {
        $this->band(p25: 98000, median: 100000, p75: 103000);

        $this->assertNull($this->listing(97000)->priceAdvantage());
    }

    /** Under the median but mid-pack is an ordinary price. */
    public function test_under_the_median_but_above_the_quartile_is_ordinary(): void
    {
        $this->assertNull($this->listing(90000)->priceAdvantage());
    }

    // --- when the site has no business making the claim -------------------

    /**
     * Cheapest of four is arithmetic about four people, not a market. The band
     * itself is allowed on a thinner sample because it is presented as a range
     * and dated; the badge is presented as a verdict.
     */
    public function test_a_thin_market_produces_no_verdict(): void
    {
        $this->band(live: 4);

        $this->assertNull($this->listing(80000)->priceAdvantage());
    }

    public function test_a_stale_band_produces_no_verdict(): void
    {
        config(['remarket.parts.price_band_max_age_days' => 7]);

        $listing = $this->listing(80000);
        $this->part->forceFill(['price_stats_at' => now()->subDays(30)])->save();

        $this->assertNull($listing->fresh()->priceAdvantage());
    }

    /** Free-text listings are most of a young marketplace and must not error. */
    public function test_a_listing_with_no_catalogue_model_is_simply_quiet(): void
    {
        $listing = $this->listing(80000, ['part_id' => null, 'category' => 'other']);

        $this->assertNull($listing->priceAdvantage());
    }

    /** A sold listing boasting about its price advertises something gone. */
    public function test_a_sold_listing_stops_boasting(): void
    {
        $listing = $this->listing(80000, ['status' => ListingStatus::Sold]);

        $this->assertNull($listing->priceAdvantage());

        Livewire::test(ShowListing::class, ['listing' => $listing])
            ->assertDontSee(self::BADGE);
    }

    // --- where it shows up ------------------------------------------------

    public function test_the_listing_page_shows_it_with_a_way_to_check_it(): void
    {
        $listing = $this->listing(80000);

        Livewire::test(ShowListing::class, ['listing' => $listing])
            ->assertSee('20% '.self::BADGE)
            ->assertSee('Виж от какво е сметнато');
    }

    /**
     * Both cards render; exactly one carries the badge, and it is the cheap
     * one. Counted in the HTML rather than asserted in order, because "appears
     * somewhere after this title" would also pass if the badge landed on the
     * wrong card in a two-card grid.
     */
    public function test_only_the_cheap_card_in_a_grid_carries_it(): void
    {
        $this->listing(80000, ['title' => 'Евтината карта']);
        $this->listing(120000, ['title' => 'Скъпата карта']);

        $html = Livewire::test(ShowPart::class, ['part' => $this->part])
            ->assertSee('Евтината карта')
            ->assertSee('Скъпата карта')
            ->html();

        $this->assertSame(1, substr_count($html, self::BADGE));

        /*
         * The percentage is what pins it to the right card. Card ORDER is not
         * asserted: the grid sorts on bumped_at, the factory randomises it, and
         * a positional assertion here would fail about half the time - the same
         * flake the seller-listings test was pinned for.
         */
        $this->assertStringContainsString('20% '.self::BADGE, $html);
    }

    // --- the primitive underneath -----------------------------------------

    /**
     * Part::priceStanding is deliberately verdict-free: it reports where a
     * price sits and leaves what that MEANS to the caller, because the answer
     * differs for a buyer's badge, a seller's guidance and a moderator's view.
     */
    public function test_price_standing_reports_position_without_judging_it(): void
    {
        $part = $this->part->fresh();

        $this->assertSame('low', $part->priceStanding(80000)['position']);
        $this->assertSame('mid', $part->priceStanding(100000)['position']);
        $this->assertSame('high', $part->priceStanding(140000)['position']);

        $this->assertSame(-20, $part->priceStanding(80000)['percent']);
        $this->assertSame(40, $part->priceStanding(140000)['percent']);
    }

    public function test_price_standing_is_null_without_a_band(): void
    {
        $this->part->forceFill([
            'price_p25_cents'    => null,
            'price_median_cents' => null,
            'price_p75_cents'    => null,
            'price_stats_at'     => null,
        ])->save();

        $this->assertNull($this->part->fresh()->priceStanding(80000));
    }
}
