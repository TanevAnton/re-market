<?php

namespace Tests\Feature;

use App\Enums\BoostTier;
use App\Enums\ListingStatus;
use App\Livewire\Home;
use App\Models\Listing;
use App\Models\User;
use App\Services\Billing\BoostService;
use App\Services\Billing\CreditService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The front page.
 *
 * The thing worth protecting here is that it tells the truth: the counts are
 * real counts, a listing that is not public does not appear on the most public
 * page on the site, and the sections that have nothing in them say nothing
 * rather than rendering an empty rail.
 */
class HomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::factory()->create($attributes + [
            'user_id' => User::factory()->create()->id,
            'status'  => ListingStatus::Active,
        ]);
    }

    public function test_a_guest_gets_the_home_page(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Категории')
            ->assertSee('Защо не е като другите обяви');
    }

    /** The name did not change when the URL did. */
    public function test_browse_still_answers_on_its_route_name(): void
    {
        $this->get(route('browse'))->assertOk();
        $this->assertSame(url('/obiavi'), route('browse'));
        $this->assertSame(url('/'), route('home'));
    }

    public function test_every_category_is_listed_even_the_empty_ones(): void
    {
        // A marketplace that hides what it has none of tells a first visitor
        // nothing about what it is for.
        $categories = Livewire::test(Home::class)->instance()->categories();

        $this->assertCount(count(config('catalog.categories')), $categories);
        $this->assertContains('gpu', array_column($categories, 'key'));
    }

    public function test_category_counts_are_real_and_busiest_first(): void
    {
        $this->listing(['category' => 'gpu']);
        $this->listing(['category' => 'gpu']);
        $this->listing(['category' => 'cpu']);

        $categories = collect(Livewire::test(Home::class)->instance()->categories());

        $this->assertSame('gpu', $categories->first()['key']);
        $this->assertSame(2, $categories->firstWhere('key', 'gpu')['count']);
        $this->assertSame(1, $categories->firstWhere('key', 'cpu')['count']);
        $this->assertSame(0, $categories->firstWhere('key', 'monitor')['count']);
    }

    public function test_the_newest_listings_are_shown_newest_first(): void
    {
        $old = $this->listing(['published_at' => now()->subDays(3)]);
        $new = $this->listing(['published_at' => now()]);

        $newest = Livewire::test(Home::class)->instance()->newest();

        $this->assertSame($new->id, $newest->first()->id);
        $this->assertTrue($newest->contains('id', $old->id));
    }

    /**
     * The home page is the most public page on the site. A listing held for
     * review appearing on it would defeat the entire moderation gate.
     */
    public function test_a_listing_awaiting_review_never_reaches_the_front_page(): void
    {
        $held = $this->listing(['status' => ListingStatus::PendingReview]);

        $this->get(route('home'))->assertDontSee($held->title);
    }

    public function test_a_removed_listing_never_reaches_the_front_page(): void
    {
        $removed = $this->listing(['status' => ListingStatus::Removed]);

        $this->get(route('home'))->assertDontSee($removed->title);
    }

    /**
     * With no views recorded the rail would be an arbitrary ordering presented
     * as a ranking, so it does not render at all.
     */
    public function test_the_most_viewed_rail_stays_empty_until_something_has_been_viewed(): void
    {
        $this->listing(['view_count' => 0]);

        $this->assertCount(0, Livewire::test(Home::class)->instance()->mostViewed());
    }

    public function test_the_most_viewed_rail_ranks_by_views(): void
    {
        $quiet   = $this->listing(['view_count' => 3]);
        $popular = $this->listing(['view_count' => 900]);

        $viewed = Livewire::test(Home::class)->instance()->mostViewed();

        $this->assertSame($popular->id, $viewed->first()->id);
        $this->assertTrue($viewed->contains('id', $quiet->id));
    }

    // --- the homepage's paid slot -----------------------------------------

    private function promote(Listing $listing): void
    {
        $seller = $listing->user;

        app(CreditService::class)->grant($seller, 100_00, 'test');
        app(BoostService::class)->buy($listing, $seller, BoostTier::Front);
    }

    public function test_a_paid_listing_reaches_the_front_page_block(): void
    {
        $paid = $this->listing(['title' => 'Платената карта на началната']);
        $this->promote($paid);

        $promoted = Livewire::test(Home::class)->instance()->promoted();

        $this->assertCount(1, $promoted);
        $this->assertSame($paid->id, $promoted->first()->id);

        $this->get(route('home'))
            ->assertSee('Платени позиции')
            ->assertSee('Платената карта на началната');
    }

    /**
     * A CATEGORY PIN IS NOT A HOMEPAGE SLOT.
     *
     * They are two purchases at two prices, and the day they stop being
     * separate is the day every 9 € pin buyer silently gets the 27 € placement
     * — which devalues the thing for everyone who paid for it properly.
     */
    public function test_a_category_pin_does_not_reach_the_front_page(): void
    {
        $listing = $this->listing();
        $seller  = $listing->user;

        app(CreditService::class)->grant($seller, 100_00, 'test');
        app(BoostService::class)->buy($listing, $seller, BoostTier::Pin);

        $this->assertCount(0, Livewire::test(Home::class)->instance()->promoted());
    }

    /** And the reverse: the homepage slot does not pin a category. */
    public function test_a_front_page_slot_does_not_pin_the_category(): void
    {
        $paid = $this->listing(['category' => 'gpu']);
        $this->promote($paid);

        Livewire::test(\App\Livewire\BrowseListings::class)
            ->assertViewHas('pinned', fn ($p) => $p->isEmpty());
    }

    public function test_the_front_page_block_is_capped(): void
    {
        config(['remarket.boosts.max_front_page' => 2]);

        for ($i = 0; $i < 4; $i++) {
            $this->promote($this->listing(['title' => "Платена {$i}"]));
        }

        $this->assertCount(2, Livewire::test(Home::class)->instance()->promoted());
    }

    public function test_setting_the_front_page_cap_to_zero_turns_the_block_off(): void
    {
        config(['remarket.boosts.max_front_page' => 0]);

        $this->promote($this->listing());

        $this->assertCount(0, Livewire::test(Home::class)->instance()->promoted());
        $this->get(route('home'))->assertDontSee('Платени позиции');
    }

    /** Nothing clears a flag — `visible()` simply stops matching it. */
    public function test_a_sold_listing_leaves_the_front_page_block(): void
    {
        $paid = $this->listing();
        $this->promote($paid);

        $paid->forceFill(['status' => ListingStatus::Sold])->save();

        $this->assertCount(0, Livewire::test(Home::class)->instance()->promoted());
    }

    public function test_a_paid_front_page_listing_carries_the_label(): void
    {
        $this->promote($this->listing());

        $this->get(route('home'))->assertSee('промотирана');
    }

    /**
     * The two rails share one slot: paid when somebody bought, most-viewed
     * when nobody did. A „Платени позиции" heading over an empty grid would be
     * a dead section on the most visible page of the site.
     */
    public function test_most_viewed_fills_the_slot_only_while_nothing_is_paid(): void
    {
        $this->listing(['view_count' => 900]);

        $this->get(route('home'))
            ->assertSee('Най-разглеждани')
            ->assertDontSee('Платени позиции');

        $this->promote($this->listing(['view_count' => 5]));

        $this->get(route('home'))
            ->assertSee('Платени позиции')
            ->assertDontSee('Най-разглеждани');
    }

    public function test_the_stats_count_what_they_say_they_count(): void
    {
        $seller = User::factory()->create();

        Listing::factory()->count(3)->create([
            'user_id' => $seller->id,
            'status'  => ListingStatus::Active,
        ]);
        $this->listing(['status' => ListingStatus::Expired]);

        $stats = Livewire::test(Home::class)->instance()->stats();

        $this->assertSame(3, $stats['listings']);
        // Three listings, one seller - the number is sellers, not listings.
        $this->assertSame(1, $stats['sellers']);
        $this->assertSame(0, $stats['deals']);
    }

    public function test_the_search_box_submits_to_browse(): void
    {
        $this->get(route('home'))
            ->assertSee('action="'.route('browse').'"', false)
            ->assertSee('name="q"', false);
    }

    /**
     * The chips point at the catalogue page for the model, not at a search for
     * its name. That is the whole difference: a search for "RTX 4070" is empty
     * the week nobody is selling one, while the model page still carries the
     * specs, the price band and somewhere to leave an alert - and the home page
     * linking to it is how the catalogue gets crawled at all.
     */
    public function test_popular_models_link_to_their_catalogue_page(): void
    {
        $listing = $this->listing();

        $parts = Livewire::test(Home::class)->instance()->popularParts();

        $this->assertNotEmpty($parts);
        $this->assertSame(1, (int) $parts[0]->live_count);
        $this->assertSame($listing->part->fullName(), $parts[0]->fullName());

        $this->get(route('home'))
            ->assertSee(route('part', $listing->part), escape: false);
    }
}
