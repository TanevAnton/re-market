<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Messages\Inbox;
use App\Livewire\Messages\ShowThread;
use App\Models\Listing;
use App\Models\Thread;
use App\Models\User;
use App\Services\Deals\DealService;
use App\Services\Messaging\ThreadService;
use App\Services\Offers\OfferService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What the thread view knows about the negotiation happening inside it.
 *
 * The screen where two people actually talk had no idea whether they had
 * agreed a price. Both of them could see an offer on /oferti and a deal on
 * /sdelki, and neither could see either one here - so "did we agree or not"
 * was answered from memory, in the one place the answer matters most.
 */
class ThreadContextTest extends TestCase
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
            'offers_enabled'  => true,
            'price_cents'     => 100000,
            'min_offer_cents' => null,
        ]);

        RateLimiter::clear('*');
    }

    private function thread(): Thread
    {
        return app(ThreadService::class)->open($this->listing, $this->buyer);
    }

    // --- offers -----------------------------------------------------------

    public function test_a_live_offer_is_shown_in_the_thread(): void
    {
        app(OfferService::class)->place($this->listing, $this->buyer, 90000);

        Livewire::actingAs($this->seller)
            ->test(ShowThread::class, ['thread' => $this->thread()])
            ->assertSee('Има оферта')
            ->assertSee('900,00 €')
            // A plain offer waits on the seller, and the seller is looking.
            ->assertSee('Чака твоя отговор.');
    }

    public function test_the_buyer_is_not_told_their_own_offer_waits_on_them(): void
    {
        app(OfferService::class)->place($this->listing, $this->buyer, 90000);

        Livewire::actingAs($this->buyer)
            ->test(ShowThread::class, ['thread' => $this->thread()])
            ->assertSee('Има оферта')
            ->assertDontSee('Чака твоя отговор.');
    }

    /**
     * An offer that is no longer live is not news. Showing a withdrawn or
     * declined number at the top of the chat is worse than showing nothing,
     * because it reads as still standing.
     */
    public function test_a_settled_offer_is_not_shown_as_live(): void
    {
        $offer = app(OfferService::class)->place($this->listing, $this->buyer, 90000);
        app(OfferService::class)->decline($offer, $this->seller);

        Livewire::actingAs($this->seller)
            ->test(ShowThread::class, ['thread' => $this->thread()])
            ->assertDontSee('Има оферта');
    }

    // --- deals ------------------------------------------------------------

    public function test_an_accepted_deal_replaces_the_offer_card(): void
    {
        $offer = app(OfferService::class)->place($this->listing, $this->buyer, 90000);
        app(OfferService::class)->accept($offer, $this->seller);

        Livewire::actingAs($this->buyer)
            ->test(ShowThread::class, ['thread' => $this->thread()])
            ->assertSee('Договорихте се')
            ->assertSee('900,00 €')
            // The offer produced the deal; the deal is now the thing that matters.
            ->assertDontSee('Има оферта');
    }

    public function test_a_deal_you_have_confirmed_says_who_is_being_waited_on(): void
    {
        $offer = app(OfferService::class)->place($this->listing, $this->buyer, 90000);
        $deal  = app(OfferService::class)->accept($offer, $this->seller);
        app(DealService::class)->confirm($deal, $this->buyer);

        Livewire::actingAs($this->buyer)
            ->test(ShowThread::class, ['thread' => $this->thread()])
            ->assertSee('Ти потвърди.');
    }

    /**
     * The masking rule and the banner that explains it read the same lookup.
     * Two copies of the "which statuses count" list is how the thread ends up
     * promising free contact exchange while the scrubber is still stripping.
     */
    public function test_the_contact_banner_agrees_with_the_scrubber(): void
    {
        $thread = $this->thread();
        $this->assertFalse($thread->allowsContactExchange());
        $this->assertNull($thread->currentDeal());

        $offer = app(OfferService::class)->place($this->listing, $this->buyer, 90000);
        app(OfferService::class)->accept($offer, $this->seller);

        $fresh = $thread->fresh();
        $this->assertNotNull($fresh->currentDeal());
        $this->assertTrue($fresh->allowsContactExchange());
    }

    // --- the safety warning -----------------------------------------------

    /**
     * It used to be the last paragraph on the page, under the send button, in
     * the faintest text available. Advance payment is how people get robbed
     * here, and this is the moment they are about to agree to one.
     */
    public function test_the_advance_payment_warning_sits_above_the_compose_box(): void
    {
        $html = Livewire::actingAs($this->buyer)
            ->test(ShowThread::class, ['thread' => $this->thread()])
            ->html();

        $warning = mb_strpos($html, 'Не плащай предварително');
        $textarea = mb_strpos($html, '<textarea');

        $this->assertNotFalse($warning, 'the warning is gone entirely');
        $this->assertNotFalse($textarea);
        $this->assertLessThan($textarea, $warning, 'the warning is below the compose box again');
    }

    // --- the inbox --------------------------------------------------------

    public function test_unread_threads_are_counted_in_one_query(): void
    {
        $threads = [];

        foreach (range(1, 3) as $i) {
            $listing = Listing::factory()->create([
                'user_id' => $this->seller->id,
                'status'  => ListingStatus::Active,
            ]);

            $thread = app(ThreadService::class)->open($listing, $this->buyer);
            RateLimiter::clear("msg:{$thread->id}:{$this->buyer->id}");
            app(ThreadService::class)->send($thread, $this->buyer, 'здрасти');

            $threads[] = $thread;
        }

        $ids = array_map(fn (Thread $t) => $t->id, $threads);

        $counts = Thread::unreadCountsFor($this->seller->id, $ids);

        // One row per thread with something unread, and the seller wrote none
        // of them.
        $this->assertSame([1, 1, 1], array_values(array_map(
            fn ($id) => $counts[$id] ?? 0,
            $ids,
        )));

        // The batched version and the per-thread one must not be able to
        // disagree: the inbox shows one and the header badge sums the other.
        $this->assertSame(
            array_sum($counts),
            Thread::unreadTotalFor($this->seller->id),
        );
    }

    public function test_the_sender_has_nothing_unread_of_their_own(): void
    {
        $thread = $this->thread();
        RateLimiter::clear("msg:{$thread->id}:{$this->buyer->id}");
        app(ThreadService::class)->send($thread, $this->buyer, 'здрасти');

        $this->assertSame([], Thread::unreadCountsFor($this->buyer->id, [$thread->id]));
        $this->assertSame(0, Thread::unreadTotalFor($this->buyer->id));
    }

    public function test_an_unread_thread_is_marked_on_the_row_not_only_by_a_badge(): void
    {
        $thread = $this->thread();
        RateLimiter::clear("msg:{$thread->id}:{$this->buyer->id}");
        app(ThreadService::class)->send($thread, $this->buyer, 'здрасти');

        Livewire::actingAs($this->seller)
            ->test(Inbox::class)
            ->assertSee('непрочетени')
            // The row itself, not just the pill inside it.
            ->assertSee('border-l-accent', escape: false);
    }

    public function test_a_read_thread_is_not_marked(): void
    {
        $thread = $this->thread();
        RateLimiter::clear("msg:{$thread->id}:{$this->buyer->id}");
        app(ThreadService::class)->send($thread, $this->buyer, 'здрасти');

        // Opening it marks it read.
        Livewire::actingAs($this->seller)->test(ShowThread::class, ['thread' => $thread]);

        Livewire::actingAs($this->seller)
            ->test(Inbox::class)
            ->assertDontSee('непрочетени')
            ->assertDontSee('border-l-accent', escape: false);
    }

    public function test_an_empty_inbox_offers_both_ways_out(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Inbox::class)
            ->assertSee('Още нямаш разговори')
            ->assertSee(route('browse'), escape: false)
            ->assertSee(route('listing.create'), escape: false);
    }
}
