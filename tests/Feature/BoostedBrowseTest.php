<?php

namespace Tests\Feature;

use App\Enums\BoostTier;
use App\Enums\ListingStatus;
use App\Livewire\BrowseListings;
use App\Models\City;
use App\Models\Listing;
use App\Models\User;
use App\Services\Billing\BoostService;
use App\Services\Billing\CreditService;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Paid visibility, on the page.
 *
 * Two things are being protected here and only one of them is a feature.
 *
 * The feature: a seller who paid gets the slot. The PROTECTION: the grid stays
 * something a visitor can believe. Every test below about caps, separation,
 * labels and filters exists because the failure mode of this whole area is not
 * a crash — it is a browse page that quietly becomes an advert board, one
 * config change at a time, and nobody notices until the sellers who came for
 * a clean site have gone.
 */
class BoostedBrowseTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private BoostService $boosts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        $this->seller = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        $this->boosts = app(BoostService::class);
        app(CreditService::class)->grant($this->seller, 100_00, 'test');
    }

    /**
     * `bumped_at` pinned to now: the factory scatters it over 45 days, and
     * BoostService refuses to sell a bump while the free daily one is
     * available — so without this the bump test here is a dice roll. Same
     * reason as BoostTest::listing().
     */
    private function listing(array $attributes = []): Listing
    {
        return Listing::factory()->create([
            'user_id'   => $this->seller->id,
            'city_id'   => City::first()->id,
            'category'  => 'gpu',
            'status'    => ListingStatus::Active,
            'bumped_at' => now(),
            ...$attributes,
        ]);
    }

    private function pinned(array $attributes = []): Listing
    {
        $listing = $this->listing($attributes);
        $this->boosts->buy($listing, $this->seller, BoostTier::Pin);

        return $listing;
    }

    // --- the cap ----------------------------------------------------------

    /**
     * THE ONE THAT PROTECTS THE PRODUCT.
     *
     * Five sellers pay for a top slot; two get one. The pressure to raise this
     * number will come from the only part of the site that earns money, so the
     * number is in config and this test is what makes raising it deliberate.
     */
    public function test_the_paid_block_is_capped_however_many_have_paid(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pinned(['title' => "Платена карта номер {$i} за теста"]);
        }

        Livewire::test(BrowseListings::class)
            ->assertViewHas('pinned', fn ($pinned) => $pinned->count() === 2);
    }

    public function test_setting_the_cap_to_zero_turns_paid_slots_off(): void
    {
        config(['remarket.boosts.max_pinned_per_page' => 0]);

        $this->pinned();

        Livewire::test(BrowseListings::class)
            ->assertViewHas('pinned', fn ($pinned) => $pinned->isEmpty())
            ->assertDontSee('Платени позиции');
    }

    /**
     * A pinned slot is worth what it is because it is the first thing seen.
     * Repeating it on page four sells the same thing twice and buries four
     * more organic listings every time.
     */
    public function test_paid_slots_appear_on_the_first_page_only(): void
    {
        $this->pinned();
        Listing::factory()->count(30)->create([
            'user_id'  => $this->seller->id,
            'city_id'  => City::first()->id,
            'category' => 'gpu',
            'status'   => ListingStatus::Active,
        ]);

        Livewire::test(BrowseListings::class)
            ->assertViewHas('pinned', fn ($p) => $p->count() === 1)
            // gotoPage(), not set('page', 2): WithPagination keeps the page in
            // a trait property, so setting it as component data throws.
            ->call('gotoPage', 2)
            ->assertViewHas('pinned', fn ($p) => $p->isEmpty());
    }

    // --- honesty ----------------------------------------------------------

    /** Nothing appears twice on one screen. */
    public function test_a_pinned_listing_is_not_also_in_the_organic_grid(): void
    {
        $pinned = $this->pinned();

        Livewire::test(BrowseListings::class)
            ->assertViewHas('listings', fn ($rows) => ! $rows->pluck('id')->contains($pinned->id));
    }

    /**
     * A paid slot that ignores the search is an advert wearing a search
     * result, and it is what makes people stop believing the order means
     * anything. The pins run through the same filter builder as the results.
     */
    public function test_paid_slots_respect_the_filters(): void
    {
        $this->pinned(['category' => 'gpu', 'title' => 'Платена видеокарта за теста']);

        Livewire::test(BrowseListings::class)
            ->set('category', 'cpu')
            ->assertViewHas('pinned', fn ($p) => $p->isEmpty())
            ->assertDontSee('Платена видеокарта за теста');
    }

    /**
     * THE ONE THAT CAUGHT A REAL BUG.
     *
     * The organic query excludes whatever is pinned, so when the only listing
     * matching a filter is a paid one, `$listings` comes back empty. The first
     * version of the view nested the paid block inside the empty-state `@else`,
     * which meant that visitor was told „няма обяви по тези филтри" while the
     * listing sat one query away, already fetched and never drawn.
     */
    public function test_a_lone_pinned_listing_is_not_an_empty_page(): void
    {
        $this->pinned(['title' => 'Единствената карта в категорията']);

        Livewire::test(BrowseListings::class)
            ->assertViewHas('listings', fn ($rows) => $rows->isEmpty())
            ->assertSee('Единствената карта в категорията')
            ->assertDontSee('Няма обяви по тези филтри');
    }

    public function test_a_pinned_listing_that_sells_leaves_the_paid_block(): void
    {
        $listing = $this->pinned();

        Livewire::test(BrowseListings::class)
            ->assertViewHas('pinned', fn ($p) => $p->count() === 1);

        $listing->forceFill(['status' => ListingStatus::Sold])->save();

        // Nothing cleared a flag — `visible()` simply stops matching it.
        Livewire::test(BrowseListings::class)
            ->assertViewHas('pinned', fn ($p) => $p->isEmpty());
    }

    // --- the label --------------------------------------------------------

    public function test_a_boosted_listing_carries_the_label(): void
    {
        $this->pinned(['title' => 'Платена карта с етикет']);

        Livewire::test(BrowseListings::class)->assertSee('промотирана');
    }

    /**
     * A bump is over the moment it is bought, so it never wears the badge.
     * Labelling a week-old bump would mislead in the direction the disclosure
     * rules exist to prevent.
     */
    public function test_a_bumped_listing_carries_no_label(): void
    {
        $this->boosts->buy($this->listing(), $this->seller, BoostTier::Bump);

        Livewire::test(BrowseListings::class)->assertDontSee('промотирана');
    }

    public function test_an_ordinary_listing_carries_no_label(): void
    {
        $this->listing();

        Livewire::test(BrowseListings::class)
            ->assertDontSee('промотирана')
            ->assertDontSee('Платени позиции');
    }

    // --- the disclosure ---------------------------------------------------

    /**
     * The Omnibus Directive wants the consumer told what decides the order and
     * that payment can influence it. The default sort is the honest hard case:
     * a paid bump DOES move a listing in „Най-нови", so the notice says so
     * rather than claiming payment never affects the order.
     */
    public function test_the_default_sort_admits_that_paying_moves_a_listing(): void
    {
        $this->listing();

        Livewire::test(BrowseListings::class)
            ->assertSee('Най-нови')
            ->assertSee('срещу заплащане');
    }

    /** And under a sort where it genuinely does not, it says that instead. */
    public function test_a_price_sort_says_payment_does_not_affect_it(): void
    {
        $this->listing();

        Livewire::test(BrowseListings::class)
            ->set('sort', 'price_asc')
            ->assertSee('плащане не влияе');
    }

    // --- the thing that quietly breaks -----------------------------------

    /**
     * THE N+1 GUARD.
     *
     * `Boosted::` reads the loaded relation. Drop `Boosted::eagerLoad()` from
     * the browse query and every card asks the database twice — fifty extra
     * round trips on a full page — and nothing looks wrong until the site is
     * busy. A count, not a stopwatch, so it fails for the right reason.
     */
    public function test_a_full_grid_does_not_query_once_per_card(): void
    {
        Listing::factory()->count(24)->create([
            'user_id'  => $this->seller->id,
            'city_id'  => City::first()->id,
            'category' => 'gpu',
            'status'   => ListingStatus::Active,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        Livewire::test(BrowseListings::class);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            30,
            $count,
            "a 24-card grid ran {$count} queries — Boosted::eagerLoad() has probably been dropped from BrowseListings",
        );
    }
}
