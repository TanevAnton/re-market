<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\Thread;
use App\Models\User;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\ThreadService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Email verification as the account gate, while SMS is off.
 *
 * The shape being protected: acting needs a verified address, reading does not.
 * Browsing stays open to everyone, and a user who cannot yet act can still see
 * the state of their own account - being unable to do anything AND unable to
 * see why is how someone decides the site is broken rather than gated.
 *
 * Worth remembering what this is NOT. Email is a far weaker proof than a phone
 * number: throwaway addresses are free and unlimited, so a ban costs a spammer
 * nothing. The moderation queue is carrying that weight until 'phone.verified'
 * goes on - which the Telegram bot already makes free.
 */
class EmailGateTest extends TestCase
{
    use RefreshDatabase;

    private User $verified;
    private User $unverified;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->verified   = User::factory()->create(['email_verified_at' => now()]);
        $this->unverified = User::factory()->unverified()->create();
    }

    private function listing(?User $seller = null): Listing
    {
        return Listing::factory()->create([
            'user_id'        => ($seller ?? User::factory()->create())->id,
            'status'         => ListingStatus::Active,
            'offers_enabled' => true,
        ]);
    }

    // --- reading stays open ----------------------------------------------

    public function test_browsing_never_asks_for_anything(): void
    {
        $listing = $this->listing();

        $this->get(route('home'))->assertOk();
        $this->get(route('browse'))->assertOk();
        $this->get(route('listing', $listing))->assertOk();
    }

    /**
     * Unable to act AND unable to see why is how someone concludes the site is
     * broken rather than gated.
     */
    public function test_an_unverified_user_can_still_see_their_own_account(): void
    {
        $this->actingAs($this->unverified);

        $this->get(route('listings.mine'))->assertOk();
        $this->get(route('offers'))->assertOk();
        $this->get(route('deals'))->assertOk();
        $this->get(route('messages'))->assertOk();
    }

    // --- acting needs a verified address ---------------------------------

    public function test_an_unverified_user_cannot_reach_the_posting_form(): void
    {
        $this->actingAs($this->unverified)
            ->get(route('listing.create'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_a_verified_user_can(): void
    {
        $this->actingAs($this->verified)
            ->get(route('listing.create'))
            ->assertOk();
    }

    public function test_an_unverified_user_cannot_open_a_message_thread(): void
    {
        $this->actingAs($this->unverified)
            ->get(route('listing.message', $this->listing()))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_an_unverified_user_cannot_edit_a_listing(): void
    {
        $mine = $this->listing($this->unverified);

        $this->actingAs($this->unverified)
            ->get(route('listing.edit', $mine))
            ->assertRedirect(route('verification.notice'));
    }

    /**
     * Offers are gated at acceptsOffersFrom(), which is the single method both
     * the component and OfferService::place go through - a second copy of this
     * rule would eventually disagree with the first.
     */
    public function test_an_unverified_user_cannot_offer(): void
    {
        $listing = $this->listing();

        $this->assertFalse($listing->acceptsOffersFrom($this->unverified));
        $this->assertTrue($listing->acceptsOffersFrom($this->verified));
    }

    /**
     * In the service rather than the component, and before the rate limiter -
     * an account that was never going to send should not burn its own cooldown.
     */
    public function test_an_unverified_user_cannot_send_a_message(): void
    {
        $seller  = User::factory()->create(['email_verified_at' => now()]);
        $listing = $this->listing($seller);

        $thread = Thread::create([
            'listing_id' => $listing->id,
            'buyer_id'   => $this->unverified->id,
            'seller_id'  => $seller->id,
        ]);

        $this->expectException(MessagingException::class);

        app(ThreadService::class)->send($thread, $this->unverified, 'Здравей, още ли е налична?');
    }

    // --- the flow ---------------------------------------------------------

    public function test_the_notice_page_says_what_is_blocked(): void
    {
        $this->actingAs($this->unverified)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee($this->unverified->email)
            ->assertSee('публикуваш обява', false);
    }

    public function test_a_verified_user_is_not_kept_on_the_notice_page(): void
    {
        $this->actingAs($this->verified)
            ->get(route('verification.notice'))
            ->assertRedirect(route('browse'));
    }

    /**
     * Phone verification is still reachable and still the stronger proof - it
     * is just not what unlocks the site today.
     */
    public function test_phone_verification_is_still_available(): void
    {
        $this->actingAs($this->unverified)
            ->get(route('phone.verify'))
            ->assertOk();
    }
}
