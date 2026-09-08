<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\ModerationTrigger;
use App\Enums\RejectionReason;
use App\Models\Listing;
use App\Models\Offer;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\DealConfirmed;
use App\Notifications\ModerationDecision;
use App\Notifications\NewMessage;
use App\Notifications\OfferAccepted;
use App\Notifications\OfferCountered;
use App\Notifications\OfferDeclined;
use App\Notifications\OfferLost;
use App\Notifications\OfferReceived;
use App\Services\Deals\DealService;
use App\Services\Messaging\ThreadService;
use App\Services\Moderation\ModerationService;
use App\Services\Offers\OfferService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Does anything actually tell anyone anything?
 *
 * Until this file existed the answer was no, and nothing failed to say so -
 * which is the whole problem with notifications. They are the one feature
 * whose absence looks exactly like success from the inside.
 *
 * Two things are tested and they are different questions. That a notification
 * is SENT is a question about the services. That it would be DELIVERED is a
 * question about via() and the user's own preferences, and it has its own
 * section at the bottom, because a notification sent to a user with no route
 * to reach them is the same silence in a costume.
 */
class NotificationTest extends TestCase
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
            'price_cents'     => 100000,
            'min_offer_cents' => 80000,
        ]);

        $this->offers = app(OfferService::class);
    }

    private function offer(?User $buyer = null, int $amount = 90000): Offer
    {
        return $this->offers->place($this->listing, $buyer ?? $this->buyer, $amount);
    }

    // --- offers ----------------------------------------------------------

    public function test_the_seller_hears_about_a_new_offer(): void
    {
        Notification::fake();

        $this->offer();

        Notification::assertSentTo($this->seller, OfferReceived::class);
        Notification::assertNotSentTo($this->buyer, OfferReceived::class);
    }

    public function test_the_buyer_hears_that_the_offer_was_accepted(): void
    {
        $offer = $this->offer();

        Notification::fake();
        $this->offers->accept($offer, $this->seller);

        Notification::assertSentTo($this->buyer, OfferAccepted::class);
    }

    public function test_the_buyer_hears_that_the_offer_was_declined(): void
    {
        $offer = $this->offer();

        Notification::fake();
        $this->offers->decline($offer, $this->seller);

        Notification::assertSentTo($this->buyer, OfferDeclined::class);
    }

    public function test_the_buyer_hears_about_a_counter_offer(): void
    {
        $offer = $this->offer();

        Notification::fake();
        $this->offers->counter($offer, $this->seller, 95000);

        Notification::assertSentTo($this->buyer, OfferCountered::class);
    }

    public function test_the_seller_hears_that_their_counter_was_accepted(): void
    {
        $counter = $this->offers->counter($this->offer(), $this->seller, 95000);

        Notification::fake();
        $this->offers->acceptCounter($counter, $this->buyer);

        Notification::assertSentTo($this->seller, OfferAccepted::class);
    }

    /**
     * The case a bulk `update()` hides.
     *
     * Accepting one offer declines every other live offer in the same
     * statement, which touches rows without ever loading a model - so there is
     * nothing to call notify() on unless someone deliberately reads them
     * first. The losing bidders were the only people in the flow left to work
     * out what happened by refreshing a listing they could no longer buy.
     */
    public function test_the_losing_bidders_are_told_the_item_went_to_someone_else(): void
    {
        $rival  = User::factory()->create();
        $second = User::factory()->create();

        $winning = $this->offer();
        $this->offer($rival, 85000);
        $this->offer($second, 82000);

        Notification::fake();
        $this->offers->accept($winning, $this->seller);

        Notification::assertSentTo($rival, OfferLost::class);
        Notification::assertSentTo($second, OfferLost::class);

        // Not the decline text: it advises re-offering after a cooldown, and
        // there is nothing left to re-offer on.
        Notification::assertNotSentTo($rival, OfferDeclined::class);

        // The winner gets the good news, not the loss.
        Notification::assertSentTo($this->buyer, OfferAccepted::class);
        Notification::assertNotSentTo($this->buyer, OfferLost::class);
    }

    public function test_losing_bidders_are_told_when_a_counter_is_accepted_too(): void
    {
        $rival = User::factory()->create();

        $counter = $this->offers->counter($this->offer(), $this->seller, 95000);
        $this->offer($rival, 85000);

        Notification::fake();
        $this->offers->acceptCounter($counter, $this->buyer);

        Notification::assertSentTo($rival, OfferLost::class);
    }

    // --- messages --------------------------------------------------------

    public function test_the_first_message_notifies_but_the_second_does_not(): void
    {
        $threads = app(ThreadService::class);
        $thread  = $threads->open($this->listing, $this->buyer);

        Notification::fake();

        $threads->send($thread, $this->buyer, 'Здравей, още ли е налична?');

        // Past the 3-second anti-flood cooldown rather than around it: the
        // behaviour under test is what happens on a genuine second message,
        // and clearing the limiter would test a path no user can take.
        $this->travel(5)->seconds();

        $threads->send($thread, $this->buyer, 'И втори въпрос.');

        // One per unread streak, not one per message. Ten messages in a row
        // from an impatient buyer is one notification, not ten.
        Notification::assertSentToTimes($this->seller, NewMessage::class, 1);
    }

    public function test_a_reply_after_reading_notifies_again(): void
    {
        $threads = app(ThreadService::class);
        $thread  = $threads->open($this->listing, $this->buyer);

        $threads->send($thread, $this->buyer, 'Здравей, още ли е налична?');
        $threads->markRead($thread, $this->seller);

        $this->travel(5)->seconds();

        Notification::fake();
        $threads->send($thread, $this->buyer, 'Още един въпрос.');

        Notification::assertSentTo($this->seller, NewMessage::class);
    }

    // --- deals -----------------------------------------------------------

    public function test_the_other_side_hears_about_a_confirmation(): void
    {
        $deal  = $this->offers->accept($this->offer(), $this->seller);
        $deals = app(DealService::class);

        Notification::fake();
        $deals->confirm($deal, $this->buyer);

        Notification::assertSentTo($this->seller, DealConfirmed::class);
        Notification::assertNotSentTo($this->buyer, DealConfirmed::class);
    }

    public function test_the_second_confirmation_notifies_nobody(): void
    {
        $deal  = $this->offers->accept($this->offer(), $this->seller);
        $deals = app(DealService::class);

        $deals->confirm($deal, $this->buyer);

        Notification::fake();
        $deals->confirm($deal->fresh(), $this->seller);

        // The deal is done. "The other side confirmed" would arrive after
        // there is anything left to do about it.
        Notification::assertNothingSent();
    }

    // --- moderation ------------------------------------------------------

    /**
     * DSA Art. 17 says the affected user must RECEIVE the statement of
     * reasons. Storing it on the row and hoping they reopen the listing is not
     * receipt, and this test is the one that stops that regressing - it is the
     * only notification in this file with a legal deadline attached.
     */
    public function test_a_rejected_seller_receives_the_statement_of_reasons(): void
    {
        $moderation = app(ModerationService::class);
        $moderator  = User::factory()->create(['is_admin' => true]);

        $item = $moderation->enqueue($this->listing, ModerationTrigger::NewAccount);

        Notification::fake();
        $moderation->reject(
            $item,
            $moderator,
            RejectionReason::ContactInfo,
            'описанието съдържа телефонен номер',
        );

        Notification::assertSentTo(
            $this->seller,
            ModerationDecision::class,
            function (ModerationDecision $notification) {
                $lines = implode("\n", $notification->lines($this->seller));

                // Sent whole and unedited: this is the record of what they were
                // told, and a summary here would differ from the copy on file.
                return str_contains($lines, 'описанието съдържа телефонен номер');
            },
        );
    }

    public function test_an_approved_seller_is_told_the_listing_is_live(): void
    {
        $moderation = app(ModerationService::class);
        $moderator  = User::factory()->create(['is_admin' => true]);

        $item = $moderation->enqueue($this->listing, ModerationTrigger::NewAccount);

        Notification::fake();
        $moderation->approve($item, $moderator);

        Notification::assertSentTo($this->seller, ModerationDecision::class);
    }

    /**
     * The trap this whole file grew out of: `return DB::transaction(...)`
     * followed by the notify call, which then never runs. Nothing fails, the
     * decision is recorded correctly, and the seller is simply never told.
     *
     * Asserting the return value as well as the notification means the fix
     * cannot be "move the notify inside the transaction", which would queue a
     * job that can outrun its own commit.
     */
    public function test_moderation_returns_the_decided_item_and_still_notifies(): void
    {
        $moderation = app(ModerationService::class);
        $moderator  = User::factory()->create(['is_admin' => true]);

        $item = $moderation->enqueue($this->listing, ModerationTrigger::NewAccount);

        Notification::fake();
        $decided = $moderation->approve($item, $moderator);

        $this->assertSame('approved', $decided->status);
        $this->assertSame($moderator->id, $decided->decided_by);
        Notification::assertSentTo($this->seller, ModerationDecision::class);
    }

    // --- delivery: who can actually be reached ---------------------------

    public function test_email_goes_only_to_a_verified_address(): void
    {
        $unverified = User::factory()->unverified()->create();

        $this->assertNotContains('mail', $this->channelsFor($unverified));

        // Bouncing mail at an address that has never been shown to work is how
        // a young sending domain loses the reputation the next thousand
        // messages depend on.
        $this->assertContains('mail', $this->channelsFor($this->buyer));
    }

    public function test_telegram_is_used_when_the_bot_knows_the_chat(): void
    {
        $this->assertNotContains(TelegramChannel::class, $this->channelsFor($this->buyer));

        $this->buyer->forceFill(['telegram_chat_id' => 123456789])->save();

        $this->assertContains(TelegramChannel::class, $this->channelsFor($this->buyer->fresh()));
    }

    public function test_a_user_who_opted_out_of_everything_gets_nothing(): void
    {
        $this->buyer->forceFill([
            'telegram_chat_id' => 123456789,
            'notify_email'     => false,
            'notify_telegram'  => false,
        ])->save();

        // No fallback channel is forced on them. Transactional mail someone has
        // switched off is mail they did not consent to; the consequence - a
        // missed offer - is theirs to accept.
        $this->assertSame([], $this->channelsFor($this->buyer->fresh()));
    }

    public function test_both_channels_are_used_when_both_are_available(): void
    {
        $this->buyer->forceFill(['telegram_chat_id' => 123456789])->save();

        $channels = $this->channelsFor($this->buyer->fresh());

        $this->assertContains('mail', $channels);
        $this->assertContains(TelegramChannel::class, $channels);
    }

    /**
     * Notifications default to on for a new account, because the 48-hour offer
     * window assumes somebody knows the clock is running.
     */
    public function test_a_new_account_is_reachable_by_default(): void
    {
        $fresh = User::factory()->create();

        $this->assertTrue($fresh->notify_email);
        $this->assertTrue($fresh->notify_telegram);
    }

    /**
     * Which channels would actually carry a notification to this user.
     *
     * via() is answered entirely from the notifiable, so the offer here is
     * unsaved scaffolding - the question is about the user, not the offer.
     *
     * @return list<string>
     */
    private function channelsFor(User $user): array
    {
        $offer = new Offer([
            'listing_id'   => $this->listing->id,
            'buyer_id'     => $user->id,
            'seller_id'    => $this->seller->id,
            'amount_cents' => 90000,
        ]);

        return (new OfferReceived($offer))->via($user);
    }
}
