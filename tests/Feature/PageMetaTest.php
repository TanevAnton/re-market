<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Listings\CreateListing;
use App\Livewire\ShowListing;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Titles, share previews and what each page asks to be indexed as.
 *
 * Every listing pasted into Viber or a Telegram group was rendering as a bare
 * link with no photo and the title "RE-MARKET" - on a marketplace whose growth
 * is word of mouth, that is the cheapest thing on the site to have got wrong.
 */
class PageMetaTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        // Indexable, because the point of these tests is what a crawler is
        // told. Unindexable is its own test at the bottom.
        config(['remarket.seo.indexable' => true]);

        $this->seller  = User::factory()->create();
        $this->listing = Listing::factory()->create([
            'user_id'         => $this->seller->id,
            'status'          => ListingStatus::Active,
            'price_cents'     => 52000,
            'min_offer_cents' => null,
        ]);
    }

    private function withPhoto(): ListingImage
    {
        return ListingImage::create([
            'listing_id' => $this->listing->id,
            'path'       => 'listings/cover.jpg',
            'position'   => 0,
        ]);
    }

    // --- listings --------------------------------------------------------

    public function test_a_listing_page_carries_its_own_title_and_description(): void
    {
        $response = $this->get(route('listing', $this->listing));

        $response->assertOk();
        $response->assertSee('<title>'.e($this->listing->title).' · ', false);
        $response->assertSee('name="description"', false);
        $response->assertSee('rel="canonical" href="'.route('listing', $this->listing).'"', false);
    }

    public function test_a_shared_listing_shows_its_photo(): void
    {
        $image = $this->withPhoto();

        $this->get(route('listing', $this->listing))
            ->assertSee('property="og:image" content="'.$image->url().'"', false)
            ->assertSee('name="twitter:card" content="summary_large_image"', false);
    }

    /**
     * A declared og:image that 404s renders as a broken card rather than a
     * plain one, so a listing with no photo must not declare one at all.
     */
    public function test_a_listing_with_no_photo_declares_no_image(): void
    {
        $this->get(route('listing', $this->listing))
            ->assertDontSee('property="og:image"', false)
            ->assertSee('name="twitter:card" content="summary"', false);
    }

    public function test_the_description_leads_with_the_facts_a_buyer_decides_on(): void
    {
        $description = Livewire::test(ShowListing::class, ['listing' => $this->listing])
            ->instance()->metaDescription();

        // The seller's own words are as often "спешно!!!" as they are useful,
        // so condition and price come first.
        $this->assertStringContainsString($this->listing->condition->label(), $description);
        $this->assertStringContainsString($this->listing->formattedPrice(), $description);
    }

    public function test_an_active_listing_declares_an_offer(): void
    {
        $schema = json_decode(
            Livewire::test(ShowListing::class, ['listing' => $this->listing])->instance()->jsonLd(),
            true,
        );

        $this->assertSame('Product', $schema['@type']);
        $this->assertEquals(520, $schema['offers']['price']);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
    }

    /**
     * Reserved, not sold - and that is the whole point of the case.
     *
     * A sold listing is not publicly visible, so nothing ever reads its
     * structured data. Reserved IS visible: a deal is in flight, the page is
     * still up and still gets crawled, and it is the one state where marking
     * the item InStock would be a claim about availability that the page
     * itself contradicts a few lines further down.
     */
    public function test_a_reserved_listing_is_visible_but_declares_no_offer(): void
    {
        $this->listing->forceFill(['status' => ListingStatus::Reserved])->save();

        $component = Livewire::test(ShowListing::class, ['listing' => $this->listing->fresh()]);

        $component->assertOk();

        $schema = json_decode($component->instance()->jsonLd(), true);

        $this->assertSame('Product', $schema['@type']);
        $this->assertArrayNotHasKey('offers', $schema);
    }

    /**
     * And the sold case, from the only side that can still see it. The seller
     * keeps access to their own listing after it ends; the public gets a 404,
     * which is why the reserved test above is the one about crawlers.
     */
    public function test_a_sold_listing_is_gone_for_the_public(): void
    {
        $this->listing->forceFill(['status' => ListingStatus::Sold])->save();

        Livewire::test(ShowListing::class, ['listing' => $this->listing->fresh()])
            ->assertNotFound();

        Livewire::actingAs($this->seller)
            ->test(ShowListing::class, ['listing' => $this->listing->fresh()])
            ->assertOk();
    }

    // --- browse ----------------------------------------------------------

    public function test_the_category_view_is_indexable_and_canonical_to_itself(): void
    {
        $response = $this->get(route('browse', ['kat' => 'gpu']));

        $response->assertOk();
        $response->assertSee('rel="canonical" href="'.route('browse', ['kat' => 'gpu']).'"', false);
        $response->assertDontSee('name="robots"', false);
    }

    /**
     * Filters multiply into an unbounded number of URLs showing the same
     * listings. Left alone they compete with each other and none of them ranks.
     */
    public function test_a_filtered_slice_is_noindex_but_still_followed(): void
    {
        $response = $this->get(route('browse', ['kat' => 'gpu', 'do' => '400']));

        $response->assertOk();

        // follow, deliberately: the page should not rank, but the listings it
        // links to should still be found through it.
        $response->assertSee('content="noindex, follow"', false);
        $response->assertSee('rel="canonical" href="'.route('browse', ['kat' => 'gpu']).'"', false);
    }

    // --- profiles --------------------------------------------------------

    public function test_a_profile_is_named_but_kept_out_of_the_index(): void
    {
        $response = $this->get(route('profile', $this->seller->username));

        $response->assertOk();
        $response->assertSee($this->seller->username);

        // A person's trading history is not what should rank for a hardware
        // search, and it is not ours to put in one somebody else typed.
        $response->assertSee('content="noindex, follow"', false);
    }

    // --- the deployment guard --------------------------------------------

    public function test_nothing_is_indexable_until_the_domain_is_live(): void
    {
        config(['remarket.seo.indexable' => false]);

        $this->get(route('listing', $this->listing))
            ->assertOk()
            ->assertSee('content="noindex, nofollow"', false);
    }

    // --- the posting wizard ----------------------------------------------

    public function test_the_optional_half_of_step_three_starts_folded(): void
    {
        $verified = User::factory()->create();

        Livewire::actingAs($verified)
            ->test(CreateListing::class)
            ->assertSet('showOptional', false)
            ->call('toggleOptional')
            ->assertSet('showOptional', true);
    }

    /**
     * A long step where the failing field is off screen makes the button look
     * broken. The event is what scrolls the page to the error.
     */
    public function test_a_failed_step_announces_itself(): void
    {
        $verified = User::factory()->create();

        Livewire::actingAs($verified)
            ->test(CreateListing::class)
            ->set('step', 3)
            ->set('title', 'кратко')
            ->call('next')
            ->assertHasErrors('title')
            ->assertDispatched('form-invalid')
            // And it must not advance past a step that did not validate.
            ->assertSet('step', 3);
    }

    public function test_the_length_minimums_are_stated_not_discovered(): void
    {
        $verified = User::factory()->create();

        Livewire::actingAs($verified)
            ->test(CreateListing::class)
            ->set('step', 3)
            // Copied from the blade, not retyped: "не" and "Не" are the same
            // word and a different assertion, and that has cost time here twice.
            ->assertSee('Поне 8 знака')
            ->assertSee('Поне 20 знака');
    }
}
