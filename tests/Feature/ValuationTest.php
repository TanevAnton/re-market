<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Valuation;
use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „Колко струва техниката ми" — the catalogue pointed at the seller.
 *
 * Every number on this page already existed; what is new is who is being shown
 * it, and when. Somebody about to sell a card looks up what it is worth BEFORE
 * deciding where to list it, and today that search ends somewhere else.
 *
 * So the behaviour worth protecting is not the arithmetic - that is tested
 * where the percentiles are computed - but the honesty. The page must refuse
 * to produce a number it cannot stand behind: a seller who prices from an
 * invented band and then hears nothing for a month does not come back, and
 * telling them "we do not know yet" costs one visit instead.
 */
class ValuationTest extends TestCase
{
    use RefreshDatabase;

    private Part $part;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        /*
         * Built here rather than taken from PartSeeder. The search assertions
         * below need a model name that matches ONE row: a fragment of a real
         * GPU name ("GeForc", "RTX") matches most of the catalogue, and the
         * suggestion list is capped at twelve - so the test would pass or fail
         * on how many cards happen to be seeded that week.
         */
        $this->part = Part::create([
            'category'     => 'gpu',
            'manufacturer' => 'ASUS',
            'model'        => 'Strix Oracle 9999',
            'slug'         => 'asus-strix-oracle-9999',
            'specs'        => [],
            'is_published' => true,
        ]);
    }

    /**
     * No return type: the Livewire testable class is an internal FQCN and
     * naming it here buys nothing a test needs.
     *
     * @param  array<string, mixed>  $set
     */
    private function page(array $set = [], ?User $as = null)
    {
        $component = $as
            ? Livewire::actingAs($as)->test(Valuation::class)
            : Livewire::test(Valuation::class);

        foreach ($set as $property => $value) {
            $component->set($property, $value);
        }

        return $component;
    }

    private function chosen(?User $as = null)
    {
        return $this->page(['slug' => $this->part->slug], $as);
    }

    private function withBand(int $p25 = 60000, int $median = 75000, int $p75 = 92000): void
    {
        $this->part->forceFill([
            'price_p25_cents'    => $p25,
            'price_median_cents' => $median,
            'price_p75_cents'    => $p75,
            'price_stats_at'     => now(),
        ])->save();
    }

    /** @param  array<string, mixed>  $attributes */
    private function listing(array $attributes = []): Listing
    {
        return Listing::factory()->create([
            'user_id'  => User::factory()->create()->id,
            'part_id'  => $this->part->id,
            'category' => $this->part->category,
            'status'   => ListingStatus::Active,
            'city_id'  => City::first()->id,
            ...$attributes,
        ]);
    }

    // --- reachability -----------------------------------------------------

    /**
     * The whole point. An acquisition page behind a login acquires nobody, and
     * the person it is written for does not have an account yet by definition.
     */
    public function test_a_guest_can_open_it(): void
    {
        $this->get(route('valuation'))->assertOk();
    }

    public function test_a_guest_is_asked_to_register_rather_than_shown_a_dead_button(): void
    {
        $this->chosen()
            ->assertSee('Регистрирай се и публикувай')
            ->assertDontSee('Публикувай обява');
    }

    public function test_a_signed_in_seller_goes_straight_to_the_wizard(): void
    {
        $this->chosen(User::factory()->create())
            ->assertSee('Публикувай обява')
            ->assertDontSee('Регистрирай се');
    }

    // --- finding the model ------------------------------------------------

    public function test_typing_a_model_offers_it(): void
    {
        $this->page(['q' => 'oracle'])->assertSee('Strix Oracle 9999');
    }

    /** One character matches most of the catalogue, which is not a search. */
    public function test_a_single_character_suggests_nothing(): void
    {
        $this->assertTrue($this->page(['q' => 'o'])->viewData('results')->isEmpty());
    }

    public function test_a_miss_says_so_instead_of_showing_an_empty_box(): void
    {
        $this->page(['q' => 'qqqwwweee'])
            ->assertSee('Няма такъв модел в каталога');
    }

    public function test_choosing_a_model_clears_the_search_and_pins_the_model(): void
    {
        $this->page(['q' => 'oracle'])
            ->call('choose', $this->part->slug)
            ->assertSet('slug', $this->part->slug)
            ->assertSet('q', '');
    }

    /**
     * Searching again abandons the chosen model. Without this the old band
     * stays on screen underneath a list of new candidates - two answers on one
     * page, and the wrong one is the one with the big number next to it.
     */
    public function test_searching_again_drops_the_previous_answer(): void
    {
        $this->chosen()->set('q', 'b550')->assertSet('slug', '');
    }

    /** A draft catalogue row is not a page, however it is reached. */
    public function test_an_unpublished_model_has_no_valuation(): void
    {
        $this->withBand();
        $this->part->forceFill(['is_published' => false])->save();

        $this->chosen()
            ->assertSee('Колко струва техниката ми')
            ->assertDontSee('обичайна цена');
    }

    // --- the number, and refusing to invent one ---------------------------

    public function test_the_band_is_said_in_the_seller_s_language(): void
    {
        $this->withBand();

        $this->chosen()
            ->assertSee('ще тръгне бързо')
            ->assertSee('обичайна цена')
            ->assertSee('ако имаш търпение')
            ->assertSee('750 €');
    }

    /** These are asking prices, and a seller who reads them as sale prices
     *  negotiates down from a number that was already optimistic. */
    public function test_the_band_says_what_kind_of_prices_these_are(): void
    {
        $this->withBand();

        $this->chosen()->assertSee('исканите');
    }

    /**
     * The one that matters most. No statistics means no band - not a zero, not
     * a guess from the launch price, and not the median of the two listings
     * that happen to exist.
     */
    public function test_a_model_with_no_statistics_says_so(): void
    {
        $this->chosen()
            ->assertSee('Още няма достатъчно данни')
            ->assertDontSee('обичайна цена');
    }

    /**
     * A stale band is the same failure wearing a timestamp. Hardware prices
     * move in weeks, so a band computed before the cut-off is withheld rather
     * than shown with an apology next to it.
     */
    public function test_a_stale_band_is_withheld_rather_than_captioned(): void
    {
        config(['remarket.parts.price_band_max_age_days' => 7]);

        $this->withBand();
        $this->part->forceFill(['price_stats_at' => now()->subDays(30)])->save();

        $this->chosen()->assertSee('Още няма достатъчно данни');
    }

    // --- the evidence under the number ------------------------------------

    public function test_the_live_asks_are_shown_so_the_number_can_be_checked(): void
    {
        $this->withBand();
        $alive = $this->listing(['title' => 'Активна карта за проверка']);

        $this->chosen()
            ->assertSee('Обявите зад числото')
            ->assertSee($alive->title);
    }

    /** A sold listing is not an asking price any more. */
    public function test_a_sold_listing_is_not_offered_as_evidence(): void
    {
        $this->withBand();
        $gone = $this->listing(['title' => 'Продадена преди месец', 'status' => ListingStatus::Sold]);

        $this->chosen()->assertDontSee($gone->title);
    }

    public function test_the_count_under_the_band_matches_what_is_live(): void
    {
        $this->withBand();
        $this->listing();
        $this->listing();
        $this->listing(['status' => ListingStatus::Sold]);

        $this->assertSame(2, $this->chosen()->viewData('live'));
    }

    // --- what it tells a crawler ------------------------------------------

    /**
     * One indexable page, not one per model.
     *
     * The band and the live asks for a chosen model ARE the /model/ page's
     * content seen from the other side, so the per-model states consolidate
     * into it rather than competing with it for the same query.
     *
     * Asserted through a real request: the canonical tag is rendered by the
     * layout, and a component test never reaches the layout.
     */
    public function test_a_chosen_model_canonicalises_to_the_catalogue_page(): void
    {
        config(['remarket.seo.indexable' => true]);

        $html = $this->get(route('valuation', ['model' => $this->part->slug]))
            ->assertOk()->getContent();

        $this->assertStringContainsString(
            'rel="canonical" href="'.route('part', $this->part).'"',
            $html,
        );
    }

    public function test_the_bare_page_is_its_own_canonical_and_indexable(): void
    {
        config(['remarket.seo.indexable' => true]);

        $html = $this->get(route('valuation'))->assertOk()->getContent();

        $this->assertStringContainsString('rel="canonical" href="'.route('valuation').'"', $html);
        $this->assertStringNotContainsString('name="robots" content="noindex', $html);
    }

    /**
     * A half-typed search is not worth its own entry in an index, and it must
     * not claim to be a model's page either - it does not name one yet.
     */
    public function test_a_search_in_progress_folds_into_the_bare_page(): void
    {
        config(['remarket.seo.indexable' => true]);

        $html = $this->get(route('valuation', ['q' => 'oracle']))->assertOk()->getContent();

        $this->assertStringContainsString('rel="canonical" href="'.route('valuation').'"', $html);
    }

    /** Submitted to Google once, not once per model. */
    public function test_the_sitemap_carries_the_page_exactly_once(): void
    {
        config(['remarket.seo.indexable' => true]);

        $xml = $this->get(route('sitemap'))->assertOk()->getContent();

        $this->assertStringContainsString('<loc>'.route('valuation').'</loc>', $xml);
        $this->assertSame(1, substr_count($xml, route('valuation')));
    }
}
