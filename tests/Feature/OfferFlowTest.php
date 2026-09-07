<?php

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Livewire\Offers\MakeOffer;
use App\Livewire\Offers\OfferInbox;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\Offer;
use App\Models\User;
use App\Services\Offers\OfferException;
use App\Services\Offers\OfferService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The offer system is the product, so these tests are about rules rather than
 * plumbing: what the floor hides, what the cooldown blocks, what accepting does
 * to everyone who did not win.
 */
class OfferFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $buyer;
    private Listing $listing;
    private OfferService $offers;

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
            'price_cents'     => 100000,   // 1000 EUR
            'min_offer_cents' => 80000,    //  800 EUR floor
        ]);

        $this->offers = app(OfferService::class);
    }

    // --- placing ---------------------------------------------------------

    public function test_an_offer_above_the_floor_reaches_the_seller(): void
    {
        $before = (int) $this->listing->fresh()->offer_count;

        $offer = $this->offers->place($this->listing, $this->buyer, 85000, 'Мога петък.');

        $this->assertSame(OfferStatus::Pending, $offer->status);
        $this->assertSame(85000, $offer->amount_cents);
        $this->assertSame($before + 1, (int) $this->listing->fresh()->offer_count);
    }

    /**
     * The mechanism the whole product rests on. A lowball is recorded so the
     * limits and cooldown can see it, but it never reaches the seller and it
     * never reveals where the floor actually is.
     */
    public function test_a_lowball_is_auto_declined_and_never_counted(): void
    {
        $before = (int) $this->listing->fresh()->offer_count;

        $offer = $this->offers->place($this->listing, $this->buyer, 79999);

        $this->assertSame(OfferStatus::AutoDeclined, $offer->status);
        $this->assertNotNull($offer->responded_at);

        // The seller's counter is untouched...
        $this->assertSame($before, (int) $this->listing->fresh()->offer_count);

        // ...and the seller's inbox does not contain it.
        $this->actingAs($this->seller);
        Livewire::test(OfferInbox::class)
            ->assertSet('tab', 'received')
            ->assertDontSee('799');
    }

    public function test_the_floor_is_inclusive_at_the_boundary(): void
    {
        $offer = $this->offers->place($this->listing, $this->buyer, 80000);

        $this->assertSame(OfferStatus::Pending, $offer->status);
    }

    public function test_an_offer_above_the_asking_price_is_refused(): void
    {
        $this->expectException(OfferException::class);

        $this->offers->place($this->listing, $this->buyer, 100001);
    }

    public function test_a_second_live_offer_is_refused_with_a_readable_reason(): void
    {
        $this->offers->place($this->listing, $this->buyer, 85000);

        $this->expectException(OfferException::class);
        $this->expectExceptionMessage('Вече имаш активна оферта');

        $this->offers->place($this->listing, $this->buyer, 90000);
    }

    public function test_a_declined_buyer_is_cooled_down_before_trying_again(): void
    {
        $first = $this->offers->place($this->listing, $this->buyer, 85000);
        $this->offers->decline($first, $this->seller);

        try {
            $this->offers->place($this->listing, $this->buyer, 90000);
            $this->fail('The cooldown did not apply.');
        } catch (OfferException $e) {
            $this->assertStringContainsString('след', $e->getMessage());
        }

        // ...and is free again once it lapses.
        $this->travel(config('remarket.offers.decline_cooldown') + 1)->hours();

        $second = $this->offers->place($this->listing, $this->buyer, 90000);
        $this->assertSame(OfferStatus::Pending, $second->status);
    }

    public function test_withdrawing_does_not_start_a_cooldown(): void
    {
        // Changing your own mind is not spam; only a seller's "no" should
        // buy silence.
        $first = $this->offers->place($this->listing, $this->buyer, 85000);
        $this->offers->withdraw($first, $this->buyer);

        $second = $this->offers->place($this->listing, $this->buyer, 88000);

        $this->assertSame(OfferStatus::Pending, $second->status);
    }

    public function test_the_per_listing_cap_counts_declines_but_not_withdrawals(): void
    {
        $max = (int) config('remarket.offers.max_per_listing');

        for ($i = 0; $i < $max; $i++) {
            $offer = $this->offers->place($this->listing, $this->buyer, 85000 + $i);
            $this->offers->withdraw($offer, $this->buyer);
        }

        // Still allowed: none of those were refusals.
        $extra = $this->offers->place($this->listing, $this->buyer, 90000);
        $this->offers->withdraw($extra, $this->buyer);

        // Now burn the budget for real.
        for ($i = 0; $i < $max; $i++) {
            $offer = $this->offers->place($this->listing, $this->buyer, 91000 + $i);
            $offer->forceFill([
                'status'       => OfferStatus::Declined,
                'responded_at' => now()->subDays(30),   // past any cooldown
            ])->save();
        }

        $this->expectException(OfferException::class);
        $this->expectExceptionMessage('лимита');

        $this->offers->place($this->listing, $this->buyer, 95000);
    }

    public function test_a_seller_cannot_offer_on_their_own_listing(): void
    {
        $this->expectException(OfferException::class);

        $this->offers->place($this->listing, $this->seller, 90000);
    }

    // --- accepting -------------------------------------------------------

    public function test_accepting_creates_a_deal_reserves_the_listing_and_clears_the_queue(): void
    {
        $rival = User::factory()->create();

        $winner = $this->offers->place($this->listing, $this->buyer, 90000);
        $loser  = $this->offers->place($this->listing, $rival, 85000);

        $deal = $this->offers->accept($winner, $this->seller);

        $this->assertInstanceOf(Deal::class, $deal);
        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertSame(90000, $deal->agreed_price_cents);

        $this->assertSame(OfferStatus::Accepted, $winner->fresh()->status);

        // Selling one item to two people is how you lose the second buyer
        // permanently, so the rest of the queue closes in the same transaction.
        $this->assertSame(OfferStatus::Declined, $loser->fresh()->status);
        $this->assertNotNull($loser->fresh()->responded_at);

        $listing = $this->listing->fresh();
        $this->assertSame(ListingStatus::Reserved, $listing->status);
        $this->assertNotNull($listing->reserved_until);
    }

    public function test_a_reserved_listing_stops_taking_offers(): void
    {
        $offer = $this->offers->place($this->listing, $this->buyer, 90000);
        $this->offers->accept($offer, $this->seller);

        $this->expectException(OfferException::class);

        $this->offers->place($this->listing->fresh(), User::factory()->create(), 95000);
    }

    public function test_only_the_seller_may_accept(): void
    {
        $offer = $this->offers->place($this->listing, $this->buyer, 90000);

        $this->expectException(OfferException::class);
        $this->expectExceptionMessage('Нямаш достъп');

        $this->offers->accept($offer, $this->buyer);
    }

    public function test_an_expired_offer_cannot_be_accepted_even_before_the_sweep_runs(): void
    {
        $offer = $this->offers->place($this->listing, $this->buyer, 90000);

        $this->travel(config('remarket.offers.ttl_hours') + 1)->hours();

        try {
            $this->offers->accept($offer, $this->seller);
            $this->fail('An expired offer was accepted.');
        } catch (OfferException $e) {
            $this->assertStringContainsString('изтекла', $e->getMessage());
        }

        // The attempt itself settles the row, rather than leaving it pending
        // until the scheduler happens to run.
        $this->assertSame(OfferStatus::Expired, $offer->fresh()->status);
        $this->assertSame(ListingStatus::Active, $this->listing->fresh()->status);
    }

    // --- countering ------------------------------------------------------

    public function test_a_counter_leaves_one_live_offer_and_the_buyer_can_accept_it(): void
    {
        $offer   = $this->offers->place($this->listing, $this->buyer, 85000);
        $counter = $this->offers->counter($offer, $this->seller, 92000, 'Мога 920.');

        $this->assertSame(OfferStatus::Countered, $offer->fresh()->status);
        $this->assertTrue($counter->is_counter);
        $this->assertSame($offer->id, $counter->parent_offer_id);

        // The partial unique index allows this only because the parent left
        // 'pending' in the same transaction.
        $this->assertSame(1, Offer::where('listing_id', $this->listing->id)
            ->where('status', OfferStatus::Pending)->count());

        $deal = $this->offers->acceptCounter($counter, $this->buyer);

        $this->assertSame(92000, $deal->agreed_price_cents);
        $this->assertSame(ListingStatus::Reserved, $this->listing->fresh()->status);
    }

    public function test_only_one_counter_per_buyer_and_listing(): void
    {
        $offer   = $this->offers->place($this->listing, $this->buyer, 85000);
        $counter = $this->offers->counter($offer, $this->seller, 92000);

        // The buyer walks away, then opens a fresh negotiation.
        $this->offers->declineCounter($counter, $this->buyer);
        $again = $this->offers->place($this->listing, $this->buyer, 87000);

        // Counting per chain would reset the budget here. Counting per
        // (listing, buyer) is what makes "one counter" mean anything.
        $this->expectException(OfferException::class);
        $this->expectExceptionMessage('пазарлък');

        $this->offers->counter($again, $this->seller, 93000);
    }

    public function test_declining_a_counter_does_not_cool_the_buyer_down(): void
    {
        $offer   = $this->offers->place($this->listing, $this->buyer, 85000);
        $counter = $this->offers->counter($offer, $this->seller, 95000);

        $this->offers->declineCounter($counter, $this->buyer);

        $this->assertSame(OfferStatus::Withdrawn, $counter->fresh()->status);

        // The buyer said no, not the seller - so they may try again at once.
        $retry = $this->offers->place($this->listing, $this->buyer, 88000);
        $this->assertSame(OfferStatus::Pending, $retry->status);
    }

    // --- expiry ----------------------------------------------------------

    public function test_the_sweep_expires_only_offers_past_their_ttl(): void
    {
        $fresh = $this->offers->place($this->listing, $this->buyer, 85000);

        $old = Offer::create([
            'listing_id'   => $this->listing->id,
            'buyer_id'     => User::factory()->create()->id,
            'seller_id'    => $this->seller->id,
            'amount_cents' => 86000,
            'expires_at'   => now()->subHour(),
        ]);

        $this->assertSame(1, $this->offers->expireStale());
        $this->assertSame(OfferStatus::Expired, $old->fresh()->status);
        $this->assertSame(OfferStatus::Pending, $fresh->fresh()->status);
    }

    // --- the screens -----------------------------------------------------

    public function test_the_buyer_can_place_an_offer_through_the_listing_page(): void
    {
        $this->actingAs($this->buyer);

        Livewire::test(MakeOffer::class, ['listing' => $this->listing])
            ->set('open', true)
            ->set('amount', '899,50')
            ->call('submit')
            ->assertHasNoErrors();

        $offer = Offer::firstOrFail();

        // Typed in euros with a Bulgarian decimal comma, stored in cents.
        $this->assertSame(89950, $offer->amount_cents);
        $this->assertSame(OfferStatus::Pending, $offer->status);
    }

    public function test_a_lowball_through_the_form_says_too_low_and_not_how_low(): void
    {
        $this->actingAs($this->buyer);

        Livewire::test(MakeOffer::class, ['listing' => $this->listing])
            ->set('open', true)
            ->set('amount', '500')
            ->call('submit')
            ->assertHasNoErrors();

        $offer = Offer::firstOrFail();
        $this->assertSame(OfferStatus::AutoDeclined, $offer->status);

        // Assert the copy at its source rather than through the flash session:
        // what matters is the rule, and reading it back out of a Livewire
        // round trip tests Livewire's session plumbing instead.
        $message = MakeOffer::resultMessage($offer);

        $this->assertStringContainsString('под минимума', $message);
        // Naming the floor would turn every listing into a two-guess game.
        $this->assertStringNotContainsString('800', $message);
    }

    /**
     * Regression: the counter reached the database and then vanished from the
     * buyer's point of view. Nothing counted it as theirs to answer - the nav
     * badge only looked at seller_id, the inbox defaulted to a tab the counter
     * is not in, and the listing page saw a live offer with the buyer's own
     * buyer_id and rendered it as "your offer" with a withdraw button.
     */
    public function test_a_counter_is_visible_to_the_buyer_as_theirs_to_answer(): void
    {
        $offer   = $this->offers->place($this->listing, $this->buyer, 85000);
        $counter = $this->offers->counter($offer, $this->seller, 92000);

        // The badge in the header counts what is waiting on YOU. Which side
        // that is depends on is_counter, not on buyer/seller.
        $this->assertSame(1, Offer::awaitingResponseFrom($this->buyer->id)->count());
        $this->assertSame(0, Offer::awaitingResponseFrom($this->seller->id)->count());

        $this->actingAs($this->buyer);

        // The inbox must show a number on the tab the counter actually lives in.
        $inbox = Livewire::test(OfferInbox::class);
        $this->assertSame(1, $inbox->instance()->counterCount());

        // Assert on the control, not on a uuid: a listing's uuid is in every
        // card's href, but an offer's is never rendered at all. What matters
        // here is that the buyer is shown an ACCEPT button for this specific
        // counter - the thing they previously had no way to do.
        $inbox->set('tab', 'sent')
            ->assertSee("acceptCounter({$counter->id})", false)
            ->assertSee($counter->formattedAmount());

        // And on the listing page it must be answerable, not withdrawable.
        Livewire::test(MakeOffer::class, ['listing' => $this->listing])
            ->assertSee('Насрещна оферта от продавача')
            ->assertDontSee('Оттегли офертата');
    }

    public function test_the_buyer_can_accept_a_counter_from_the_listing_page(): void
    {
        $offer = $this->offers->place($this->listing, $this->buyer, 85000);
        $this->offers->counter($offer, $this->seller, 92000);

        $this->actingAs($this->buyer);

        Livewire::test(MakeOffer::class, ['listing' => $this->listing])
            ->call('acceptCounter')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('deals', 1);
        $this->assertSame(ListingStatus::Reserved, $this->listing->fresh()->status);
    }

    public function test_the_seller_accepts_from_the_inbox(): void
    {
        $offer = $this->offers->place($this->listing, $this->buyer, 90000);

        $this->actingAs($this->seller);

        Livewire::test(OfferInbox::class)
            ->call('accept', $offer->id)
            ->assertHasNoErrors();

        $this->assertSame(OfferStatus::Accepted, $offer->fresh()->status);
        $this->assertDatabaseCount('deals', 1);
    }

    public function test_a_stranger_cannot_act_on_someone_elses_offer(): void
    {
        $offer = $this->offers->place($this->listing, $this->buyer, 90000);

        // The component scopes its lookup to offers the viewer is party to, so
        // a forged id never even reaches the service.
        $this->actingAs(User::factory()->create());

        try {
            Livewire::test(OfferInbox::class)->call('accept', $offer->id);
        } catch (NotFoundHttpException) {
            // Either shape is fine. What matters is the state below: asserting
            // on the 404 alone would pass just as happily if the abort ran
            // AFTER the offer had already been accepted.
        }

        $this->assertSame(OfferStatus::Pending, $offer->fresh()->status);
        $this->assertDatabaseCount('deals', 0);
    }

    public function test_the_inbox_requires_a_login(): void
    {
        $this->get(route('offers'))->assertRedirect(route('login'));
    }
}
