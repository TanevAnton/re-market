<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Messages\Inbox;
use App\Livewire\Messages\ShowThread;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\ThreadService;
use App\Services\Offers\OfferService;
use App\Support\ContactScrubber;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $buyer;
    private Listing $listing;
    private ThreadService $threads;

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

        $this->threads = app(ThreadService::class);

        // The service throttles one message every few seconds. Real behaviour,
        // but it would make these tests about the rate limiter.
        RateLimiter::clear('*');
    }

    private function thread(): Thread
    {
        return $this->threads->open($this->listing, $this->buyer);
    }

    private function sendFresh(Thread $thread, User $sender, string $body): Message
    {
        RateLimiter::clear("msg:{$thread->id}:{$sender->id}");

        return $this->threads->send($thread, $sender, $body);
    }

    // --- the scrubber ----------------------------------------------------

    public static function contactCases(): array
    {
        return [
            'bg mobile'        => ['Звънни на 0888 123 456', true],
            'e164'             => ['+359 88 812 34 56', true],
            'run together'     => ['0899111222 звънни', true],
            'email'            => ['пиши на ivan@abv.bg', true],
            'url'              => ['виж https://olx.bg/x', true],
            'telegram handle'  => ['@ivanpetrov в телеграм', true],
            'price is not a phone' => ['Мога 850 лв', false],
            'year is not a phone'  => ['гаранция до 2027 г.', false],
            'courier talk'     => ['Приемам преглед и тест с Еконт', false],
        ];
    }

    #[DataProvider('contactCases')]
    public function test_the_scrubber_removes_contacts_but_leaves_normal_sentences(string $body, bool $expected): void
    {
        $result = ContactScrubber::scrub($body);

        $this->assertSame($expected, $result['had_contact_info'], $body);

        if ($expected) {
            $this->assertStringContainsString('[скрито]', $result['clean']);
        } else {
            // A false positive is worse than a miss: it mangles an ordinary
            // sentence and the sender cannot tell why.
            $this->assertSame($body, $result['clean']);
        }
    }

    // --- threads ---------------------------------------------------------

    public function test_a_buyer_gets_one_thread_per_listing_however_often_they_open_it(): void
    {
        $first  = $this->thread();
        $second = $this->thread();

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('threads', 1);
    }

    public function test_a_seller_cannot_message_themselves(): void
    {
        $this->expectException(MessagingException::class);

        $this->threads->open($this->listing, $this->seller);
    }

    public function test_a_stranger_cannot_post_into_someone_elses_thread(): void
    {
        $thread = $this->thread();

        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('Нямаш достъп');

        $this->threads->send($thread, User::factory()->create(), 'здрасти');
    }

    public function test_a_locked_thread_refuses_messages(): void
    {
        $thread = $this->thread();
        $thread->forceFill(['is_locked' => true])->save();

        $this->expectException(MessagingException::class);

        $this->threads->send($thread, $this->buyer, 'здрасти');
    }

    public function test_messages_are_throttled(): void
    {
        $thread = $this->thread();
        $this->threads->send($thread, $this->buyer, 'едно');

        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('Изчакай');

        $this->threads->send($thread, $this->buyer, 'две');
    }

    // --- scrubbing in context --------------------------------------------

    public function test_a_phone_number_is_hidden_before_a_deal_but_kept_on_the_record(): void
    {
        $thread  = $this->thread();
        $message = $this->sendFresh($thread, $this->buyer, 'Звънни ми на 0888123456');

        $this->assertStringNotContainsString('0888123456', $message->visibleBody());
        $this->assertTrue($message->had_contact_info);

        // The original survives, so a pattern of pushing people off-platform
        // can be evidenced during moderation rather than merely asserted.
        $this->assertStringContainsString('0888123456', $message->body);
    }

    /**
     * The whole point of the rule. Before agreement the platform keeps the
     * conversation here; after agreement it gets out of the way, because these
     * two now have to arrange a handover and phone numbers are stored only as
     * an HMAC - the site could not hand one over itself if it wanted to.
     */
    public function test_contacts_flow_freely_once_a_deal_exists(): void
    {
        $thread = $this->thread();
        $this->assertFalse($thread->allowsContactExchange());

        $offers = app(OfferService::class);
        $offer  = $offers->place($this->listing, $this->buyer, 90000);
        $offers->accept($offer, $this->seller);

        $this->assertTrue($thread->fresh()->allowsContactExchange());

        $message = $this->sendFresh($thread->fresh(), $this->seller, 'Звънни на 0888123456');

        $this->assertStringContainsString('0888123456', $message->visibleBody());
        $this->assertTrue($message->had_contact_info);
    }

    // --- unread ----------------------------------------------------------

    public function test_writing_a_message_does_not_make_it_unread_for_its_own_sender(): void
    {
        $thread = $this->thread();
        $this->threads->send($thread, $this->buyer, 'здрасти');

        $this->assertSame(0, Thread::unreadTotalFor($this->buyer->id));
        $this->assertSame(1, Thread::unreadTotalFor($this->seller->id));
    }

    public function test_opening_a_thread_clears_its_unread_count(): void
    {
        $thread = $this->thread();
        $this->threads->send($thread, $this->buyer, 'здрасти');

        $this->actingAs($this->seller);
        Livewire::test(ShowThread::class, ['thread' => $thread]);

        $this->assertSame(0, Thread::unreadTotalFor($this->seller->id));
    }

    // --- the screens -----------------------------------------------------

    public function test_the_listing_button_opens_a_thread_and_redirects_to_it(): void
    {
        $this->actingAs($this->buyer);

        $this->get(route('listing.message', $this->listing))
            ->assertRedirect(route('thread', Thread::firstOrFail()));

        $this->assertDatabaseCount('threads', 1);
    }

    public function test_a_stranger_cannot_open_someone_elses_thread(): void
    {
        $thread = $this->thread();

        $this->actingAs(User::factory()->create());

        $this->get(route('thread', $thread))->assertNotFound();
    }

    public function test_both_sides_see_the_thread_in_their_inbox(): void
    {
        $thread = $this->thread();
        $this->threads->send($thread, $this->buyer, 'налична ли е?');

        foreach ([$this->buyer, $this->seller] as $user) {
            $this->actingAs($user);
            Livewire::test(Inbox::class)->assertSee('налична ли е?');
        }
    }

    public function test_messaging_requires_a_login(): void
    {
        $this->get(route('messages'))->assertRedirect(route('login'));
    }
}
