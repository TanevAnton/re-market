<?php

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Models\City;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The header, which had quietly become eleven items of equal weight.
 *
 * A bar where everything is top-level says everything matters equally, which
 * means nothing does — and it had run out of room: uppercase Cyrillic with
 * letter-spacing is about a third wider than the sentence case the labels were
 * written in, so „Колко струва?" and „Моите обяви" were breaking mid-phrase.
 *
 * The fix is structural rather than cosmetic. What the SITE is stays in the
 * bar; what belongs to ONE PERSON moved into an account menu. The tests below
 * are mostly about the two things that can silently go wrong afterwards:
 * something becoming unreachable, and a count going unnoticed because it now
 * lives behind a click.
 */
class HeaderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        $this->user = User::factory()->create();
    }

    /**
     * Only the header markup.
     *
     * Scoped deliberately: the page underneath renders listing cards with
     * badges of their own, and a test that counted those would pass or fail on
     * what happened to be for sale that run.
     */
    private function header(?User $as = null): string
    {
        $request = $as ? $this->actingAs($as) : $this;
        $html    = $request->get(route('home'))->assertOk()->getContent();

        $start = strpos($html, '<header');
        $end   = strpos($html, '</header>', $start);

        $this->assertNotFalse($start, 'the page rendered no header at all');

        return substr($html, $start, $end - $start);
    }

    // --- nothing became unreachable ---------------------------------------

    /**
     * The whole risk of a menu: a page that was one click away is now zero
     * clicks away from nowhere. Every destination still has to be IN the
     * markup — the menu is hidden with CSS, not withheld from the page.
     */
    public function test_every_destination_is_still_in_the_header(): void
    {
        $html = $this->header($this->user);

        foreach ([
            'browse', 'valuation', 'offers', 'messages', 'deals',
            'listings.mine', 'favorites', 'searches', 'profile.edit', 'listing.create',
        ] as $name) {
            $this->assertStringContainsString(
                'href="'.route($name).'"',
                $html,
                "[{$name}] is no longer reachable from the header",
            );
        }
    }

    /**
     * Two pages the old header did not link to at all. It was simultaneously
     * overcrowded and missing its own settings screen, which is what an
     * overflowing bar does: the last things added never fit.
     */
    public function test_the_menu_adds_the_pages_the_bar_had_no_room_for(): void
    {
        $html = $this->header($this->user);

        $this->assertStringContainsString('href="'.route('searches').'"', $html);
        $this->assertStringContainsString('href="'.route('profile.edit').'"', $html);
    }

    /**
     * A phone used to get no navigation whatsoever: the nav was `hidden
     * md:flex` and there was nothing else. A guest could not reach the
     * listings from a phone at all.
     */
    public function test_a_guest_can_still_reach_the_listings(): void
    {
        $html = $this->header();

        $this->assertStringContainsString('href="'.route('browse').'"', $html);
        $this->assertStringContainsString('href="'.route('valuation').'"', $html);
        $this->assertStringContainsString('href="'.route('login').'"', $html);
        $this->assertStringContainsString('href="'.route('register').'"', $html);
    }

    /** A guest must not be offered anything that needs an account. */
    public function test_a_guest_gets_no_account_menu(): void
    {
        $html = $this->header();

        $this->assertStringNotContainsString('href="'.route('deals').'"', $html);
        $this->assertStringNotContainsString(route('logout'), $html);
    }

    // --- nothing goes unnoticed behind the click --------------------------

    /**
     * The reason the roll-up exists. Deals moved inside the menu, and a count
     * nobody can see is a count nobody acts on — on this site that is a deal
     * lapsing at 72 hours and the user's own completion rate paying for it.
     */
    public function test_a_count_hidden_in_the_menu_shows_on_the_closed_menu(): void
    {
        $this->openDeal();

        $html = $this->header($this->user);

        // Twice: once inside the menu next to „Сделки", once on the closed
        // trigger. Counted rather than matched against exact markup, so
        // reordering a class list does not fail a test about behaviour.
        $this->assertSame(2, substr_count($html, 'badge-accent'));
        $this->assertSame(2, substr_count($html, '>1</span>'));
    }

    public function test_nothing_waiting_means_no_badge_at_all(): void
    {
        $html = $this->header($this->user);

        $this->assertStringNotContainsString('badge-accent', $html);
    }

    /**
     * The roll-up counts only what somebody is WAITING on.
     *
     * The catalogue queue is work that is available rather than work that is
     * late, so it wears a neutral badge and stays out of the number. A roll-up
     * that never reaches zero is a dot that stops being read, and then the
     * lapsing deal it was supposed to surface goes unnoticed too.
     */
    public function test_the_catalogue_queue_does_not_inflate_the_roll_up(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Listing::factory()->create([
            'user_id'     => $this->user->id,
            'part_id'     => null,
            'custom_part' => 'RTX 4070',
            'category'    => 'gpu',
            'status'      => ListingStatus::Active,
            'city_id'     => City::first()->id,
        ]);

        $html = $this->header($admin);

        // The neutral badge is there, next to „Каталог"...
        $this->assertStringContainsString('badge-neutral', $html);
        // ...and the trigger stays quiet, because nobody is waiting.
        $this->assertStringNotContainsString('badge-accent', $html);
    }

    /** Admin-only entries stay admin-only, menu or no menu. */
    public function test_the_admin_queues_are_not_offered_to_everyone(): void
    {
        $this->assertStringNotContainsString('href="'.route('catalogue').'"', $this->header($this->user));

        $admin = User::factory()->create(['is_admin' => true]);

        $this->assertStringContainsString('href="'.route('catalogue').'"', $this->header($admin));
        $this->assertStringContainsString('href="'.route('moderation').'"', $this->header($admin));
    }

    // --- the wrapping itself ----------------------------------------------

    /**
     * The defect under the cramped header, and it was never only the header:
     * any two-word button on the site could break mid-phrase, because no
     * button variant set `white-space`. „Колко струва?" on two lines is what
     * made this visible, not what made it wrong.
     */
    public function test_no_button_variant_can_break_a_label_mid_phrase(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach (['.btn', '.btn-primary', '.btn-secondary', '.btn-ghost'] as $variant) {
            /*
             * Anchored to the start of a line, which is the whole trick here.
             * A plain search for „.btn-secondary {" finds
             * „.site-header .btn-secondary {" first — the override sits above
             * the definitions in the file, and a descendant selector contains
             * the component selector as a substring. The first version of this
             * test read the override's four-property block, found no
             * whitespace-nowrap in it, and failed on correct CSS.
             */
            $found = preg_match(
                '/^[ \t]*'.preg_quote($variant, '/').' \{\s*@apply[^;]*;/m',
                $css,
                $match,
            );

            $this->assertSame(1, $found, "{$variant} has no @apply block of its own");

            $this->assertStringContainsString(
                'whitespace-nowrap',
                $match[0],
                "{$variant} can still wrap a label mid-phrase",
            );
        }
    }

    /**
     * A panel opens toward the side that has room.
     *
     * The guest trigger sits against the left edge of the bar, so its panel
     * must be anchored left; the account trigger is the last thing in the bar,
     * so its panel is anchored right. Anchored the wrong way, a 208px panel on
     * a 411px phone hangs off the screen — which is exactly what happened, and
     * only a screenshot caught it.
     *
     * Asserting class names is a weak proxy for a layout, and it is here
     * because the alternative is no guard at all: nothing else in the suite
     * can see a panel leave the viewport.
     */
    public function test_each_menu_opens_toward_the_side_that_has_room(): void
    {
        $guest = $this->header();
        $auth  = $this->header($this->user);

        $this->assertStringContainsString('absolute left-0', $guest,
            'the guest menu is not left-anchored, so on a phone it opens off the left edge');
        $this->assertStringNotContainsString('absolute right-0', $guest);

        $this->assertStringContainsString('absolute right-0', $auth,
            'the account menu is not right-anchored, so it opens off the right edge');
    }

    /** Neither panel may be wider than the screen it opens on. */
    public function test_neither_menu_can_be_wider_than_the_viewport(): void
    {
        foreach ([$this->header(), $this->header($this->user)] as $html) {
            preg_match_all('/class="card absolute[^"]*"/', $html, $panels);

            $this->assertNotEmpty($panels[0], 'no dropdown panel found in the header');

            foreach ($panels[0] as $panel) {
                $this->assertStringContainsString('max-w-[calc(100vw-2rem)]', $panel);
            }
        }
    }

    private function openDeal(): void
    {
        $listing = Listing::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status'  => ListingStatus::Reserved,
            'city_id' => City::first()->id,
        ]);

        Deal::create([
            'listing_id'         => $listing->id,
            'buyer_id'           => $this->user->id,
            'seller_id'          => $listing->user_id,
            'agreed_price_cents' => $listing->price_cents,
            'status'             => DealStatus::Open,
            'expires_at'         => now()->addHours(72),
        ]);
    }
}
