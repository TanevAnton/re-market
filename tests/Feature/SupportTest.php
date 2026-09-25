<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Enums\TicketTopic;
use App\Livewire\Support\ShowTicket;
use App\Livewire\Support\SupportCentre;
use App\Livewire\Support\TicketQueue;
use App\Models\City;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketReplied;
use App\Services\Support\SupportService;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The support page.
 *
 * TWO THINGS ARE BEING PROTECTED and only one of them is the feature.
 *
 * The feature: somebody can ask a question and get an answer in one thread.
 *
 * The PROTECTION, and the reason half this file exists: a guest ticket has no
 * account to gate it on, so the emailed link is signed and the signature is the
 * credential. Get that wrong in either direction and the failure is silent —
 * too strict and the person who cannot log in cannot read their own answer
 * either; too loose and a uuid in a URL reads anybody's support conversation,
 * complete with their email address.
 */
class SupportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $admin;
    private SupportService $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        $this->user = User::factory()->create([
            'email'             => 'seller@example.com',
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
            'is_admin'          => true,
        ]);

        $this->support = app(SupportService::class);
    }

    private function guestTicket(string $email = 'guest@example.com'): Ticket
    {
        return $this->support->open(
            user: null,
            email: $email,
            topic: TicketTopic::Account,
            subject: 'Не мога да вляза в профила си',
            body: 'Паролата ми не се приема, а имейлът за възстановяване не идва.',
        );
    }

    // --- reachable without an account -------------------------------------

    /**
     * THE ONE THAT JUSTIFIES THE WHOLE DESIGN.
     *
     * The commonest first-week ticket is „I cannot log in". A support form
     * behind a login is useless to exactly that person, so the page and the
     * form are public.
     */
    public function test_a_guest_can_reach_the_page_and_open_a_ticket(): void
    {
        $this->get(route('support'))->assertOk();

        Livewire::test(SupportCentre::class)
            ->set('topic', TicketTopic::Account->value)
            ->set('subject', 'Не мога да вляза')
            ->set('body', 'Паролата не се приема и имейлът за възстановяване не идва.')
            ->set('email', 'guest@example.com')
            ->call('submit')
            ->assertHasNoErrors();

        $ticket = Ticket::sole();

        $this->assertNull($ticket->user_id);
        $this->assertSame('guest@example.com', $ticket->email);
        $this->assertSame(TicketStatus::New, $ticket->status);
        $this->assertCount(1, $ticket->messages);
    }

    public function test_a_signed_in_user_does_not_have_to_retype_their_address(): void
    {
        Livewire::actingAs($this->user)
            ->test(SupportCentre::class)
            ->set('topic', TicketTopic::Bug->value)
            ->set('subject', 'Снимките не се качват')
            ->set('body', 'При трета снимка формата спира и нищо не се случва.')
            ->call('submit')
            ->assertHasNoErrors();

        $ticket = Ticket::sole();

        $this->assertSame($this->user->id, $ticket->user_id);
        $this->assertSame('seller@example.com', $ticket->email);
    }

    public function test_a_guest_has_to_leave_an_address(): void
    {
        Livewire::test(SupportCentre::class)
            ->set('topic', TicketTopic::Other->value)
            ->set('subject', 'Един въпрос')
            ->set('body', 'Това е достатъчно дълъг текст, за да мине проверката.')
            ->call('submit')
            ->assertHasErrors('email');

        $this->assertSame(0, Ticket::count());
    }

    /** „не работи" is not something anybody can answer. */
    public function test_a_one_word_description_is_refused(): void
    {
        Livewire::actingAs($this->user)
            ->test(SupportCentre::class)
            ->set('topic', TicketTopic::Bug->value)
            ->set('subject', 'Проблем')
            ->set('body', 'не работи')
            ->call('submit')
            ->assertHasErrors('body');
    }

    // --- what the page must NOT swallow -----------------------------------

    /**
     * A DSA notice filed as a support ticket loses its clock, its statement of
     * reasons and its appeal — and nobody finds out for months. So the topics
     * do not offer it, and the page says where it goes, above the form.
     */
    public function test_the_topics_do_not_invite_a_dsa_notice(): void
    {
        $labels = implode(' ', array_values(TicketTopic::options()));

        foreach (['измама', 'незакон', 'сигнал', 'жалба'] as $word) {
            $this->assertStringNotContainsString($word, mb_strtolower($labels));
        }

        $this->get(route('support'))
            ->assertSee('Измама или незаконно съдържание')
            ->assertSee(route('legal.notice'), escape: false);
    }

    // --- the cap ----------------------------------------------------------

    /**
     * A queue with thirty items in it, twenty of which are one person asking
     * again, is a queue nobody opens — and the ticket that mattered is in the
     * middle of it.
     */
    public function test_one_person_cannot_fill_the_queue(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->guestTicket();
        }

        try {
            $this->guestTicket();
            $this->fail('a fourth open ticket was accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('отворени запитвания', $e->getMessage());
        }

        $this->assertSame(3, Ticket::count());
    }

    /**
     * Counted per PERSON, not per account. Counting only by user_id would let
     * the same address open unlimited tickets while logged out — which is both
     * the spam path and the honest one: somebody who cannot log in tries three
     * times.
     */
    public function test_the_cap_follows_the_address_across_the_login_boundary(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->guestTicket('seller@example.com');
        }

        $this->expectException(RuntimeException::class);

        $this->support->open(
            user: $this->user,
            email: 'seller@example.com',
            topic: TicketTopic::Other,
            subject: 'Още един',
            body: 'Достатъчно дълъг текст за проверката на минималната дължина.',
        );
    }

    /** A closed ticket does not count against it. */
    public function test_closing_one_frees_a_slot(): void
    {
        $first = $this->guestTicket();
        $this->guestTicket();
        $this->guestTicket();

        $this->support->close($first, $this->admin);

        $this->guestTicket();

        $this->assertSame(4, Ticket::count());
    }

    // --- who may read it --------------------------------------------------

    /**
     * THE ONE THAT KEEPS SOMEBODY ELSE'S SUPPORT THREAD PRIVATE.
     *
     * A uuid in a URL is not a credential. Without a signature and without an
     * account, this has to be a 404 — and a 404 rather than a 403, because a
     * 403 confirms the ticket exists.
     */
    public function test_a_guest_ticket_is_not_readable_from_its_url_alone(): void
    {
        $ticket = $this->guestTicket();

        $this->get(route('support.ticket', $ticket))->assertNotFound();
    }

    public function test_a_signed_link_opens_a_guest_ticket(): void
    {
        $ticket = $this->guestTicket();

        $this->get(URL::signedRoute('support.ticket', $ticket))
            ->assertOk()
            ->assertSee($ticket->reference)
            ->assertSee('Не мога да вляза в профила си');
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        $ticket = $this->guestTicket();

        $url = URL::signedRoute('support.ticket', $ticket);

        $this->get($url.'x')->assertNotFound();
    }

    /** A signature for one ticket does not open another. */
    public function test_a_signature_does_not_travel_between_tickets(): void
    {
        $mine  = $this->guestTicket('a@example.com');
        $other = $this->guestTicket('b@example.com');

        $signed = URL::signedRoute('support.ticket', $mine);
        $query  = parse_url($signed, PHP_URL_QUERY);

        $this->get(route('support.ticket', $other).'?'.$query)->assertNotFound();
    }

    public function test_an_owner_reads_their_own_ticket_without_a_signature(): void
    {
        $ticket = $this->support->open(
            user: $this->user,
            email: $this->user->email,
            topic: TicketTopic::Boost,
            subject: 'Платих за топ обява',
            body: 'Платих за топ обява, но не я виждам в блока най-горе.',
        );

        $this->actingAs($this->user)
            ->get(route('support.ticket', $ticket))
            ->assertOk()
            ->assertSee('Платих за топ обява');
    }

    public function test_a_stranger_cannot_read_an_account_ticket(): void
    {
        $ticket = $this->support->open(
            user: $this->user,
            email: $this->user->email,
            topic: TicketTopic::Other,
            subject: 'Личен въпрос',
            body: 'Съдържание, което никой друг не бива да вижда, достатъчно дълго.',
        );

        $stranger = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        $this->actingAs($stranger)->get(route('support.ticket', $ticket))->assertNotFound();
    }

    public function test_an_admin_can_read_any_ticket(): void
    {
        $ticket = $this->guestTicket();

        $this->actingAs($this->admin)->get(route('support.ticket', $ticket))->assertOk();
    }

    /**
     * THE ONE THAT CAUGHT A DESIGN MISTAKE.
     *
     * The first version checked the signature on every request. That breaks two
     * ordinary things at once: a guest who REFRESHES the page loses the query
     * string and gets thrown out of their own thread, and Livewire's updates
     * are POSTs to its own endpoint carrying none of the original URL — so the
     * first reply would work and the second would 404. The worst kind of bug,
     * because it passes a manual test done once.
     *
     * Opening the signed link grants access to that ticket in the session, and
     * everything afterwards reads the session. This is that, tested the way a
     * person actually behaves: open the emailed link, then reload without it.
     */
    public function test_opening_the_signed_link_keeps_the_thread_open_afterwards(): void
    {
        $ticket = $this->guestTicket();

        // Cold, with no signature: nothing.
        $this->get(route('support.ticket', $ticket))->assertNotFound();

        $this->get(URL::signedRoute('support.ticket', $ticket))->assertOk();

        // Now a plain reload — no signature anywhere — still works.
        $this->get(route('support.ticket', $ticket))
            ->assertOk()
            ->assertSee($ticket->reference);
    }

    /** And a guest can write in that thread, more than once. */
    public function test_a_guest_can_reply_after_opening_the_signed_link(): void
    {
        $ticket = $this->guestTicket();

        $this->get(URL::signedRoute('support.ticket', $ticket))->assertOk();

        Livewire::test(ShowTicket::class, ['ticket' => $ticket])
            ->set('body', 'Забравих да добавя, че опитах и от друг браузър.')
            ->call('reply')
            ->assertHasNoErrors()
            ->set('body', 'И от телефона е същото.')
            ->call('reply')
            ->assertHasNoErrors();

        $this->assertCount(3, $ticket->fresh()->messages);
    }

    /** Access to one conversation is access to one conversation. */
    public function test_opening_one_ticket_does_not_open_another(): void
    {
        $mine  = $this->guestTicket('a@example.com');
        $other = $this->guestTicket('b@example.com');

        $this->get(URL::signedRoute('support.ticket', $mine))->assertOk();

        $this->get(route('support.ticket', $other))->assertNotFound();
    }

    // --- answering --------------------------------------------------------

    public function test_an_answer_reaches_the_address_on_the_ticket(): void
    {
        Notification::fake();

        $ticket = $this->guestTicket();

        $this->support->answer($ticket, 'Пуснахме ти нов имейл за възстановяване.', $this->admin);

        Notification::assertSentOnDemand(
            TicketReplied::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'guest@example.com',
        );

        $ticket->refresh();

        $this->assertSame(TicketStatus::Answered, $ticket->status);
        $this->assertTrue($ticket->unreadForUser());
        $this->assertFalse($ticket->unreadForStaff());
    }

    public function test_a_user_reply_puts_it_back_in_the_queue(): void
    {
        Notification::fake();

        $ticket = $this->guestTicket();
        $this->support->answer($ticket, 'Пробвай отново и ни кажи.', $this->admin);

        $this->support->reply($ticket->fresh(), 'Все още не става.');

        $ticket->refresh();

        // Open, not New: a follow-up must not jump ahead of a first-time
        // question nobody has looked at yet.
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertTrue($ticket->unreadForStaff());
        $this->assertFalse($ticket->unreadForUser());
    }

    public function test_a_closed_ticket_takes_no_more_replies(): void
    {
        Notification::fake();

        $ticket = $this->guestTicket();
        $this->support->answer($ticket, 'Готово.', $this->admin, close: true);

        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);

        $this->expectException(RuntimeException::class);

        $this->support->reply($ticket->fresh(), 'Още нещо?');
    }

    /**
     * THE REGRESSION GUARD FOR A BUG I WROTE.
     *
     * The first version tracked who had read what with three TIMESTAMPS, and
     * these columns store seconds. Open a ticket and answer it inside the same
     * second — which every test does, and a quick reply in production will — and
     * „seen strictly before the last reply" is false, so the badge silently
     * never appears. Message ids have no such resolution to run out of.
     *
     * `travel()` is deliberately NOT used here. Freezing or advancing the clock
     * is exactly what would hide the bug this test exists for.
     */
    public function test_an_answer_in_the_same_second_still_shows_as_unread(): void
    {
        Notification::fake();

        $ticket = $this->guestTicket();
        $this->support->answer($ticket, 'Ето отговора, веднага.', $this->admin);

        $ticket->refresh();

        $this->assertTrue($ticket->unreadForUser());
        $this->assertFalse($ticket->unreadForStaff());

        // And the reverse, also within one second.
        $this->support->reply($ticket, 'Благодаря, но още не става.');

        $ticket->refresh();

        $this->assertTrue($ticket->unreadForStaff());
        $this->assertFalse($ticket->unreadForUser());
    }

    /**
     * Reading a new ticket is what „в работа" means. Left at New it would look
     * untouched to the next person who opens the queue.
     */
    public function test_reading_a_new_ticket_marks_it_in_progress(): void
    {
        $ticket = $this->guestTicket();

        $this->support->markSeenByStaff($ticket);

        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
    }

    /** An admin opening a ticket must not clear the USER's unread mark. */
    public function test_an_admin_reading_a_ticket_does_not_mark_it_read_for_the_user(): void
    {
        Notification::fake();

        $ticket = $this->guestTicket();
        $this->support->answer($ticket, 'Ето отговора.', $this->admin);

        $this->assertTrue($ticket->fresh()->unreadForUser());

        $this->actingAs($this->admin)->get(route('support.ticket', $ticket))->assertOk();

        $this->assertTrue($ticket->fresh()->unreadForUser());
    }

    public function test_opening_a_ticket_clears_the_users_own_badge(): void
    {
        Notification::fake();

        $ticket = $this->support->open(
            user: $this->user,
            email: $this->user->email,
            topic: TicketTopic::Other,
            subject: 'Въпрос',
            body: 'Достатъчно дълъг текст, за да мине проверката за дължина.',
        );

        $this->support->answer($ticket, 'Отговор.', $this->admin);
        $this->assertTrue($ticket->fresh()->unreadForUser());

        $this->actingAs($this->user)->get(route('support.ticket', $ticket))->assertOk();

        $this->assertFalse($ticket->fresh()->unreadForUser());
    }

    // --- the queue --------------------------------------------------------

    public function test_the_queue_is_admin_only(): void
    {
        $this->actingAs($this->user)->get(route('tickets'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('tickets'))->assertOk();
    }

    public function test_an_admin_answers_from_the_queue(): void
    {
        Notification::fake();

        $ticket = $this->guestTicket();

        Livewire::actingAs($this->admin)
            ->test(TicketQueue::class)
            ->assertSee($ticket->reference)
            ->call('openReply', $ticket->id)
            ->set('body', 'Изпратихме ти нов имейл за възстановяване на паролата.')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(TicketStatus::Answered, $ticket->fresh()->status);
        Notification::assertSentOnDemand(TicketReplied::class);
    }

    /** Oldest first: the longest wait is the one somebody has given up on. */
    public function test_the_queue_puts_the_longest_wait_first(): void
    {
        $first  = $this->guestTicket('one@example.com');
        $second = $this->guestTicket('two@example.com');

        $tickets = Livewire::actingAs($this->admin)
            ->test(TicketQueue::class)
            ->viewData('tickets');

        $this->assertSame($first->id, $tickets->first()->id);
        $this->assertTrue($tickets->contains('id', $second->id));
    }

    // --- the address ------------------------------------------------------

    /**
     * The published DSA Art. 12 contact point is on the site's own domain.
     *
     * A marketplace whose published contact sits on another company's domain
     * reads as a front to anybody who checks — and the people who check are
     * exactly the cautious buyers worth keeping.
     */
    public function test_the_published_contact_is_on_the_sites_own_domain(): void
    {
        $this->assertSame('support@rigo.bg', config('legal.contact.users'));

        foreach ([config('legal.contact.users'), config('legal.contact.authorities')] as $address) {
            $this->assertStringEndsWith('@rigo.bg', $address);
        }
    }

    public function test_the_address_and_the_form_are_both_reachable_from_every_page(): void
    {
        $this->get(route('home'))
            ->assertSee(route('support'), escape: false)
            ->assertSee('support@rigo.bg');

        $this->get(route('legal.contacts'))
            ->assertSee('support@rigo.bg')
            ->assertSee(route('support'), escape: false);
    }
}
