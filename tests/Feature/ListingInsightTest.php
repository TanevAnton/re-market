<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Livewire\Listings\MyListings;
use App\Models\City;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\Offer;
use App\Models\Part;
use App\Models\User;
use App\Services\Listings\ListingInsight;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „Why is my listing not selling?"
 *
 * THE NUMBERS ARE NOT WHAT IS BEING TESTED. „240 preглеждания" is a figure the
 * database already held; the feature is the DIAGNOSIS, and the tests that
 * matter are about it saying the right thing and — more importantly — refusing
 * to say things it cannot support:
 *
 *   - no benchmark without a real sample, because „средно за този модел" over
 *     two listings is a rumour that gets quoted back in negotiations;
 *   - no verdict on a listing that is two days old;
 *   - paid visibility offered ONLY where the diagnosis is „too few people have
 *     seen it", never where the price is the problem;
 *   - and the panel is the seller's own, because it contains the best price
 *     anybody offered — their private negotiating position.
 */
class ListingInsightTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private Part $part;
    private ListingInsight $insight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->part   = Part::where('category', 'gpu')->firstOrFail();
        $this->seller = $this->user();

        $this->insight = app(ListingInsight::class);
    }

    private function user(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);
    }

    private function listing(array $attributes = [], ?User $owner = null): Listing
    {
        return Listing::factory()->create([
            'user_id'      => ($owner ?? $this->seller)->id,
            'city_id'      => City::first()->id,
            'category'     => 'gpu',
            'part_id'      => $this->part->id,
            'price_cents'  => 50000,
            'status'       => ListingStatus::Active,
            'view_count'   => 0,
            // Old enough that the „too early to tell" branch does not swallow
            // everything — that branch has its own test.
            'published_at' => now()->subDays(10),
            'bumped_at'    => now()->subDays(10),
            // LAST, and the spread is not optional: without it this helper
            // silently ignores every argument passed to it, and six tests here
            // asserted against numbers they never actually set.
            ...$attributes,
        ]);
    }

    /** A price band needs a sample; the refresh command refuses one otherwise. */
    private function bandAround(int $medianCents): void
    {
        $this->part->forceFill([
            'price_p25_cents'       => (int) round($medianCents * 0.9),
            'price_median_cents'    => $medianCents,
            'price_p75_cents'       => (int) round($medianCents * 1.1),
            'price_stats_at'        => now(),
            'active_listings_count' => 8,
        ])->save();
    }

    private function peers(int $count, int $views): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->listing(['view_count' => $views], $this->user());
        }
    }

    private function favourite(Listing $listing, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Favorite::create(['user_id' => $this->user()->id, 'listing_id' => $listing->id]);
        }
    }

    /** create(), not forceFill: Offer uses HasUuids and column defaults. */
    private function offer(Listing $listing, int $cents, OfferStatus $status = OfferStatus::Pending): Offer
    {
        return Offer::create([
            'listing_id'   => $listing->id,
            'buyer_id'     => $this->user()->id,
            'seller_id'    => $listing->user_id,
            'amount_cents' => $cents,
            'status'       => $status->value,
            'expires_at'   => now()->addDays(3),
        ]);
    }

    // --- the numbers ------------------------------------------------------

    public function test_it_counts_views_saves_and_offers(): void
    {
        $listing = $this->listing(['view_count' => 240]);

        $this->favourite($listing, 3);
        $this->offer($listing, 45000);
        $this->offer($listing, 47000);

        $stats = $this->insight->for($listing);

        $this->assertSame(240, $stats['views']);
        $this->assertSame(3, $stats['saves']);
        $this->assertSame(2, $stats['offers']);
        $this->assertSame(47000, $stats['best_offer']);
    }

    /**
     * THE ONE THAT KEEPS THE PANEL HONEST. Below four comparable listings there
     * is no comparison, and it is ABSENT rather than softened — a number that
     * looks authoritative and is not gets quoted back in negotiations.
     */
    public function test_there_is_no_benchmark_without_a_sample(): void
    {
        $listing = $this->listing(['view_count' => 10]);

        $this->peers(2, 400);

        $stats = $this->insight->for($listing);

        $this->assertNull($stats['median_views']);
        $this->assertNull($stats['median_offers']);
    }

    public function test_with_enough_peers_the_median_appears(): void
    {
        $listing = $this->listing(['view_count' => 10]);

        $this->peers(5, 400);

        $stats = $this->insight->for($listing);

        $this->assertSame(400, $stats['median_views']);
        $this->assertSame(5, $stats['peers']);
    }

    /** A sold listing's lifetime numbers are not a benchmark for a live one. */
    public function test_only_live_listings_are_compared_against(): void
    {
        $listing = $this->listing(['view_count' => 10]);

        $this->peers(5, 400);

        Listing::whereKeyNot($listing->id)->update(['status' => ListingStatus::Sold]);

        $this->assertNull($this->insight->for($listing)['median_views']);
    }

    // --- the diagnosis ----------------------------------------------------

    /** Nothing useful can be said about a listing that is two days old. */
    public function test_a_brand_new_listing_gets_no_verdict(): void
    {
        $listing = $this->listing([
            'view_count'   => 4,
            'published_at' => now()->subDay(),
        ]);

        $this->bandAround(30000);   // well below its price: „high", but too early

        $findings = $this->insight->for($listing)['findings'];

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('рано', $findings[0]['title']);
    }

    /**
     * THE CLEAREST SIGNAL ON THE SITE. People who save a listing want the item;
     * if they still do not ask, it is almost always the number.
     */
    public function test_saves_without_offers_points_at_the_price(): void
    {
        $listing = $this->listing(['view_count' => 200]);

        $this->favourite($listing, 4);

        $findings = $this->insight->for($listing)['findings'];

        $this->assertStringContainsString('запазиха', $findings[0]['title']);
        $this->assertSame('warn', $findings[0]['tone']);
    }

    public function test_views_without_saves_points_at_the_photos_or_the_price(): void
    {
        $listing = $this->listing(['view_count' => 120]);

        $findings = $this->insight->for($listing)['findings'];

        $this->assertStringContainsString('нито едно запазване', $findings[0]['title']);
    }

    /**
     * A seller leaving money on the table is also a failure of this screen —
     * and saying so is what makes the panel worth trusting when it says the
     * opposite.
     */
    public function test_it_says_when_the_price_is_too_low(): void
    {
        $this->bandAround(100000);

        $listing  = $this->listing(['price_cents' => 60000, 'view_count' => 200]);
        $findings = $this->insight->for($listing)['findings'];

        $titles = array_column($findings, 'title');

        $this->assertNotEmpty(array_filter($titles, fn ($t) => str_contains($t, 'под средната')));
    }

    public function test_it_names_the_best_offer_as_the_number_the_market_agreed_on(): void
    {
        $listing = $this->listing(['price_cents' => 50000, 'view_count' => 200]);

        $this->offer($listing, 40000);

        $findings = array_column($this->insight->for($listing)['findings'], 'title');

        $this->assertNotEmpty(array_filter($findings, fn ($t) => str_contains($t, '20% под искането')));
    }

    // --- the line that must not be crossed --------------------------------

    /**
     * THE INTEGRITY TEST, and the reason this file exists at all.
     *
     * Paid visibility is offered on exactly one diagnosis: „too few people have
     * seen it". A boost on an overpriced listing spends the seller's money to
     * show more people the same number they already declined — and teaches them
     * that paid visibility does not work, which costs far more than the €9
     * earns.
     */
    public function test_a_price_problem_never_suggests_paying_for_visibility(): void
    {
        $this->bandAround(30000);

        // Plenty of views, four saves, no offers, priced above the band: every
        // signal says price.
        $listing = $this->listing(['price_cents' => 50000, 'view_count' => 300]);
        $this->favourite($listing, 4);
        $this->peers(5, 300);

        foreach ($this->insight->for($listing)['findings'] as $finding) {
            $this->assertFalse(
                $finding['boost'],
                "„{$finding['title']}" .'" offered a boost for what is a price problem',
            );
        }
    }

    /** And where the diagnosis genuinely IS visibility, it offers it. */
    public function test_too_few_views_does_suggest_paying_for_visibility(): void
    {
        $listing = $this->listing(['view_count' => 10]);

        $this->peers(5, 400);

        $boosted = array_filter($this->insight->for($listing)['findings'], fn ($f) => $f['boost']);

        $this->assertNotEmpty($boosted, 'a listing with a tenth of the median views was offered nothing');
    }

    /** Never a wall of suggestions: three at most, or nobody acts on any. */
    public function test_at_most_three_findings(): void
    {
        $this->bandAround(30000);

        $listing = $this->listing(['price_cents' => 50000, 'view_count' => 300]);
        $this->favourite($listing, 5);
        $this->offer($listing, 20000);
        $this->peers(6, 900);

        $this->assertLessThanOrEqual(3, count($this->insight->for($listing)['findings']));
    }

    // --- the screen -------------------------------------------------------

    public function test_the_panel_opens_and_closes_on_the_sellers_own_page(): void
    {
        $listing = $this->listing(['view_count' => 240]);

        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->assertDontSee('Как се движи обявата')
            ->call('toggleStats', $listing->id)
            ->assertSee('Как се движи обявата')
            ->assertSee('240')
            ->call('toggleStats', $listing->id)
            ->assertDontSee('Как се движи обявата');
    }

    /**
     * THE ONE THAT PROTECTS SOMEBODY'S NEGOTIATING POSITION.
     *
     * The panel carries the best price anybody offered. The first version
     * leaned on a scoped firstOrFail() during render, which refused the data
     * but threw ModelNotFoundException halfway through drawing the page — so
     * the gate moved onto the action, where every other owner-only path in this
     * codebase puts it, and answers 404 like they do.
     */
    public function test_a_seller_cannot_open_somebody_elses_stats(): void
    {
        $stranger = $this->user();
        $theirs   = $this->listing([], $stranger);

        $this->offer($theirs, 49000);

        /*
         * assertNotFound(), NOT expectException — and this is the SECOND time
         * that caught me in this codebase, after BoostPanelTest. Livewire turns
         * an abort() inside an action into a 404 response before PHPUnit ever
         * sees an exception, and Testable forwards unknown methods to the
         * underlying TestResponse. The assertion after it is the one that
         * matters: the panel did not open.
         */
        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->call('toggleStats', $theirs->id)
            ->assertNotFound()
            ->assertSet('showingStats', null)
            ->assertDontSee('Как се движи обявата');
    }

    /** With no sample, the screen says so rather than showing an empty column. */
    public function test_the_screen_admits_when_it_cannot_compare(): void
    {
        $listing = $this->listing(['view_count' => 50]);

        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->call('toggleStats', $listing->id)
            ->assertSee('няма достатъчно подобни обяви');
    }
}
