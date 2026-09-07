<?php

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Livewire\Deals\MyDeals;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\User;
use App\Services\Deals\DealException;
use App\Services\Deals\DealService;
use App\Services\Offers\OfferService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * A deal is not a payment record - the platform never touches the money. It is
 * the measurement of whether the handshake was honoured, and the completion
 * rate it produces is the only real trust signal the site has. These tests are
 * about that measurement being honest.
 */
class DealFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $buyer;
    private Listing $listing;
    private DealService $deals;

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
            'offers_enabled'  => true,
            'price_cents'     => 100000,
            'min_offer_cents' => null,
        ]);

        $this->deals = app(DealService::class);
    }

    /**
     * A user's reputation counters, read from the database.
     *
     * UserFactory seeds these with plausible-looking numbers so demo profiles
     * do not all read zero, which means every assertion about them has to be a
     * DELTA. An absolute number passes here only by luck - and a wrong one can
     * pass by luck too, which is worse.
     *
     * @return array{completed:int, abandoned:int}
     */
    private function counters(User $user): array
    {
        $fresh = $user->fresh();

        return [
            'completed' => (int) $fresh->deals_completed,
            'abandoned' => (int) $fresh->deals_abandoned,
        ];
    }

    /** An accepted offer is the only way a deal comes into existence. */
    private function openDeal(): Deal
    {
        $offers = app(OfferService::class);
        $offer  = $offers->place($this->listing, $this->buyer, 90000);

        return $offers->accept($offer, $this->seller);
    }

    // --- completing ------------------------------------------------------

    public function test_one_confirmation_is_not_enough(): void
    {
        $deal   = $this->openDeal();
        $before = $this->counters($this->seller);

        $this->deals->confirm($deal, $this->buyer);

        // A seller who could close their own sale would be marking their own
        // exam, and every rating hanging off it would be worthless.
        $this->assertSame(DealStatus::Open, $deal->fresh()->status);
        $this->assertSame(ListingStatus::Reserved, $this->listing->fresh()->status);
        $this->assertSame($before['completed'], $this->counters($this->seller)['completed']);
    }

    public function test_both_confirmations_complete_the_deal_and_sell_the_listing(): void
    {
        $deal         = $this->openDeal();
        $buyerBefore  = $this->counters($this->buyer);
        $sellerBefore = $this->counters($this->seller);

        $this->deals->confirm($deal, $this->buyer);
        $this->deals->confirm($deal->fresh(), $this->seller);

        $deal = $deal->fresh();
        $this->assertSame(DealStatus::Completed, $deal->status);
        $this->assertNotNull($deal->completed_at);
        $this->assertTrue($deal->status->allowsRating());

        $listing = $this->listing->fresh();
        $this->assertSame(ListingStatus::Sold, $listing->status);
        $this->assertNotNull($listing->sold_at);
        $this->assertNull($listing->reserved_until);

        // Both sides earn the completed deal - buyers ghost too.
        $this->assertSame($buyerBefore['completed'] + 1, $this->counters($this->buyer)['completed']);
        $this->assertSame($sellerBefore['completed'] + 1, $this->counters($this->seller)['completed']);
    }

    public function test_confirming_twice_is_refused_rather_than_counted_twice(): void
    {
        $deal = $this->openDeal();

        $this->deals->confirm($deal, $this->buyer);

        $this->expectException(DealException::class);
        $this->expectExceptionMessage('Вече потвърди');

        $this->deals->confirm($deal->fresh(), $this->buyer);
    }

    public function test_a_stranger_cannot_confirm(): void
    {
        $deal = $this->openDeal();

        $this->expectException(DealException::class);
        $this->expectExceptionMessage('Нямаш достъп');

        $this->deals->confirm($deal, User::factory()->create());
    }

    // --- cancelling ------------------------------------------------------

    public function test_cancelling_relists_the_item_and_costs_no_reputation(): void
    {
        $deal   = $this->openDeal();
        $before = $this->counters($this->buyer);

        $this->deals->cancel($deal, $this->buyer, 'Намерих друга карта.');

        $deal = $deal->fresh();
        $this->assertSame(DealStatus::Cancelled, $deal->status);
        $this->assertSame($this->buyer->id, $deal->cancelled_by);
        $this->assertSame('Намерих друга карта.', $deal->cancel_reason);

        // If backing out honestly cost the same as ghosting, nobody would ever
        // do it - they would just stop replying, which is strictly worse.
        $this->assertSame($before['abandoned'], $this->counters($this->buyer)['abandoned']);

        // The item is still for sale.
        $this->assertSame(ListingStatus::Active, $this->listing->fresh()->status);
        $this->assertNull($this->listing->fresh()->reserved_until);
    }

    public function test_a_cancellation_needs_a_reason(): void
    {
        $deal = $this->openDeal();

        $this->expectException(DealException::class);

        $this->deals->cancel($deal, $this->seller, '   ');
    }

    public function test_a_closed_deal_cannot_be_reopened(): void
    {
        $deal = $this->openDeal();
        $this->deals->cancel($deal, $this->seller, 'Продадох я другаде.');

        $this->expectException(DealException::class);
        $this->expectExceptionMessage('приключена');

        $this->deals->confirm($deal->fresh(), $this->buyer);
    }

    // --- lapsing ---------------------------------------------------------

    public function test_the_side_that_went_quiet_is_the_one_marked_abandoned(): void
    {
        $deal         = $this->openDeal();
        $buyerBefore  = $this->counters($this->buyer);
        $sellerBefore = $this->counters($this->seller);

        // The seller did their part and said so; the buyer never showed up.
        $this->deals->confirm($deal, $this->seller);

        $this->travel(config('remarket.deals.reservation_hours') + 1)->hours();

        $this->assertSame(1, $this->deals->lapseStale());

        $this->assertSame(DealStatus::Abandoned, $deal->fresh()->status);
        $this->assertSame($buyerBefore['abandoned'] + 1, $this->counters($this->buyer)['abandoned']);
        $this->assertSame($sellerBefore['abandoned'], $this->counters($this->seller)['abandoned']);

        // The seller keeps their item and their listing.
        $this->assertSame(ListingStatus::Active, $this->listing->fresh()->status);
    }

    public function test_when_neither_side_confirms_both_are_marked(): void
    {
        $this->openDeal();
        $buyerBefore  = $this->counters($this->buyer);
        $sellerBefore = $this->counters($this->seller);

        $this->travel(config('remarket.deals.reservation_hours') + 1)->hours();
        $this->deals->lapseStale();

        $this->assertSame($buyerBefore['abandoned'] + 1, $this->counters($this->buyer)['abandoned']);
        $this->assertSame($sellerBefore['abandoned'] + 1, $this->counters($this->seller)['abandoned']);
    }

    public function test_the_sweep_leaves_live_deals_alone(): void
    {
        $deal = $this->openDeal();

        $this->assertSame(0, $this->deals->lapseStale());
        $this->assertSame(DealStatus::Open, $deal->fresh()->status);
    }

    public function test_a_lapsed_deal_cannot_be_confirmed_even_before_the_sweep_runs(): void
    {
        $deal = $this->openDeal();

        $this->travel(config('remarket.deals.reservation_hours') + 1)->hours();

        $this->expectException(DealException::class);
        $this->expectExceptionMessage('изтече');

        $this->deals->confirm($deal, $this->buyer);
    }

    public function test_the_completion_rate_reflects_what_happened(): void
    {
        // Three agreed deals, two honoured.
        $this->seller->forceFill(['deals_completed' => 2, 'deals_abandoned' => 1])->save();

        $this->assertSame(66.7, $this->seller->fresh()->completionRate());
    }

    // --- the screen ------------------------------------------------------

    public function test_a_party_can_confirm_from_the_deals_screen(): void
    {
        $deal = $this->openDeal();

        $this->actingAs($this->buyer);

        Livewire::test(MyDeals::class)
            ->call('confirm', $deal->id)
            ->assertHasNoErrors();

        $this->assertNotNull($deal->fresh()->buyer_confirmed_at);
    }

    public function test_a_stranger_cannot_act_on_someone_elses_deal(): void
    {
        $deal = $this->openDeal();

        $this->actingAs(User::factory()->create());

        try {
            Livewire::test(MyDeals::class)->call('confirm', $deal->id);
        } catch (NotFoundHttpException) {
            // Either shape is fine; the state below is what matters.
        }

        $this->assertNull($deal->fresh()->buyer_confirmed_at);
        $this->assertNull($deal->fresh()->seller_confirmed_at);
    }

    public function test_the_deals_screen_requires_a_login(): void
    {
        $this->get(route('deals'))->assertRedirect(route('login'));
    }
}
