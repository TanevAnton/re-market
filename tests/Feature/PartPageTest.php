<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\ShowPart;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The catalogue landing pages and the sitemap that gets them crawled.
 *
 * The tests worth having here are about what the page refuses to claim. A
 * landing page that invents a market price, or offers that do not exist, is
 * worse than no landing page - it ranks, gets quoted in a negotiation, and is
 * wrong.
 */
class PartPageTest extends TestCase
{
    use RefreshDatabase;

    private Part $part;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller = User::factory()->create();
        $this->part   = Part::query()->where('is_published', true)->firstOrFail();
    }

    private function listing(int $priceCents, string $status = 'active'): Listing
    {
        return Listing::factory()->create([
            'user_id'         => $this->seller->id,
            'part_id'         => $this->part->id,
            'category'        => $this->part->category,
            'status'          => ListingStatus::from($status),
            'price_cents'     => $priceCents,
            'min_offer_cents' => null,
        ]);
    }

    // --- the page --------------------------------------------------------

    public function test_the_page_renders_for_a_part_with_no_listings(): void
    {
        // The case that matters most: someone arrives from a search for a model
        // nobody is selling this week. If this 404s or renders empty, the SEO
        // play is worthless precisely when it would pay.
        Livewire::test(ShowPart::class, ['part' => $this->part])
            ->assertOk()
            ->assertSee($this->part->model)
            ->assertSee('няма активни обяви');
    }

    public function test_an_unpublished_part_is_not_a_page(): void
    {
        // Built by hand: there is no PartFactory, and the catalogue is seeded
        // rather than generated - parts are real hardware, not fixtures.
        $draft = Part::create([
            'category'     => 'gpu',
            'manufacturer' => 'NVIDIA',
            'model'        => 'GeForce RTX 9090 Ti',
            'slug'         => 'test-unpublished-part',
            'is_published' => false,
        ]);

        Livewire::test(ShowPart::class, ['part' => $draft])->assertNotFound();
    }

    public function test_live_listings_appear_on_the_page(): void
    {
        $listing = $this->listing(50000);

        Livewire::test(ShowPart::class, ['part' => $this->part])
            ->assertSee($listing->title);
    }

    // --- the price band --------------------------------------------------

    public function test_no_price_band_without_enough_listings(): void
    {
        config(['remarket.parts.price_band_min_listings' => 3]);

        $this->listing(50000);
        $this->artisan('remarket:refresh-part-stats')->assertSuccessful();

        // One person's asking price is not a market price, and printing it as
        // one is the kind of wrong that gets repeated back at us.
        $this->assertNull($this->part->fresh()->price_median_cents);
    }

    public function test_the_band_is_computed_from_active_listings(): void
    {
        config(['remarket.parts.price_band_min_listings' => 3]);

        foreach ([40000, 50000, 60000] as $price) {
            $this->listing($price);
        }

        $this->artisan('remarket:refresh-part-stats')->assertSuccessful();

        $fresh = $this->part->fresh();

        $this->assertSame(50000, $fresh->price_median_cents);
        $this->assertSame(3, $fresh->active_listings_count);

        Livewire::test(ShowPart::class, ['part' => $fresh])
            ->assertSee('Пазарна цена');
    }

    /**
     * The median must not be the mean. One seller asking retail for a
     * three-year-old card would drag an average far enough to be useless, and
     * the long right tail is normal on a used marketplace rather than an
     * anomaly to be cleaned up.
     */
    public function test_one_absurd_asking_price_does_not_move_the_median(): void
    {
        config(['remarket.parts.price_band_min_listings' => 3]);

        foreach ([40000, 50000, 60000, 900000] as $price) {
            $this->listing($price);
        }

        $this->artisan('remarket:refresh-part-stats');

        $median = $this->part->fresh()->price_median_cents;

        $this->assertSame(55000, $median);
        $this->assertLessThan(262500, $median, 'The mean would be 262 500 - this must not be a mean.');
    }

    public function test_a_stale_band_is_withheld_rather_than_shown(): void
    {
        config([
            'remarket.parts.price_band_min_listings' => 1,
            'remarket.parts.price_band_max_age_days' => 7,
        ]);

        $this->listing(50000);
        $this->artisan('remarket:refresh-part-stats');

        $this->part->fresh()->forceFill(['price_stats_at' => now()->subDays(30)])->save();

        // A stale median looks current, gets quoted in negotiations, and
        // nothing on the page says how old it is. Withholding beats guessing.
        $component = Livewire::test(ShowPart::class, ['part' => $this->part->fresh()]);

        $this->assertNull($component->instance()->priceBand());
    }

    public function test_the_band_is_cleared_when_the_listings_go_away(): void
    {
        config(['remarket.parts.price_band_min_listings' => 3]);

        $listings = collect([40000, 50000, 60000])->map(fn ($p) => $this->listing($p));
        $this->artisan('remarket:refresh-part-stats');
        $this->assertNotNull($this->part->fresh()->price_median_cents);

        $listings->each(fn (Listing $l) => $l->forceFill(['status' => ListingStatus::Sold])->save());
        $this->artisan('remarket:refresh-part-stats');

        // Not left at yesterday's figure: a part that sold out keeps reporting
        // a live market that no longer exists.
        $this->assertNull($this->part->fresh()->price_median_cents);
        $this->assertSame(0, $this->part->fresh()->active_listings_count);
    }

    // --- structured data -------------------------------------------------

    public function test_no_offers_are_claimed_when_nothing_is_for_sale(): void
    {
        $schema = json_decode(
            Livewire::test(ShowPart::class, ['part' => $this->part])->instance()->jsonLd(),
            true,
        );

        $this->assertSame('Product', $schema['@type']);

        // Declaring offers that do not exist earns a manual penalty rather
        // than a rich result, and it is a lie told to a machine on our behalf.
        $this->assertArrayNotHasKey('offers', $schema);
    }

    public function test_the_offer_range_matches_the_live_listings(): void
    {
        $this->listing(40000);
        $this->listing(60000);
        $this->listing(99000, 'sold');

        $schema = json_decode(
            Livewire::test(ShowPart::class, ['part' => $this->part->fresh()])->instance()->jsonLd(),
            true,
        );

        // assertEquals, not assertSame: json_encode writes a float with no
        // fractional part as `400`, so it decodes back as an int. The value is
        // right; the type is an artefact of the round trip.
        $this->assertSame(2, $schema['offers']['offerCount']);
        $this->assertEquals(400, $schema['offers']['lowPrice']);
        $this->assertEquals(600, $schema['offers']['highPrice']);
    }

    public function test_the_meta_description_is_not_boilerplate(): void
    {
        $empty = Livewire::test(ShowPart::class, ['part' => $this->part])
            ->instance()->metaDescription();

        $this->listing(40000);
        $this->listing(60000);

        $stocked = Livewire::test(ShowPart::class, ['part' => $this->part->fresh()])
            ->instance()->metaDescription();

        // One description repeated across ten thousand catalogue pages is read
        // as boilerplate and ignored - rightly, since it says nothing about the
        // page it is on.
        $this->assertNotSame($empty, $stocked);
        $this->assertStringContainsString($this->part->model, $empty);
    }

    // --- the sitemap -----------------------------------------------------

    public function test_the_sitemap_is_refused_when_the_deployment_is_not_indexable(): void
    {
        config(['remarket.seo.indexable' => false]);

        // A LAN box or staging copy handing a crawler a map of itself would
        // compete with the real domain for the real domain's own content.
        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_the_sitemap_lists_parts_that_have_listings(): void
    {
        config([
            'remarket.seo.indexable' => true,
            'remarket.parts.sitemap_min_listings' => 1,
        ]);

        $this->listing(50000);
        $this->artisan('remarket:refresh-part-stats');

        $response = $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $response->assertSee(route('part', $this->part), escape: false);
        $response->assertSee(route('home'), escape: false);
    }

    public function test_empty_parts_stay_out_of_the_sitemap(): void
    {
        config([
            'remarket.seo.indexable' => true,
            'remarket.parts.sitemap_min_listings' => 1,
        ]);

        $this->artisan('remarket:refresh-part-stats');

        // Submitting thousands of empty pages teaches a crawler the site is
        // thin, and thin sites get crawled less. The page still works for
        // anyone with the link.
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee(route('part', $this->part), escape: false);
    }

    public function test_pages_are_noindex_until_the_domain_is_live(): void
    {
        config(['remarket.seo.indexable' => false]);

        $this->get(route('part', $this->part))
            ->assertOk()
            ->assertSee('noindex', escape: false);
    }
}
