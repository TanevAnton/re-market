<?php

namespace Tests\Feature;

use App\Livewire\BrowseListings;
use App\Livewire\Listings\CreateListing;
use App\Livewire\Valuation;
use App\Models\City;
use App\Models\Part;
use App\Models\User;
use App\Support\RetechOffer;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The offer to sell a graphics card straight to RE-Tech.
 *
 * Three things are worth protecting, in this order.
 *
 * THAT IT SAYS WHOSE IT IS. The shop and the marketplace are one company. The
 * offer is fine with that sentence beside it and a conflict of interest
 * without it, so every placement is checked for the sentence, not just for
 * the link.
 *
 * THAT IT IS OFF UNLESS SOMEBODY TURNED IT ON, and that a bad value in `.env`
 * turns it off rather than putting a broken or hostile link on four pages.
 *
 * THAT IT STAYS WHERE IT BELONGS - beside graphics cards, and nowhere it was
 * not asked for.
 */
class RetechOfferTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS    = 'https://shop.example/pages/izkupuvane';
    private const DISCLOSURE = 'са на една и съща фирма';

    private Part $gpu;
    private Part $cpu;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->gpu = Part::where('model', 'GeForce RTX 3070')->firstOrFail();
        $this->cpu = Part::where('model', 'Ryzen 7 5800X3D')->firstOrFail();

        // phpunit.xml pins the offer OFF for the whole suite. This file is
        // about what happens when it is on, so it says so itself.
        config(['remarket.retech.buyback_url' => self::ADDRESS]);
    }

    /** The link as it is written into an href - an ampersand is an entity there. */
    private function href(string $placement): string
    {
        return 'href="'.e(RetechOffer::url($placement)).'"';
    }

    private function seller(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ])->refresh();
    }

    // --- the address ------------------------------------------------------

    public function test_the_link_says_where_on_the_site_it_was_pressed(): void
    {
        $url = RetechOffer::url('valuation');

        $this->assertStringStartsWith(self::ADDRESS.'?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $tags);

        $this->assertSame('referral', $tags['utm_medium']);
        $this->assertSame('buyback', $tags['utm_campaign']);
        $this->assertSame('valuation', $tags['utm_content']);
        // This site's host, read from config rather than typed in.
        $this->assertSame(parse_url(config('app.url'), PHP_URL_HOST), $tags['utm_source']);
    }

    /** And nothing else: no model, no account, nothing about who pressed it. */
    public function test_the_link_carries_nothing_about_the_visitor(): void
    {
        parse_str((string) parse_url(RetechOffer::url('listing-form'), PHP_URL_QUERY), $tags);

        $this->assertSame(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'], array_keys($tags));
    }

    public function test_an_address_that_already_has_a_query_or_a_fragment_keeps_both(): void
    {
        config(['remarket.retech.buyback_url' => 'https://shop.example/page?ref=a#form']);

        $url = RetechOffer::url('home');

        $this->assertStringStartsWith('https://shop.example/page?ref=a&utm_source=', $url);
        $this->assertStringEndsWith('#form', $url);
    }

    public function test_a_placement_nobody_defined_is_not_passed_through(): void
    {
        parse_str((string) parse_url(RetechOffer::url('"><script>'), PHP_URL_QUERY), $tags);

        $this->assertSame('other', $tags['utm_content']);
    }

    // --- off, and off again when the value is wrong ------------------------

    public function test_with_no_address_nothing_is_shown_anywhere(): void
    {
        config(['remarket.retech.buyback_url' => null]);

        $this->assertFalse(RetechOffer::enabled());
        $this->assertNull(RetechOffer::url('home'));
        $this->assertFalse(RetechOffer::appliesTo('gpu'));

        $this->get(route('home'))->assertOk()->assertDontSee('data-retech-offer', false);
        $this->get(route('browse', ['kat' => 'gpu']))->assertOk()->assertDontSee('data-retech-offer', false);

        Livewire::test(Valuation::class)
            ->call('choose', $this->gpu->slug)
            ->assertDontSeeHtml('data-retech-offer');
    }

    /**
     * The value is typed into `.env` by a person. Anything that is not an
     * https address switches the offer off instead of becoming a link.
     */
    public function test_a_value_that_is_not_an_https_address_switches_it_off(): void
    {
        foreach (['javascript:alert(1)', 'http://shop.example/page', 'shop.example/page', '   ', 'https://'] as $bad) {
            config(['remarket.retech.buyback_url' => $bad]);

            $this->assertFalse(RetechOffer::enabled(), "Accepted: {$bad}");
        }

        $this->get(route('home'))->assertOk()->assertDontSee('data-retech-offer', false);
    }

    // --- the four places --------------------------------------------------

    public function test_the_price_check_page_offers_it_for_a_graphics_card(): void
    {
        Livewire::test(Valuation::class)
            ->call('choose', $this->gpu->slug)
            ->assertSeeHtml('data-retech-offer="valuation"')
            ->assertSeeHtml($this->href('valuation'))
            ->assertSee(self::DISCLOSURE)
            // Beside the site's own ask, never in place of it.
            ->assertSee('Продай я тук');
    }

    public function test_the_price_check_page_does_not_offer_it_for_anything_else(): void
    {
        Livewire::test(Valuation::class)
            ->call('choose', $this->cpu->slug)
            ->assertDontSeeHtml('data-retech-offer');

        // And not before a model is chosen at all.
        Livewire::test(Valuation::class)->assertDontSeeHtml('data-retech-offer');
    }

    public function test_the_listing_form_mentions_it_once_a_graphics_card_is_chosen(): void
    {
        $form = Livewire::actingAs($this->seller())
            ->test(CreateListing::class)
            ->set('category', 'gpu')
            // `step` is locked against the browser, so the form is walked the
            // way a person walks it.
            ->call('next')
            ->assertSet('step', 2);

        // Not while the model is still being searched for.
        $form->assertDontSeeHtml('data-retech-offer');

        $form->call('choosePart', $this->gpu->id)
            ->assertSeeHtml('data-retech-offer="listing-form"')
            ->assertSeeHtml($this->href('listing-form'))
            ->assertSee(self::DISCLOSURE);
    }

    public function test_the_listing_form_says_nothing_for_a_processor(): void
    {
        Livewire::actingAs($this->seller())
            ->test(CreateListing::class)
            ->set('category', 'cpu')
            ->call('next')
            ->assertSet('step', 2)
            ->call('choosePart', $this->cpu->id)
            ->assertDontSeeHtml('data-retech-offer');
    }

    public function test_the_home_page_strip_carries_it(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-retech-offer="home"', false)
            ->assertSee($this->href('home'), false)
            ->assertSee(self::DISCLOSURE);
    }

    public function test_the_graphics_cards_page_carries_it_and_other_categories_do_not(): void
    {
        $this->get(route('browse', ['kat' => 'gpu']))
            ->assertOk()
            ->assertSee('data-retech-offer="gpu-page"', false)
            ->assertSee($this->href('gpu-page'), false)
            ->assertSee(self::DISCLOSURE);

        $this->get(route('browse', ['kat' => 'cpu']))->assertOk()->assertDontSee('data-retech-offer', false);
        $this->get(route('browse'))->assertOk()->assertDontSee('data-retech-offer', false);

        // Switching category in place takes it away too.
        Livewire::test(BrowseListings::class)
            ->set('category', 'gpu')
            ->assertSeeHtml('data-retech-offer="gpu-page"')
            ->set('category', 'ram')
            ->assertDontSeeHtml('data-retech-offer');
    }

    // --- what every placement has to say -----------------------------------

    /** The company named in the disclosure is the one in the legal config, not a typed-in name. */
    public function test_the_disclosure_names_the_company_from_the_legal_config(): void
    {
        config(['legal.entity.name' => 'ZZQ ИЗМИСЛЕНА ФИРМА ООД']);

        $this->get(route('browse', ['kat' => 'gpu']))
            ->assertSee(RetechOffer::name().' и '.config('app.name').' '.self::DISCLOSURE.' — ZZQ ИЗМИСЛЕНА ФИРМА ООД.');
    }

    public function test_the_shop_name_comes_from_config(): void
    {
        config(['remarket.retech.name' => 'ZZPARTNER']);

        $this->get(route('home'))
            ->assertSee('Продай я директно на ZZPARTNER')
            ->assertDontSee('RE-Tech');

        $this->get(route('browse', ['kat' => 'gpu']))
            ->assertSee('ZZPARTNER я изкупува')
            ->assertSee('Към ZZPARTNER')
            ->assertDontSee('RE-Tech');
    }

    /** It leaves the site, so it opens beside it - and without handing the new tab a way back in. */
    public function test_the_link_opens_in_a_new_tab_safely(): void
    {
        $this->get(route('home'))
            ->assertSee($this->href('home').' target="_blank" rel="noopener"', false);
    }

    /**
     * The banner and the home page row say whose it is in two words, where
     * anybody can see them, and keep the full sentence behind those words.
     * Short is fine; hidden is not.
     */
    public function test_the_short_shapes_still_say_it_is_ours_in_plain_sight(): void
    {
        foreach (['row', 'banner'] as $variant) {
            $html = view('partials.retech-buyback', ['placement' => 'home', 'variant' => $variant])->render();

            $this->assertStringContainsString('наш магазин', strip_tags($html), $variant);
            $this->assertStringContainsString('title="'.e(RetechOffer::name().' и '.config('app.name').' '.self::DISCLOSURE), $html, $variant);
        }

        // The two with room for it print the sentence itself.
        foreach (['card', 'line'] as $variant) {
            $html = view('partials.retech-buyback', ['placement' => 'home', 'variant' => $variant])->render();

            $this->assertStringContainsString(self::DISCLOSURE, strip_tags($html), $variant);
        }
    }

    /**
     * One claim: that the shop buys them. A price, a speed or a promise typed
     * in here would go stale on this side without anybody noticing. Checked on
     * the words a visitor reads, in every shape the offer takes.
     */
    public function test_the_offer_promises_nothing_about_price_or_speed(): void
    {
        foreach (['card', 'line', 'row', 'banner'] as $variant) {
            $text = strip_tags(view('partials.retech-buyback', ['placement' => 'home', 'variant' => $variant])->render());

            $this->assertNotSame('', trim($text), $variant);
            $this->assertDoesNotMatchRegularExpression('/€|лв|минут|час|веднага|най-добр|гаранти/u', $text, $variant);
        }
    }
}
