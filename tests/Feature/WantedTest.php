<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\WantedStatus;
use App\Livewire\Wanted\BrowseWanted;
use App\Livewire\Wanted\ManageWanted;
use App\Livewire\Wanted\ShowWanted;
use App\Models\City;
use App\Models\Listing;
use App\Models\ModerationItem;
use App\Models\Part;
use App\Models\User;
use App\Models\WantedAd;
use App\Models\WantedResponse;
use App\Notifications\WantedAnswered;
use App\Notifications\WantedMatchFound;
use App\Services\Moderation\ModerationService;
use App\Services\Wanted\WantedService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * „Търся" — the other half of the marketplace.
 *
 * WHAT IS ACTUALLY BEING PROTECTED, and it is not the CRUD. This feature earns
 * its place by turning a buyer who found nothing into a signal a seller acts
 * on, so the tests that matter are the ones about WHO GETS TOLD:
 *
 *   - both directions of the match, which have to agree, because a buyer told
 *     about a listing that its seller is never told about looks to everybody
 *     like nothing happened;
 *   - that a wanted ad is announced exactly once, because the second
 *     notification is what turns this into a mailing list people mute;
 *   - that an answer has to be a real listing that really matches, because
 *     „Търся" is the easiest surface on a marketplace to turn into
 *     „купувам всичко, пишете на Вайбър".
 */
class WantedTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private User $seller;
    private Part $part;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        /*
         * THE HOLD IS TURNED ON HERE, EXPLICITLY, and it has to be.
         *
         * phpunit.xml pins this to 0 so the rest of the suite is deterministic,
         * so a file that tests „a new account's first request is held" has to
         * state its own precondition. It did not, and the three tests that
         * depend on it failed while looking like the feature was broken —
         * the same trap the Turnstile keys set once already.
         *
         * Before trustedUser() below, because that reads this value to decide
         * how many listings make an account trusted.
         */
        config(['remarket.antispam.moderated_listings_for_new_accounts' => 2]);

        $this->part = Part::where('category', 'gpu')->firstOrFail();

        // Both start trusted: the new-account hold has its own test below, and
        // everywhere else it would only be noise.
        $this->buyer  = $this->trustedUser();
        $this->seller = $this->trustedUser();
    }

    /**
     * A user past the new-account moderation threshold.
     *
     * `holdsNewSeller()` counts listings, so the cheapest way to be trusted is
     * to have some — which is also what it means in production.
     */
    private function trustedUser(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        Listing::factory()->count(
            (int) config('remarket.antispam.moderated_listings_for_new_accounts', 2)
        )->create([
            'user_id' => $user->id,
            'city_id' => City::first()->id,
            'status'  => ListingStatus::Sold,   // not Active: never a match
        ]);

        return $user;
    }

    private function listing(array $attributes = [], ?User $owner = null): Listing
    {
        return Listing::factory()->create([
            'user_id'   => ($owner ?? $this->seller)->id,
            'city_id'   => City::first()->id,
            'category'  => 'gpu',
            'part_id'   => $this->part->id,
            'price_cents' => 25000,
            'status'    => ListingStatus::Active,
            'bumped_at' => now(),
            ...$attributes,
        ]);
    }

    private function wanted(array $data = [], ?User $buyer = null): WantedAd
    {
        return app(WantedService::class)->create($buyer ?? $this->buyer, [
            'category'         => 'gpu',
            'part_id'          => $this->part->id,
            'title'            => 'Търся видеокарта',
            'detail'           => null,
            'budget_max_cents' => 30000,
            'city_id'          => null,
            ...$data,
        ]);
    }

    // --- the match, both ways ---------------------------------------------

    /**
     * THE ONE THE FEATURE EXISTS FOR.
     *
     * A buyer posts a request and the seller who already has the card is told.
     * Without this the page is a noticeboard nobody visits.
     */
    public function test_posting_a_request_tells_the_sellers_who_already_match(): void
    {
        Notification::fake();

        $this->listing();

        $this->wanted();

        Notification::assertSentTo($this->seller, WantedMatchFound::class);
    }

    /**
     * And the loop closes: the buyer who found nothing in March hears about it
     * in May. Goes through the real publishing path, not the service directly.
     */
    public function test_a_new_listing_tells_the_buyers_who_were_waiting(): void
    {
        $this->wanted();

        Notification::fake();

        $listing = $this->listing();

        app(\App\Services\Moderation\ListingScreener::class)->screen($listing);

        // Asserted first so that a failure here says „screening held it"
        // rather than „matching is broken" — the announce is deliberately
        // after the flag check, and only fires for a listing that is live.
        $this->assertSame(ListingStatus::Active, $listing->fresh()->status);

        Notification::assertSentTo($this->buyer, WantedMatchFound::class);
    }

    /** And a listing that screening pulled down announces nothing. */
    public function test_a_listing_held_by_screening_tells_nobody(): void
    {
        $this->wanted();

        Notification::fake();

        $listing = $this->listing();
        $listing->forceFill(['status' => ListingStatus::PendingReview])->save();

        app(\App\Services\Moderation\ListingScreener::class)->screen($listing);

        Notification::assertNothingSentTo($this->buyer);
    }

    /** One seller with three matching cards gets ONE message, not three. */
    public function test_a_seller_is_told_once_however_many_listings_match(): void
    {
        Notification::fake();

        $this->listing();
        $this->listing();
        $this->listing();

        $this->wanted();

        Notification::assertSentToTimes($this->seller, WantedMatchFound::class, 1);
    }

    /**
     * THE ONE THAT KEEPS THIS FROM BECOMING A MAILING LIST.
     *
     * A buyer fixing a typo in their request must not re-notify every seller
     * who already heard about it. Two of those and people mute the channel —
     * and then they miss the offer on their own listing.
     */
    public function test_a_request_is_announced_once_and_only_once(): void
    {
        Notification::fake();

        $this->listing();
        $ad = $this->wanted();

        Notification::assertSentToTimes($this->seller, WantedMatchFound::class, 1);

        $this->assertSame(0, app(WantedService::class)->announce($ad->fresh()));

        Notification::assertSentToTimes($this->seller, WantedMatchFound::class, 1);
    }

    public function test_nobody_is_told_about_their_own_request(): void
    {
        Notification::fake();

        $this->listing(owner: $this->buyer);

        $this->wanted();

        Notification::assertNothingSentTo($this->buyer);
    }

    /**
     * The rules are narrow on purpose. A notification nobody wanted is worse
     * than none: it is what makes people turn email off.
     */
    public function test_a_request_does_not_reach_sellers_it_does_not_match(): void
    {
        Notification::fake();

        $other = Part::where('category', 'cpu')->firstOrFail();

        $this->listing(['category' => 'cpu', 'part_id' => $other->id]);   // wrong category
        $this->listing(['price_cents' => 90000]);                          // over budget
        $this->listing(['status' => ListingStatus::PendingReview]);        // not public

        $this->wanted();

        Notification::assertNothingSentTo($this->seller);
    }

    public function test_a_city_on_the_request_narrows_it(): void
    {
        Notification::fake();

        $elsewhere = City::where('id', '!=', City::first()->id)->firstOrFail();

        $this->listing(['city_id' => $elsewhere->id]);

        $this->wanted(['city_id' => City::first()->id]);

        Notification::assertNothingSentTo($this->seller);
    }

    /** No model named means any card in the category is worth telling them about. */
    public function test_a_request_with_no_model_matches_the_whole_category(): void
    {
        Notification::fake();

        $other = Part::where('category', 'gpu')->where('id', '!=', $this->part->id)->first()
            ?? $this->part;

        $this->listing(['part_id' => $other->id]);

        $this->wanted(['part_id' => null]);

        Notification::assertSentTo($this->seller, WantedMatchFound::class);
    }

    // --- answering ---------------------------------------------------------

    public function test_a_seller_answers_with_one_of_their_own_listings(): void
    {
        Notification::fake();

        $ad      = $this->wanted();
        $listing = $this->listing();

        app(WantedService::class)->respond($ad, $listing, $this->seller);

        $this->assertSame(1, WantedResponse::count());
        Notification::assertSentTo($this->buyer, WantedAnswered::class);
    }

    /**
     * THE ONE THAT KEEPS „Търся" FROM BECOMING A COMMENT THREAD.
     *
     * An answer must be a real listing, owned by the answerer, that actually
     * matches. Without all three this is a free channel to put anything in
     * front of anybody — which is what it has become on every other Bulgarian
     * classifieds site.
     */
    public function test_an_answer_has_to_be_your_own_listing(): void
    {
        $ad      = $this->wanted();
        $someone = $this->trustedUser();
        $listing = $this->listing(owner: $someone);

        $this->expectException(NotFoundHttpException::class);

        app(WantedService::class)->respond($ad, $listing, $this->seller);
    }

    public function test_an_answer_has_to_match_what_was_asked_for(): void
    {
        $ad = $this->wanted();

        $cpu = Part::where('category', 'cpu')->firstOrFail();
        $listing = $this->listing(['category' => 'cpu', 'part_id' => $cpu->id]);

        try {
            app(WantedService::class)->respond($ad, $listing, $this->seller);
            $this->fail('answered a GPU request with a CPU');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('не отговаря', $e->getMessage());
        }

        $this->assertSame(0, WantedResponse::count());
    }

    /**
     * Over budget is ALLOWED, and deliberately so: a seller at 320 € answering
     * „до 300 €" is making an argument the buyer can judge. Refusing it would
     * only teach sellers to ignore the field.
     */
    public function test_an_answer_over_budget_is_allowed_and_shown_as_such(): void
    {
        Notification::fake();

        $ad      = $this->wanted(['budget_max_cents' => 30000]);
        $listing = $this->listing(['price_cents' => 32000]);

        app(WantedService::class)->respond($ad, $listing, $this->seller);

        Livewire::actingAs($this->buyer)
            ->test(ShowWanted::class, ['ad' => $ad])
            ->assertSee('над бюджета');
    }

    /** The unique index, not a check that a refactor can remove. */
    public function test_one_listing_answers_one_request_once(): void
    {
        Notification::fake();

        $ad      = $this->wanted();
        $listing = $this->listing();

        app(WantedService::class)->respond($ad, $listing, $this->seller);

        try {
            app(WantedService::class)->respond($ad->fresh(), $listing, $this->seller);
            $this->fail('the same listing was offered twice');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Вече', $e->getMessage());
        }

        $this->assertSame(1, WantedResponse::count());
    }

    public function test_a_closed_request_takes_no_more_answers(): void
    {
        Notification::fake();

        $ad = $this->wanted();
        app(WantedService::class)->fulfil($ad, $this->buyer);

        $this->expectException(RuntimeException::class);

        app(WantedService::class)->respond($ad->fresh(), $this->listing(), $this->seller);
    }

    /** Dismissing tells nobody — see WantedResponse. */
    public function test_dismissing_an_answer_notifies_no_one(): void
    {
        Notification::fake();

        $ad       = $this->wanted();
        $response = app(WantedService::class)->respond($ad, $this->listing(), $this->seller);

        Notification::fake();   // forget the answer notification

        app(WantedService::class)->dismiss($response, $this->buyer);

        Notification::assertNothingSent();
        $this->assertSame(WantedResponse::DISMISSED, $response->fresh()->status);
    }

    // --- spam ---------------------------------------------------------------

    /**
     * A new account's requests are held, exactly like their first listings.
     *
     * The gap this closes: broadcasting a request is cheaper than making
     * listings, so an account not trusted to publish one unreviewed must not
     * be trusted to reach every seller in a category either.
     */
    public function test_a_new_accounts_first_request_is_held(): void
    {
        Notification::fake();

        $newcomer = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        $this->listing();

        $ad = $this->wanted(buyer: $newcomer);

        $this->assertSame(WantedStatus::PendingReview, $ad->status);
        $this->assertSame(1, ModerationItem::where('subject_type', $ad->getMorphClass())->count());

        // And nobody was told until a human looked at it.
        Notification::assertNothingSentTo($this->seller);
    }

    /**
     * APPROVING IT HAS TO ANNOUNCE IT.
     *
     * ModerationService::approve() branches per subject type, and its own
     * docblock warns that a missing branch does not throw — it marks the item
     * approved and leaves the subject held forever with nothing in the queue to
     * notice. This is that warning, as a test.
     */
    public function test_approving_a_held_request_publishes_and_announces_it(): void
    {
        Notification::fake();

        $newcomer = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        $this->listing();
        $ad = $this->wanted(buyer: $newcomer);

        $moderator = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
            'is_admin'          => true,
        ]);

        $item = ModerationItem::where('subject_type', $ad->getMorphClass())->sole();

        app(ModerationService::class)->approve($item, $moderator);

        $this->assertSame(WantedStatus::Active, $ad->fresh()->status);
        Notification::assertSentTo($this->seller, WantedMatchFound::class);
    }

    public function test_a_buyer_cannot_fill_the_board(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->wanted(['title' => "Търся карта номер {$i}"]);
        }

        $this->expectException(RuntimeException::class);

        $this->wanted(['title' => 'И още едно']);
    }

    // --- the screens --------------------------------------------------------

    public function test_a_guest_can_read_the_board_and_a_single_request(): void
    {
        Notification::fake();

        $ad = $this->wanted(['title' => 'Търся точно тази карта']);

        $this->get(route('wanted'))->assertOk()->assertSee('Търся точно тази карта');
        $this->get(route('wanted.show', $ad))->assertOk();
    }

    public function test_a_held_request_is_not_on_the_public_board(): void
    {
        Notification::fake();

        $newcomer = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        $ad = $this->wanted(['title' => 'Още непрегледано търсене'], buyer: $newcomer);

        $this->get(route('wanted'))->assertDontSee('Още непрегледано търсене');

        // Its author can still see what happened to it.
        $this->actingAs($newcomer)->get(route('wanted.show', $ad))->assertOk();
    }

    public function test_the_seller_sees_a_one_click_button_for_their_matching_listing(): void
    {
        Notification::fake();

        $ad      = $this->wanted();
        $listing = $this->listing(['title' => 'Моята подходяща карта']);

        Livewire::actingAs($this->seller)
            ->test(ShowWanted::class, ['ad' => $ad])
            ->assertSee('Моята подходяща карта')
            ->call('offer', $listing->id)
            ->assertHasNoErrors();

        $this->assertSame(1, WantedResponse::count());
    }

    /** Offered once, it stops being offerable — the button cannot be a trap. */
    public function test_an_already_offered_listing_is_not_offered_again(): void
    {
        Notification::fake();

        $ad      = $this->wanted();
        $listing = $this->listing(['title' => 'Моята подходяща карта']);

        app(WantedService::class)->respond($ad, $listing, $this->seller);

        Livewire::actingAs($this->seller)
            ->test(ShowWanted::class, ['ad' => $ad->fresh()])
            ->assertDontSee('Моята подходяща карта');
    }

    public function test_a_buyer_posts_a_request_from_the_form(): void
    {
        Notification::fake();

        Livewire::actingAs($this->buyer)
            ->test(ManageWanted::class)
            ->set('category', 'gpu')
            ->set('title', 'Търся RTX 4070')
            ->set('budget', '300')
            ->call('save')
            ->assertHasNoErrors();

        $ad = WantedAd::where('user_id', $this->buyer->id)->sole();

        $this->assertSame(30000, $ad->budget_max_cents);
        $this->assertSame(WantedStatus::Active, $ad->status);
    }

    public function test_posting_needs_an_account(): void
    {
        $this->get(route('wanted.create'))->assertRedirect();
    }

    /** A sold listing must not sit on the buyer's screen as an answer. */
    public function test_an_answer_whose_listing_sells_disappears(): void
    {
        Notification::fake();

        $ad      = $this->wanted();
        $listing = $this->listing(['title' => 'Картата, която се продаде']);

        app(WantedService::class)->respond($ad, $listing, $this->seller);

        Livewire::actingAs($this->buyer)
            ->test(ShowWanted::class, ['ad' => $ad])
            ->assertSee('Картата, която се продаде');

        $listing->forceFill(['status' => ListingStatus::Sold])->save();

        Livewire::actingAs($this->buyer)
            ->test(ShowWanted::class, ['ad' => $ad->fresh()])
            ->assertDontSee('Картата, която се продаде');
    }

    // --- closing ------------------------------------------------------------

    /**
     * Fulfilled and Expired are kept apart because the difference between them
     * is the difference between a feature that works and one that does not.
     */
    public function test_an_expired_request_is_not_a_fulfilled_one(): void
    {
        Notification::fake();

        $ad = $this->wanted();

        $ad->forceFill(['expires_at' => now()->subDay()])->save();

        app(WantedService::class)->expire();

        $this->assertSame(WantedStatus::Expired, $ad->fresh()->status);
        $this->assertNull($ad->fresh()->fulfilled_at);

        $this->get(route('wanted'))->assertDontSee($ad->title);
    }

    /** An expired request is out of sight even before the sweeper runs. */
    public function test_an_expired_request_leaves_the_board_without_the_sweeper(): void
    {
        Notification::fake();

        $ad = $this->wanted(['title' => 'Изтекло търсене за теста']);
        $ad->forceFill(['expires_at' => now()->subDay()])->save();

        $this->get(route('wanted'))->assertDontSee('Изтекло търсене за теста');
    }

    public function test_only_the_buyer_closes_their_own_request(): void
    {
        Notification::fake();

        $ad = $this->wanted();

        $this->expectException(NotFoundHttpException::class);

        app(WantedService::class)->fulfil($ad, $this->seller);
    }
}
