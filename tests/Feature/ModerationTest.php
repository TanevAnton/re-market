<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\ModerationTrigger;
use App\Enums\RejectionReason;
use App\Livewire\Moderation\Queue;
use App\Models\Listing;
use App\Models\ModerationItem;
use App\Models\User;
use App\Services\Moderation\ModerationService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The moderation queue.
 *
 * Two things are being protected here. One is ordinary: only moderators decide,
 * and a decision is made once. The other is legal - a refusal must carry a
 * statement of reasons, and the seller must actually receive it. A decision
 * filed only in the database satisfies nobody.
 */
class ModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $admin;
    private ModerationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller  = User::factory()->create();
        $this->admin   = User::factory()->create(['is_admin' => true]);
        $this->service = app(ModerationService::class);
    }

    private function queued(): ModerationItem
    {
        $listing = Listing::factory()->create([
            'user_id' => $this->seller->id,
            'status'  => ListingStatus::PendingReview,
        ]);

        return $this->service->enqueue($listing, ModerationTrigger::NewAccount);
    }

    // --- who gets in -----------------------------------------------------

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('moderation'))->assertRedirect(route('login'));
    }

    /**
     * 404, not 403. A 403 confirms the URL exists and is worth attacking.
     */
    public function test_an_ordinary_user_gets_a_404(): void
    {
        $this->actingAs($this->seller)->get(route('moderation'))->assertNotFound();
    }

    public function test_a_moderator_sees_the_queue(): void
    {
        $item = $this->queued();

        $this->actingAs($this->admin)
            ->get(route('moderation'))
            ->assertOk()
            ->assertSee($item->subject->title);
    }

    // --- deciding --------------------------------------------------------

    public function test_approving_publishes_the_listing(): void
    {
        $item = $this->queued();

        Livewire::actingAs($this->admin)
            ->test(Queue::class)
            ->call('approve', $item->id)
            ->assertHasNoErrors();

        $this->assertSame(ListingStatus::Active, $item->subject->fresh()->status);
        $this->assertSame('approved', $item->fresh()->status);
        $this->assertSame($this->admin->id, $item->fresh()->decided_by);
    }

    /**
     * A listing that waited in the queue should not be closer to expiring for
     * having waited. The clock starts when it becomes visible.
     */
    public function test_the_expiry_clock_restarts_at_approval(): void
    {
        $item = $this->queued();
        $item->subject->forceFill(['expires_at' => now()->addDays(3)])->save();

        $this->service->approve($item, $this->admin);

        $this->assertTrue($item->subject->fresh()->expires_at->isAfter(now()->addDays(50)));
    }

    public function test_rejecting_removes_the_listing_and_records_a_statement(): void
    {
        $item = $this->queued();

        Livewire::actingAs($this->admin)
            ->test(Queue::class)
            ->call('startReject', $item->id)
            ->set('reason', RejectionReason::StockPhotos->value)
            ->set('facts', 'Снимките са рекламни от сайта на производителя.')
            ->call('reject', $item->id)
            ->assertHasNoErrors();

        $item = $item->fresh();

        $this->assertSame(ListingStatus::Removed, $item->subject->fresh()->status);
        $this->assertSame('rejected', $item->status);
        $this->assertSame(RejectionReason::StockPhotos, $item->decision_reason);

        // DSA Art. 17: the facts relied on, the ground, and how to challenge it.
        $this->assertStringContainsString('рекламни', $item->statement_of_reasons);
        $this->assertStringContainsString(
            RejectionReason::StockPhotos->ground(),
            $item->statement_of_reasons,
        );
        $this->assertStringContainsString('оспориш', $item->statement_of_reasons);
    }

    /**
     * The length floor is the rule, not a formality: without it the statement
     * of reasons becomes the category typed out again, which tells the seller
     * nothing they can act on or dispute.
     */
    public function test_a_rejection_without_facts_is_refused(): void
    {
        $item = $this->queued();

        Livewire::actingAs($this->admin)
            ->test(Queue::class)
            ->call('startReject', $item->id)
            ->set('reason', RejectionReason::Other->value)
            ->set('facts', 'лошо')
            ->call('reject', $item->id)
            ->assertHasErrors('facts');

        $this->assertSame(ListingStatus::PendingReview, $item->subject->fresh()->status);
        $this->assertSame('pending', $item->fresh()->status);
    }

    public function test_a_decided_item_leaves_the_queue(): void
    {
        $item = $this->queued();
        $this->service->approve($item, $this->admin);

        Livewire::actingAs($this->admin)
            ->test(Queue::class)
            ->assertDontSee($item->subject->title);
    }

    public function test_the_same_item_cannot_be_decided_twice(): void
    {
        $item = $this->queued();
        $this->service->approve($item, $this->admin);

        // Two moderators with the queue open, both pressing approve.
        Livewire::actingAs($this->admin)
            ->test(Queue::class)
            ->call('approve', $item->id)
            ->assertHasErrors('queue');
    }

    /**
     * An admin approving their own listing is the check reviewing itself, and
     * admins post listings like everyone else.
     */
    public function test_a_moderator_cannot_decide_their_own_listing(): void
    {
        $own = Listing::factory()->create([
            'user_id' => $this->admin->id,
            'status'  => ListingStatus::PendingReview,
        ]);

        $item = $this->service->enqueue($own, ModerationTrigger::NewAccount);

        Livewire::actingAs($this->admin)
            ->test(Queue::class)
            ->call('approve', $item->id)
            ->assertHasErrors('queue');

        $this->assertSame(ListingStatus::PendingReview, $own->fresh()->status);
    }

    // --- the queue itself -------------------------------------------------

    /**
     * Publishing twice, or any future automated check running again, must not
     * ask a moderator the same question twice.
     */
    public function test_enqueueing_the_same_subject_twice_makes_one_item(): void
    {
        $listing = Listing::factory()->create([
            'user_id' => $this->seller->id,
            'status'  => ListingStatus::PendingReview,
        ]);

        $first  = $this->service->enqueue($listing, ModerationTrigger::NewAccount);
        $second = $this->service->enqueue($listing, ModerationTrigger::NewAccount);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ModerationItem::count());
    }

    public function test_fraud_is_reviewed_before_housekeeping(): void
    {
        $new = $this->queued();

        $flagged = Listing::factory()->create([
            'user_id' => $this->seller->id,
            'status'  => ListingStatus::PendingReview,
        ]);
        $this->service->enqueue($flagged, ModerationTrigger::PhashCollision);

        $order = ModerationItem::queue()->pluck('id')->all();

        // A reused photo is somebody's listing being stolen; a new account is
        // paperwork. The queue has to reflect that or the wrong thing waits.
        $this->assertNotSame($new->id, $order[0]);
    }

    // --- what the seller sees --------------------------------------------

    public function test_the_seller_is_shown_the_statement_of_reasons(): void
    {
        $item = $this->queued();

        $this->service->reject(
            $item,
            $this->admin,
            RejectionReason::ContactInfo,
            'В описанието има телефонен номер и линк към друга платформа.',
        );

        $this->actingAs($this->seller)
            ->get(route('listing', $item->subject))
            ->assertOk()
            ->assertSee('телефонен номер', false)
            ->assertSee('оспориш', false);
    }

    public function test_a_stranger_cannot_read_a_removed_listing(): void
    {
        $item = $this->queued();

        $this->service->reject(
            $item,
            $this->admin,
            RejectionReason::ContactInfo,
            'В описанието има телефонен номер и линк към друга платформа.',
        );

        $this->actingAs(User::factory()->create())
            ->get(route('listing', $item->subject))
            ->assertNotFound();
    }

    // --- granting the rights ---------------------------------------------

    public function test_the_make_admin_command_grants_and_revokes(): void
    {
        $this->artisan('remarket:make-admin', ['user' => $this->seller->email])
            ->assertSuccessful();

        $this->assertTrue($this->seller->fresh()->is_admin);

        $this->artisan('remarket:make-admin', ['user' => $this->seller->username, '--revoke' => true])
            ->assertSuccessful();

        $this->assertFalse($this->seller->fresh()->is_admin);
    }

    public function test_the_make_admin_command_fails_on_an_unknown_user(): void
    {
        $this->artisan('remarket:make-admin', ['user' => 'nobody@example.com'])
            ->assertFailed();
    }
}
