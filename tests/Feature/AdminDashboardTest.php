<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Admin\Dashboard;
use App\Models\Listing;
use App\Models\User;
use App\Services\Admin\Pulse;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „Табло" — the operator's morning screen.
 *
 * THE TESTS THAT MATTER HERE ARE NOT „does the page load". They are the three
 * ways a dashboard lies while looking perfectly healthy:
 *
 *   THE DAY BOUNDARY. `app.timezone` is UTC, so Laravel's `today()` is 03:00
 *   in a Bulgarian summer. A listing published at half past midnight Sofia time
 *   would be counted as yesterday's, every day, for ever, and the number would
 *   look exactly as plausible as the right one.
 *
 *   THE DEAD. Listings are soft-deleted and erased accounts are anonymised
 *   rather than removed. Counted naively, „обяви" includes ones withdrawn
 *   months ago and the user count can never go down.
 *
 *   THE INVENTED. While DemoSeeder's accounts are still in the database every
 *   figure on the screen is fiction, and a screen that does not say so teaches
 *   its operator to distrust it later.
 *
 * And one that is about trust rather than arithmetic: the figures in the
 * account menu and the figures here answer the same questions, so they come
 * from one class. A badge saying 12 over a screen showing 5 is how a queue
 * stops being worked.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE = 'Europe/Sofia';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // The layout renders avatars on every page.
        Storage::fake('public');

        $this->seed(CitySeeder::class);

        /*
         * Frozen, and in the middle of a Sofia afternoon on purpose.
         *
         * Half of this file is about where one local day ends and the next
         * begins, and a test about a boundary that runs at an unknown moment
         * relative to that boundary is a test that passes until it doesn't.
         * Mid-July is also UTC+3 rather than +2, so an off-by-one-hour bug
         * cannot hide inside the winter offset.
         */
        Carbon::setTestNow(Carbon::parse('2026-07-15 14:00', self::ZONE));

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function pulse(): Pulse
    {
        return app(Pulse::class);
    }

    /** A listing published at a given wall-clock moment in Sofia. */
    private function publishedAt(string $sofiaTime): Listing
    {
        return Listing::factory()->create([
            'status'       => ListingStatus::Active,
            'published_at' => Carbon::parse($sofiaTime, self::ZONE)->utc(),
        ]);
    }

    // --- who may look ----------------------------------------------------

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('admin'))->assertRedirect(route('login'));
    }

    /**
     * 404, never 403. An admin screen that confirms its own existence to a
     * stranger is a map of where to attack.
     */
    public function test_an_ordinary_account_gets_a_404_rather_than_a_refusal(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin'))
            ->assertNotFound();
    }

    public function test_an_admin_sees_the_board(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin'))
            ->assertOk()
            ->assertSee('Табло');
    }

    // --- the day boundary ------------------------------------------------

    public function test_the_day_starts_at_local_midnight_and_not_at_midnight_utc(): void
    {
        // 00:30 Sofia is 21:30 UTC *the previous day*. Counted in UTC this
        // listing belongs to yesterday; counted correctly it is today's.
        $this->publishedAt('2026-07-15 00:30');

        $this->assertSame(
            1,
            $this->pulse()->listingsPublishedSince($this->pulse()->startOfToday()),
            'A listing published just after local midnight must count as today.',
        );
    }

    public function test_the_last_half_hour_of_yesterday_is_not_today(): void
    {
        $this->publishedAt('2026-07-14 23:30');

        $pulse = $this->pulse();

        $this->assertSame(0, $pulse->listingsPublishedSince($pulse->startOfToday()));
        $this->assertSame(1, $pulse->listingsPublishedSince($pulse->startOfDays(7)));
    }

    public function test_the_seven_day_window_includes_today(): void
    {
        $this->publishedAt('2026-07-15 09:00');   // today
        $this->publishedAt('2026-07-09 09:00');   // seven days back, inside
        $this->publishedAt('2026-07-08 09:00');   // eight days back, outside

        $this->assertSame(2, $this->pulse()->listingsPublishedSince($this->pulse()->startOfDays(7)));
    }

    public function test_the_daily_series_buckets_by_local_day(): void
    {
        $this->publishedAt('2026-07-15 00:30');   // today, barely
        $this->publishedAt('2026-07-14 23:30');   // yesterday, barely

        $series = collect($this->pulse()->dailySeries(7))->keyBy('date');

        $this->assertCount(7, $series);
        $this->assertSame(1, $series['2026-07-15']['listings']);
        $this->assertSame(1, $series['2026-07-14']['listings']);
    }

    // --- what counts as a thing at all -----------------------------------

    public function test_a_listing_its_seller_withdrew_is_not_counted(): void
    {
        $this->publishedAt('2026-07-15 09:00');
        $this->publishedAt('2026-07-15 10:00')->delete();   // soft delete

        $pulse = $this->pulse();

        $this->assertSame(1, $pulse->listingsPublishedSince($pulse->startOfToday()));
        $this->assertSame(1, $pulse->activeListings());
    }

    public function test_a_draft_that_was_never_published_is_not_counted(): void
    {
        Listing::factory()->create([
            'status'       => ListingStatus::Draft,
            'published_at' => null,
        ]);

        $this->assertSame(0, $this->pulse()->listingsPublishedSince($this->pulse()->startOfDays(7)));
    }

    /**
     * After a GDPR erasure the row survives — it has to, so an enforcement can
     * outlive the account. Counting it would mean the user total can only ever
     * rise, which makes it a vanity figure rather than a fact.
     */
    public function test_an_anonymised_account_is_not_a_user(): void
    {
        $before = $this->pulse()->users();

        User::factory()->create(['anonymised_at' => now()]);

        $this->assertSame($before, $this->pulse()->users());
    }

    public function test_a_new_account_shows_up_today(): void
    {
        $before = $this->pulse()->usersJoinedSince($this->pulse()->startOfToday());

        User::factory()->create();

        $this->assertSame($before + 1, $this->pulse()->usersJoinedSince($this->pulse()->startOfToday()));
    }

    // --- the queues ------------------------------------------------------

    public function test_a_company_awaiting_verification_is_waiting_on_somebody(): void
    {
        $this->assertSame(0, $this->pulse()->traderRequests());

        User::factory()->create(['trader_status' => User::TRADER_PENDING]);

        $this->assertSame(1, $this->pulse()->traderRequests());

        Livewire::actingAs($this->admin)
            ->test(Dashboard::class)
            ->assertSee('Проверка на фирми');
    }

    /**
     * Executes every queue query against the real schema. Worth having on its
     * own: these are six different tables and an enum, and a renamed column
     * would otherwise only surface on the live site.
     */
    public function test_an_empty_site_has_nothing_waiting_and_says_so(): void
    {
        $pulse = $this->pulse();

        $this->assertSame(0, $pulse->moderationQueue());
        $this->assertSame(0, $pulse->pendingPayments());
        $this->assertSame(0, $pulse->openTickets());
        $this->assertSame(0, $pulse->stolenClaims());
        $this->assertSame(0, $pulse->traderRequests());
        $this->assertSame(0, $pulse->uncatalogued());
        $this->assertSame(0, $pulse->openDeals());

        Livewire::actingAs($this->admin)
            ->test(Dashboard::class)
            ->assertSee('Нищо не чака');
    }

    // --- honesty ---------------------------------------------------------

    public function test_the_board_says_so_while_the_numbers_are_demo_data(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Dashboard::class)
            ->assertDontSee('Данните са демо');

        User::factory()->create(['email' => 'demo-plamen@demo.invalid']);

        $this->assertTrue($this->pulse()->hasDemoData());

        Livewire::actingAs($this->admin)
            ->test(Dashboard::class)
            ->assertSee('Данните са демо');
    }

    // --- one source of truth ---------------------------------------------

    /**
     * The account menu and this screen must never give different answers, and
     * the only durable way to guarantee that is for there to be one definition.
     * The layout used to compute these inline; if it ever does again, the two
     * will drift and nobody will notice until a badge disagrees with a screen.
     */
    public function test_the_layout_takes_its_admin_counts_from_pulse(): void
    {
        $layout = (string) file_get_contents(
            resource_path('views/components/layouts/app.blade.php'),
        );

        // Comments in that file discuss these queries, so they come out before
        // the search — otherwise the guard matches its own documentation.
        $code = preg_replace(
            ['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s', '#//.*#'],
            '',
            $layout,
        );

        $this->assertStringContainsString(
            'Admin\Pulse',
            (string) $code,
            'The layout should read its admin badge counts from the Pulse service.',
        );

        foreach ([
            'ModerationItem::queue()',
            'StolenReport::pending()',
            'Payment::pending()',
        ] as $inline) {
            $this->assertStringNotContainsString(
                $inline,
                (string) $code,
                "The layout recomputes {$inline} instead of asking Pulse, so the nav badge "
                .'and the dashboard can drift apart.',
            );
        }
    }
}
