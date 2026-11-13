<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\WantedStatus;
use App\Livewire\Home;
use App\Livewire\HomeSearch;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Models\WantedAd;
use App\Support\GpuSeries;
use App\Support\SpecFilter;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The front page while the site is young.
 *
 * HomeTest protects that the page tells the truth. This file protects the five
 * things added so that a nearly empty marketplace still gives a first visitor
 * somewhere to go: no wall of zeros, an answer for the buyer who found nothing,
 * real requests from real buyers, a search that knows the catalogue, and
 * shortcuts that cannot lead to an empty page.
 */
class HomeFrontDoorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::factory()->create($attributes + [
            'user_id'         => User::factory()->create()->id,
            'status'          => ListingStatus::Active,
            'min_offer_cents' => null,
        ]);
    }

    /**
     * Written straight to the table. `status` is deliberately not fillable and
     * WantedService owns every transition - which is right for the site and
     * beside the point here, where the question is only what the page shows
     * for a row in a given state.
     */
    private function wanted(array $attributes = []): WantedAd
    {
        $ad = new WantedAd;

        $ad->forceFill($attributes + [
            'user_id'          => User::factory()->create()->id,
            'category'         => 'gpu',
            'title'            => 'Търся RTX 3060 Ti',
            'budget_max_cents' => 22000,
            'status'           => WantedStatus::Active,
            'expires_at'       => now()->addDays(30),
        ])->save();

        return $ad;
    }

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    // --- category tiles ---------------------------------------------------

    /**
     * Every category is still on the page - HomeTest holds that line - but a
     * grid of nineteen zeros reads as a dead site, so an empty one carries no
     * number at all.
     */
    public function test_an_empty_category_shows_no_number(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(SpecFilter::categoryLabel('monitor'))
            ->assertDontSee('data-tile-count', false);
    }

    public function test_a_category_with_listings_shows_how_many(): void
    {
        $this->listing(['category' => 'gpu']);
        $this->listing(['category' => 'gpu']);

        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-tile-count="2"', $html);
        // One category has stock, so one tile has a number.
        $this->assertSame(1, substr_count($html, 'data-tile-count='));
    }

    // --- the sell strip ---------------------------------------------------

    public function test_the_strip_answers_the_buyer_as_well_as_the_seller(): void
    {
        $this->get(route('home'))
            ->assertSee('Пусни обява — безплатно')
            ->assertSee('Не намираш каквото търсиш?')
            ->assertSee('Пусни „Търся“');
    }

    /**
     * Where one of the strip's two buttons points.
     *
     * Read off the button itself rather than searched for across the page: the
     * header and the footer link to some of the same places, and a test that
     * finds „a link to register somewhere" proves nothing about this strip.
     */
    private function stripLink(string $html, string $label): string
    {
        $found = preg_match('/<a href="([^"]+)"[^>]*>'.preg_quote($label, '/').'<\/a>/u', $html, $m);

        $this->assertSame(1, $found, "No button labelled {$label}.");

        return html_entity_decode($m[1]);
    }

    /** Both buttons need an account, so a guest is sent to make one. */
    public function test_a_guest_is_sent_to_register_from_both_buttons(): void
    {
        $html = $this->get(route('home'))->getContent();

        $this->assertSame(route('register'), $this->stripLink($html, 'Пусни обява — безплатно'));
        $this->assertSame(route('register'), $this->stripLink($html, 'Пусни „Търся“'));
    }

    public function test_a_signed_in_user_goes_straight_to_each_form(): void
    {
        $html = $this->actingAs($this->verifiedUser())->get(route('home'))->assertOk()->getContent();

        $this->assertSame(route('listing.create'), $this->stripLink($html, 'Пусни обява — безплатно'));
        $this->assertSame(route('wanted.create'), $this->stripLink($html, 'Пусни „Търся“'));
    }

    // --- what buyers are asking for ---------------------------------------

    /** A heading over an empty row would only repeat the strip above it. */
    public function test_the_wanted_block_is_absent_while_nobody_is_asking(): void
    {
        $this->assertCount(0, Livewire::test(Home::class)->instance()->wanted());

        $this->get(route('home'))->assertDontSee('Купувачи търсят');
    }

    public function test_an_active_request_reaches_the_front_page(): void
    {
        $ad = $this->wanted(['title' => 'Търся видеокарта за 1080p']);

        $this->get(route('home'))
            ->assertSee('Купувачи търсят')
            ->assertSee('Търся видеокарта за 1080p')
            ->assertSee('href="'.route('wanted.show', $ad).'"', false)
            ->assertSee('href="'.route('wanted').'"', false);
    }

    /**
     * The most public page on the site. A request still waiting for review
     * appearing here would defeat the moderation gate, the same as a listing.
     */
    public function test_a_request_awaiting_review_never_reaches_the_front_page(): void
    {
        $this->wanted(['title' => 'Чака преглед', 'status' => WantedStatus::PendingReview]);

        $this->assertCount(0, Livewire::test(Home::class)->instance()->wanted());
        $this->get(route('home'))->assertDontSee('Чака преглед');
    }

    /** Expiry is checked, not trusted from the status column. */
    public function test_an_expired_request_drops_off_before_the_sweeper_runs(): void
    {
        $this->wanted(['title' => 'Изтекло търсене', 'expires_at' => now()->subDay()]);

        $this->assertCount(0, Livewire::test(Home::class)->instance()->wanted());
    }

    public function test_the_newest_requests_come_first_and_the_row_is_capped(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->wanted(['title' => "Търсене {$i}"]);
        }

        $wanted = Livewire::test(Home::class)->instance()->wanted();

        $this->assertCount(4, $wanted);
        $this->assertSame('Търсене 6', $wanted->first()->title);
        $this->assertSame('Търсене 3', $wanted->last()->title);
    }

    /** The card reads the city, the model and the answer count - loaded once, not per card. */
    public function test_the_wanted_row_loads_what_its_card_reads(): void
    {
        $this->wanted(['part_id' => Part::where('category', 'gpu')->firstOrFail()->id]);

        $ad = Livewire::test(Home::class)->instance()->wanted()->first();

        $this->assertTrue($ad->relationLoaded('city'));
        $this->assertTrue($ad->relationLoaded('part'));
        $this->assertSame(0, (int) $ad->response_count);
    }

    // --- search suggestions -----------------------------------------------

    public function test_the_search_on_the_home_page_is_the_suggesting_one(): void
    {
        $this->get(route('home'))
            ->assertSeeLivewire(HomeSearch::class)
            // And it is still an ordinary form underneath.
            ->assertSee('action="'.route('browse').'"', false)
            ->assertSee('name="q"', false);
    }

    public function test_typing_a_model_offers_its_catalogue_page(): void
    {
        $part = Part::where('model', 'GeForce RTX 4090')->firstOrFail();

        $search = Livewire::test(HomeSearch::class)->set('q', '4090');

        $this->assertSame($part->id, $search->instance()->suggestions()->first()->id);

        $search->assertSeeHtml('href="'.route('part', $part).'"')
            ->assertSee($part->fullName());
    }

    /** The same matcher as everywhere else, so the same spellings work. */
    public function test_cyrillic_and_mistyped_queries_find_the_model(): void
    {
        $cyrillic = Livewire::test(HomeSearch::class)->set('q', 'ртх 4090')->instance()->suggestions();
        $typo     = Livewire::test(HomeSearch::class)->set('q', 'rtx 4o7o')->instance()->suggestions();

        $this->assertSame('GeForce RTX 4090', $cyrillic->first()->model);
        $this->assertSame('GeForce RTX 4070', $typo->first()->model);
    }

    public function test_one_letter_is_not_a_search(): void
    {
        $search = Livewire::test(HomeSearch::class)->set('q', 'r');

        $this->assertCount(0, $search->instance()->suggestions());
        $search->assertDontSeeHtml('id="home-suggestions"');
    }

    public function test_nothing_typed_shows_no_list(): void
    {
        Livewire::test(HomeSearch::class)->assertDontSeeHtml('id="home-suggestions"');
    }

    public function test_the_list_is_short(): void
    {
        $suggestions = Livewire::test(HomeSearch::class)->set('q', 'rtx')->instance()->suggestions();

        $this->assertNotEmpty($suggestions);
        $this->assertLessThanOrEqual(6, $suggestions->count());
    }

    /**
     * The list is never a dead end: when the catalogue has no such model, the
     * one row left is the search across the listings, spelled out.
     */
    public function test_a_query_the_catalogue_does_not_know_still_offers_the_search(): void
    {
        $search = Livewire::test(HomeSearch::class)->set('q', 'zzqqxxww');

        $this->assertCount(0, $search->instance()->suggestions());

        $search->assertSeeHtml('id="home-suggestions"')
            ->assertSee('във всички обяви')
            ->assertSee('zzqqxxww');
    }

    /** An unpublished model has no page to send anybody to. */
    public function test_an_unpublished_model_is_not_suggested(): void
    {
        Part::where('model', 'GeForce RTX 4090')->update(['is_published' => false]);

        $models = Livewire::test(HomeSearch::class)->set('q', '4090')
            ->instance()->suggestions()->pluck('model');

        $this->assertNotContains('GeForce RTX 4090', $models->all());
    }

    public function test_a_suggestion_says_how_many_are_for_sale_and_only_counts_live_ones(): void
    {
        $part = Part::where('model', 'GeForce RTX 4090')->firstOrFail();

        $this->listing(['part_id' => $part->id, 'category' => 'gpu']);
        $this->listing(['part_id' => $part->id, 'category' => 'gpu']);
        $this->listing(['part_id' => $part->id, 'category' => 'gpu', 'status' => ListingStatus::Draft]);

        $search = Livewire::test(HomeSearch::class)->set('q', '4090');

        $this->assertSame(2, (int) $search->instance()->suggestions()->first()->live_count);
        $search->assertSee('2 обяви');
    }

    /** What is typed goes into the page, so it goes in escaped. */
    public function test_the_typed_text_is_escaped(): void
    {
        Livewire::test(HomeSearch::class)
            ->set('q', '<script>alert(1)</script>')
            ->assertDontSeeHtml('<script>alert(1)</script>');
    }

    // --- shortcuts --------------------------------------------------------

    public function test_the_shortcuts_point_at_series_that_exist(): void
    {
        $links = Livewire::test(HomeSearch::class)->instance()->quickLinks();

        $this->assertNotEmpty($links);

        foreach ($links as $link) {
            parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $query);

            $this->assertSame('gpu', $query['kat']);
            $this->assertNotNull(GpuSeries::find($query['seriya']), "{$query['seriya']} is not a series.");
            $this->assertSame(GpuSeries::find($query['seriya'])['label'], $link['label']);
        }
    }

    public function test_the_shortcuts_are_on_the_home_page(): void
    {
        $url = route('browse', ['kat' => 'gpu', 'seriya' => 'rtx-30']);

        // Escaped, because that is how an ampersand is written in an href.
        $this->get(route('home'))->assertSee('href="'.e($url).'"', false);
    }

    /** And the page the shortcut leads to opens on that series. */
    public function test_a_shortcut_lands_on_its_series(): void
    {
        $this->get(route('browse', ['kat' => 'gpu', 'seriya' => 'rtx-30']))
            ->assertOk()
            ->assertSee('Серия: RTX 30');
    }
}
