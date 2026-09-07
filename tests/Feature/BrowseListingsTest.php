<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\BrowseListings;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BrowseListingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);
        User::factory()->create();
    }

    private function listingFor(string $model, array $overrides = []): Listing
    {
        $part = Part::where('model', $model)->firstOrFail();

        return Listing::factory()->create(array_merge([
            'part_id'  => $part->id,
            'category' => $part->category,
            'title'    => $part->fullName(),
            'status'   => ListingStatus::Active,
        ], $overrides));
    }

    /**
     * Assert which listings the result grid actually contains.
     *
     * Never assert on a model name like "RTX 4090": once a category is chosen
     * the sidebar renders the whole chipset facet, so that string is on the
     * page whether or not the listing survived the filter. Both of the facet
     * tests below were passing their assertSee and failing their assertDontSee
     * for exactly that reason - they were reading the filter, not the results.
     * The uuid appears only in a result card's href, so it cannot lie.
     */
    private function assertResults(object $page, array $shown, array $hidden = []): void
    {
        foreach ($shown as $listing) {
            $page->assertSee($listing->uuid);
        }

        foreach ($hidden as $listing) {
            $page->assertDontSee($listing->uuid);
        }
    }

    public function test_the_browse_page_loads(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_only_publicly_visible_listings_appear(): void
    {
        $active = $this->listingFor('GeForce RTX 4090');
        $draft  = $this->listingFor('GeForce RTX 4070', ['status' => ListingStatus::Draft]);

        $this->assertResults(Livewire::test(BrowseListings::class), [$active], [$draft]);
    }

    public function test_category_filter_narrows_results(): void
    {
        $gpu = $this->listingFor('GeForce RTX 4090');
        $cpu = $this->listingFor('Ryzen 7 7800X3D');

        $this->assertResults(
            Livewire::test(BrowseListings::class)->set('category', 'cpu'),
            [$cpu],
            [$gpu],
        );
    }

    /**
     * The query the whole catalogue exists to make possible, and the one OLX
     * structurally cannot express.
     */
    public function test_a_length_facet_excludes_cards_that_will_not_fit(): void
    {
        $long  = $this->listingFor('GeForce RTX 4090');     // 304 mm
        $short = $this->listingFor('Radeon RX 7800 XT');    // 267 mm

        $this->assertResults(
            Livewire::test(BrowseListings::class)
                ->set('category', 'gpu')
                ->set('specs', ['length_mm' => ['max' => 300]]),
            [$short],
            [$long],
        );
    }

    public function test_a_terms_facet_filters_on_vram(): void
    {
        $vram24 = $this->listingFor('GeForce RTX 4090');    // 24 GB
        $vram12 = $this->listingFor('GeForce RTX 4070');    // 12 GB

        $this->assertResults(
            Livewire::test(BrowseListings::class)
                ->set('category', 'gpu')
                ->set('specs', ['vram_gb' => ['12']]),
            [$vram12],
            [$vram24],
        );
    }

    public function test_cyrillic_search_finds_a_latin_titled_listing(): void
    {
        $nvidia = $this->listingFor('GeForce RTX 4090');
        $amd    = $this->listingFor('Radeon RX 7800 XT');

        $this->assertResults(
            Livewire::test(BrowseListings::class)->set('q', 'ртх 4090'),
            [$nvidia],
            [$amd],
        );
    }

    public function test_price_bounds_are_applied_in_euros_not_cents(): void
    {
        $expensive = $this->listingFor('GeForce RTX 4090', ['price_cents' => 150000]);  // 1500 EUR
        $cheap     = $this->listingFor('GeForce RTX 4070', ['price_cents' => 40000]);   //  400 EUR

        $this->assertResults(
            Livewire::test(BrowseListings::class)->set('priceMax', '500'),
            [$cheap],
            [$expensive],
        );
    }

    public function test_an_unknown_spec_key_is_ignored_rather_than_injected(): void
    {
        $listing = $this->listingFor('GeForce RTX 4090');

        // A hostile key must be dropped by the schema check, not reach SQL.
        $page = Livewire::test(BrowseListings::class)
            ->set('category', 'gpu')
            ->set('specs', ["1) or 1=1--" => ['x']])
            ->assertOk();

        $this->assertResults($page, [$listing]);
    }

    public function test_clearing_filters_restores_everything(): void
    {
        $gpu = $this->listingFor('GeForce RTX 4090');
        $cpu = $this->listingFor('Ryzen 7 7800X3D');

        $page = Livewire::test(BrowseListings::class)->set('category', 'cpu');
        $this->assertResults($page, [$cpu], [$gpu]);

        $page->set('category', '')->call('clearFilters');
        $this->assertResults($page, [$gpu, $cpu]);
    }
}
