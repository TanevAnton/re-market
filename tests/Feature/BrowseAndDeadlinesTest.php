<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\BrowseListings;
use App\Livewire\Deals\MyDeals;
use App\Livewire\Offers\OfferInbox;
use App\Models\City;
use App\Models\Listing;
use App\Models\Offer;
use App\Models\User;
use App\Services\Offers\OfferService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The browse sidebar and the two screens where a clock is running.
 *
 * The deadline tests are the ones worth having: an offer that expires costs
 * both sides a deal they had already agreed the price of, and a deal that
 * lapses marks somebody abandoned permanently. Both were rendered in the
 * faintest text on the card.
 */
class BrowseAndDeadlinesTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $buyer;
    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller = User::factory()->create();
        $this->buyer  = User::factory()->create();

        $this->listing = Listing::factory()->create([
            'user_id'         => $this->seller->id,
            'status'          => ListingStatus::Active,
            'category'        => 'gpu',
            'price_cents'     => 100000,
            'min_offer_cents' => 80000,
            'offers_enabled'  => true,
            'city_id'         => City::first()->id,
        ]);
    }

    // --- the city filter, which had no control at all ---------------------

    public function test_the_city_filter_is_reachable_from_the_page(): void
    {
        $city = City::first();

        // It was a #[Url] property the query honoured and nothing rendered, so
        // it worked only if you typed ?grad= into the address bar yourself.
        Livewire::test(BrowseListings::class)
            ->assertSee($city->name())
            ->set('city', $city->slug)
            ->assertSee($this->listing->title);
    }

    public function test_filtering_by_another_city_excludes_the_listing(): void
    {
        $other = City::where('id', '!=', $this->listing->city_id)->firstOrFail();

        Livewire::test(BrowseListings::class)
            ->set('city', $other->slug)
            ->assertDontSee($this->listing->title);
    }

    // --- active filter chips ---------------------------------------------

    public function test_applied_filters_are_listed_and_removable(): void
    {
        $component = Livewire::test(BrowseListings::class)
            ->set('category', 'gpu')
            ->set('priceMax', '400');

        $chips = collect($component->instance()->activeFilters());

        $this->assertTrue($chips->contains(fn ($c) => $c['label'] === 'до 400 €'));

        $component->call('clearFilter', 'price')
            ->assertSet('priceMax', '')
            ->assertSet('category', 'gpu');
    }

    /**
     * clearFilters deliberately keeps the category - it is which page you are
     * on rather than a filter on it - and the label says "изчисти филтрите"
     * rather than "всички" for exactly that reason.
     */
    public function test_clearing_the_filters_keeps_the_category(): void
    {
        Livewire::test(BrowseListings::class)
            ->set('category', 'gpu')
            ->set('priceMax', '400')
            ->set('q', 'нещо')
            ->call('clearFilters')
            ->assertSet('priceMax', '')
            ->assertSet('q', '')
            ->assertSet('category', 'gpu');
    }

    public function test_removing_the_category_chip_drops_its_facets_too(): void
    {
        Livewire::test(BrowseListings::class)
            ->set('category', 'gpu')
            ->set('specs', ['vram_gb' => ['min' => '12']])
            ->call('clearFilter', 'category')
            ->assertSet('category', '')
            // Facets are category-specific; leaving them behind would filter
            // the next category by a spec it does not have.
            ->assertSet('specs', []);
    }

    // --- deadlines --------------------------------------------------------

    private function offer(): Offer
    {
        return app(OfferService::class)->place($this->listing, $this->buyer, 90000);
    }

    public function test_an_offer_nearing_expiry_is_shown_as_urgent(): void
    {
        $offer = $this->offer();
        $offer->forceFill(['expires_at' => now()->addHours(3)])->save();

        // Under six hours the countdown turns red rather than staying the
        // quietest thing on the card.
        Livewire::actingAs($this->seller)
            ->test(OfferInbox::class)
            ->assertSee('остават 3 ч')
            // bg-bad-soft, not text-bad: the latter appears on other controls
            // and the assertion would pass for the wrong reason.
            ->assertSee('bg-bad-soft', escape: false);
    }

    public function test_a_comfortable_offer_window_is_not_shouted_about(): void
    {
        $offer = $this->offer();
        $offer->forceFill(['expires_at' => now()->addHours(40)])->save();

        Livewire::actingAs($this->seller)
            ->test(OfferInbox::class)
            ->assertSee('остават 2 дни')
            ->assertDontSee('bg-bad-soft', escape: false);
    }

    // --- whose move is it -------------------------------------------------

    public function test_the_seller_is_told_an_offer_waits_on_them(): void
    {
        $this->offer();

        Livewire::actingAs($this->seller)
            ->test(OfferInbox::class)
            ->assertSee('Чака твоя отговор');
    }

    public function test_the_buyer_is_not_told_their_own_offer_waits_on_them(): void
    {
        $this->offer();

        Livewire::actingAs($this->buyer)
            ->test(OfferInbox::class)
            ->set('tab', 'sent')
            ->assertDontSee('Чака твоя отговор');
    }

    /**
     * The rule the model's own comment warns about: a plain offer waits on the
     * seller, a counter waits on the buyer. Reading it as "seller_id = me" is
     * how a counter sits unanswered forever.
     */
    public function test_a_counter_waits_on_the_buyer_not_the_seller(): void
    {
        $counter = app(OfferService::class)->counter($this->offer(), $this->seller, 95000);

        $this->assertTrue($counter->awaitsResponseFrom($this->buyer->id));
        $this->assertFalse($counter->awaitsResponseFrom($this->seller->id));
    }

    public function test_a_deal_awaiting_your_confirmation_says_so(): void
    {
        app(OfferService::class)->accept($this->offer(), $this->seller);

        Livewire::actingAs($this->buyer)
            ->test(MyDeals::class)
            ->assertSee('Чака твоето потвърждение');
    }

    /**
     * Someone who has done their part should not be shouted at by a countdown
     * that is no longer theirs, and should be told plainly who is late.
     */
    public function test_after_confirming_you_are_told_who_is_being_waited_on(): void
    {
        $deal = app(OfferService::class)->accept($this->offer(), $this->seller);
        app(\App\Services\Deals\DealService::class)->confirm($deal, $this->buyer);

        Livewire::actingAs($this->buyer)
            ->test(MyDeals::class)
            ->assertDontSee('Чака твоето потвърждение')
            ->assertSee('Ти потвърди.');
    }
}
