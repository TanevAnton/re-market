<?php

namespace Tests\Feature;

use App\Enums\BoostTier;
use App\Enums\ListingStatus;
use App\Livewire\Billing\MyCredit;
use App\Livewire\Listings\MyListings;
use App\Models\Boost;
use App\Models\City;
use App\Models\Listing;
use App\Models\User;
use App\Services\Billing\CreditService;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screens where money is spent.
 *
 * BoostTest covers what the service refuses. This file covers whether the
 * seller is ever put in front of a button that takes their money and gives
 * them nothing — a priced bump that is free right now, a „Купи" they cannot
 * afford, a tier already running. A refusal that only exists in the service is
 * a 500 in the middle of somebody paying.
 */
class BoostPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        $this->seller = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);
    }

    /** bumped_at => now: the free daily bump has just been used. */
    private function listing(array $attributes = [], ?User $owner = null): Listing
    {
        return Listing::factory()->create([
            'user_id'   => ($owner ?? $this->seller)->id,
            'city_id'   => City::first()->id,
            'category'  => 'gpu',
            'status'    => ListingStatus::Active,
            'bumped_at' => now(),
            ...$attributes,
        ]);
    }

    private function credit(int $cents): void
    {
        app(CreditService::class)->grant($this->seller, $cents, 'test');
    }

    // --- the ladder -------------------------------------------------------

    public function test_the_ladder_opens_and_shows_all_three_tiers(): void
    {
        $listing = $this->listing();
        $this->credit(50_00);

        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->assertDontSee('Плати за повече видимост')
            ->call('openBoost', $listing->id)
            ->assertSee('Плати за повече видимост')
            ->assertSee(BoostTier::Bump->label())
            ->assertSee(BoostTier::Highlight->label())
            ->assertSee(BoostTier::Pin->label());
    }

    public function test_buying_a_pin_spends_the_balance_and_closes_the_panel(): void
    {
        $listing = $this->listing();
        $this->credit(50_00);

        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->call('openBoost', $listing->id)
            ->call('buyBoost', $listing->id, BoostTier::Pin->value)
            ->assertHasNoErrors()
            ->assertSet('boosting', null);

        $this->assertSame(1, Boost::running()->pinned()->count());
        $this->assertSame(50_00 - BoostTier::Pin->priceCents(), app(CreditService::class)->balance($this->seller));
    }

    /**
     * THE ONE THAT PROTECTS THE SELLER'S WALLET.
     *
     * A paid bump and the free daily bump do the same thing. While the free
     * one is available the screen must not offer the paid one — and if the
     * request arrives anyway, from a stale page or a double click, it must be
     * refused rather than charged.
     */
    public function test_a_free_bump_is_not_offered_for_sale(): void
    {
        $listing = $this->listing(['bumped_at' => now()->subDays(3)]);
        $this->credit(50_00);

        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->call('openBoost', $listing->id)
            ->assertSee('безплатно')
            ->call('buyBoost', $listing->id, BoostTier::Bump->value)
            ->assertHasErrors('boost');

        $this->assertSame(0, Boost::count());
        $this->assertSame(50_00, app(CreditService::class)->balance($this->seller));
    }

    public function test_a_short_balance_is_a_message_not_a_crash(): void
    {
        $listing = $this->listing();
        $this->credit(50);   // half a euro

        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->call('buyBoost', $listing->id, BoostTier::Pin->value)
            ->assertHasErrors('boost');

        $this->assertSame(0, Boost::count());
        $this->assertSame(50, app(CreditService::class)->balance($this->seller));
    }

    public function test_the_same_window_cannot_be_bought_twice_from_the_screen(): void
    {
        $listing = $this->listing();
        $this->credit(50_00);

        $panel = Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->call('buyBoost', $listing->id, BoostTier::Pin->value)
            ->assertHasNoErrors()
            ->call('buyBoost', $listing->id, BoostTier::Pin->value)
            ->assertHasErrors('boost');

        $panel->call('openBoost', $listing->id)->assertSee('активно');

        $this->assertSame(1, Boost::count());
    }

    /**
     * Paying for a listing you do not own is not a mistake the screen can
     * make, but it is one a crafted request can try. The service aborts 404.
     *
     * THIS TEST CAUGHT A REAL BUG, and the bug is a language trap worth
     * knowing: Symfony's HttpException extends \RuntimeException. The first
     * version of buyBoost() caught RuntimeException to turn the service's
     * refusals into readable messages — and quietly caught the `abort(404)`
     * along with them. abort() carries no message, so an ownership violation
     * rendered as a blank red line and a 200 response. Any component that
     * catches RuntimeException around a service that aborts has the same hole;
     * ManageBundle had it too.
     *
     * assertNotFound(), NOT expectException(): Livewire's Testable forwards
     * unknown methods to the underlying TestResponse, so the abort is already
     * a 404 response by the time the assertion runs. The two assertions after
     * it are the ones that matter — nothing bought, nothing taken.
     */
    public function test_you_cannot_boost_a_stranger_s_listing_from_the_screen(): void
    {
        $stranger = User::factory()->create(['city_id' => City::first()->id]);
        $listing  = $this->listing([], $stranger);
        $this->credit(50_00);

        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->call('buyBoost', $listing->id, BoostTier::Pin->value)
            ->assertNotFound();

        $this->assertSame(0, Boost::count());
        $this->assertSame(50_00, app(CreditService::class)->balance($this->seller));
    }

    public function test_a_bogus_tier_does_not_reach_the_service(): void
    {
        $listing = $this->listing();
        $this->credit(50_00);

        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->call('buyBoost', $listing->id, 'free-top-spot-please')
            ->assertHasErrors('boost');

        $this->assertSame(0, Boost::count());
    }

    /**
     * The disclosure a seller sees BEFORE paying, not after.
     *
     * The refusal that matters most is the one money cannot buy, so the panel
     * says it at the till rather than leaving it to be discovered by a seller
     * who paid to jump a moderation queue.
     */
    public function test_the_panel_says_what_the_money_does_not_buy(): void
    {
        $listing = $this->listing();

        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->call('openBoost', $listing->id)
            ->assertSee('не ускорява проверката');
    }

    /** N+1 guard — fifteen rows, each asking what is running on it. */
    public function test_the_list_does_not_query_once_per_row(): void
    {
        Listing::factory()->count(15)->create([
            'user_id'   => $this->seller->id,
            'city_id'   => City::first()->id,
            'category'  => 'gpu',
            'status'    => ListingStatus::Active,
            'bumped_at' => now(),
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        Livewire::actingAs($this->seller)->test(MyListings::class);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            30,
            $count,
            "a 15-row list ran {$count} queries — Boosted::eagerLoad() has probably been dropped from MyListings",
        );
    }

    // --- the credit page --------------------------------------------------

    public function test_the_credit_page_shows_the_balance_and_its_history(): void
    {
        $listing = $this->listing(['title' => 'Картата, за която платих']);
        $this->credit(50_00);

        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->call('buyBoost', $listing->id, BoostTier::Pin->value);

        Livewire::actingAs($this->seller)
            ->test(MyCredit::class)
            ->assertSee('41,00')                      // 50,00 − 9,00
            ->assertSee('Подарен кредит')
            ->assertSee('Плащане')
            // Every spend names what it bought. A ledger row a seller cannot
            // check against a listing is a number they have to take on faith.
            ->assertSee('Картата, за която платих');
    }

    /**
     * A refund is a ROW, not a quiet correction of an earlier one — the table
     * refuses UPDATE at the database level, and this is what that looks like
     * to the seller.
     */
    public function test_selling_mid_window_leaves_a_visible_refund_row(): void
    {
        $listing = $this->listing();
        $this->credit(50_00);

        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->call('buyBoost', $listing->id, BoostTier::Pin->value);

        $this->travel(3)->days();

        app(\App\Services\Billing\BoostService::class)->stopAll($listing->fresh(), 'sold');

        Livewire::actingAs($this->seller)
            ->test(MyCredit::class)
            ->assertSee('Върнато')
            ->assertSee('Плащане');
    }

    public function test_a_seller_only_ever_sees_their_own_ledger(): void
    {
        $stranger = User::factory()->create(['city_id' => City::first()->id]);
        app(CreditService::class)->grant($stranger, 99_00, 'НЕ Е ТВОЙ РЕД');

        $this->credit(10_00);

        Livewire::actingAs($this->seller)
            ->test(MyCredit::class)
            ->assertSee('10,00')
            ->assertDontSee('НЕ Е ТВОЙ РЕД');
    }
}
