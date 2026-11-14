<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\BrowseListings;
use App\Models\Listing;
use App\Models\Part;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\Search\SavedSearchService;
use App\Support\GpuSeries;
use App\Support\SpecFilter;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The series menu above the graphics cards: „RTX 30", „RX 6000".
 *
 * A series is not stored anywhere - it is a rule about the chipset name - so
 * most of what can go wrong is the rule and the page disagreeing: a tile that
 * says three over a grid showing two, a card that belongs to no tile, a model
 * left selected inside a series it is not part of. Those are what is pinned
 * down here.
 */
class GpuSeriesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->user = User::factory()->create();
    }

    private function listingFor(string $model, array $overrides = []): Listing
    {
        $part = Part::where('model', $model)->firstOrFail();

        return Listing::factory()->create(array_merge([
            'part_id'         => $part->id,
            'category'        => $part->category,
            'title'           => $part->fullName(),
            'status'          => ListingStatus::Active,
            'min_offer_cents' => null,
        ], $overrides));
    }

    /**
     * By uuid, never by model name: the sidebar and the menu both print model
     * names whether or not a listing survived the filter. The uuid is only in
     * a result card's link.
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

    private function gpuPage(): object
    {
        return Livewire::test(BrowseListings::class)->set('category', 'gpu');
    }

    /** One tile out of a menu, by key. */
    private function tile(array $menu, string $key): array
    {
        $tile = collect($menu['tiles'])->firstWhere('key', $key);

        $this->assertNotNull($tile, "No tile for {$key}.");

        return $tile;
    }

    // --- the rule ---------------------------------------------------------

    /**
     * The guard on the seeder. A card added to the catalogue with a chipset no
     * pattern claims would be reachable only through „Всички", and nothing on
     * the page would say so.
     */
    public function test_every_graphics_card_in_the_catalogue_is_in_exactly_one_series(): void
    {
        $chipsets = array_unique(GpuSeries::chipsetsByPart());

        $this->assertNotEmpty($chipsets);

        foreach ($chipsets as $chipset) {
            $claimedBy = array_values(array_filter(
                GpuSeries::keys(),
                fn (string $key) => GpuSeries::contains($key, $chipset),
            ));

            $this->assertCount(1, $claimedBy, "{$chipset} is in ".count($claimedBy).' series.');
            $this->assertSame($claimedBy[0], GpuSeries::forChipset($chipset));
        }
    }

    public function test_look_alike_names_land_in_the_right_series(): void
    {
        // Same leading digit, different generation: the pair the patterns
        // exist to tell apart.
        $this->assertSame('rx-500', GpuSeries::forChipset('RX 580'));
        $this->assertSame('rx-5000', GpuSeries::forChipset('RX 5700 XT'));
        $this->assertSame('rtx-50', GpuSeries::forChipset('RTX 5070 Ti'));
        $this->assertSame('gtx', GpuSeries::forChipset('GTX 1660 SUPER'));

        $this->assertNull(GpuSeries::forChipset('Quadro P2000'));
        $this->assertNull(GpuSeries::find('rtx-99'));
    }

    public function test_a_tile_lists_its_models_in_order_and_names_the_range(): void
    {
        $tile = $this->tile($this->gpuPage()->instance()->seriesMenu(), 'rtx-30');

        $models = array_column($tile['models'], 'name');

        $this->assertSame('RTX 3050', $models[0]);
        $this->assertSame('RTX 3090 Ti', end($models));
        // Natural order, not alphabetical: the Ti sits after its base card
        // and before the next number up.
        $this->assertSame(
            ['RTX 3060', 'RTX 3060 Ti', 'RTX 3070'],
            array_slice($models, array_search('RTX 3060', $models, true), 3),
        );
        $this->assertSame('3050 – 3090 Ti', $tile['range']);
        $this->assertSame('NVIDIA', $tile['brand']);
    }

    // --- where the menu appears -------------------------------------------

    public function test_the_menu_is_drawn_on_the_graphics_card_page_only(): void
    {
        $page = Livewire::test(BrowseListings::class);

        $page->assertDontSeeHtml("pickSeries('rtx-30')");
        $this->assertNull($page->instance()->seriesMenu());

        $page->set('category', 'gpu')
            ->assertSeeHtml("pickSeries('rtx-30')")
            ->assertSeeHtml("pickSeries('rx-6000')")
            ->assertSee('Всички видеокарти');

        $page->set('category', 'cpu')->assertDontSeeHtml("pickSeries('rtx-30')");
    }

    // --- filtering --------------------------------------------------------

    public function test_picking_a_series_shows_only_that_series(): void
    {
        $rtx30 = $this->listingFor('GeForce RTX 3070');
        $rtx40 = $this->listingFor('GeForce RTX 4090');
        $amd   = $this->listingFor('Radeon RX 7800 XT');

        $page = $this->gpuPage()->call('pickSeries', 'rtx-30')->assertSet('series', 'rtx-30');

        $this->assertResults($page, [$rtx30], [$rtx40, $amd]);

        $page->call('pickSeries', 'rx-7000');

        $this->assertResults($page, [$amd], [$rtx30, $rtx40]);
    }

    public function test_pressing_the_chosen_series_again_lets_go_of_it(): void
    {
        $rtx30 = $this->listingFor('GeForce RTX 3070');
        $rtx40 = $this->listingFor('GeForce RTX 4090');

        $page = $this->gpuPage()
            ->call('pickSeries', 'rtx-30')
            ->call('pickSeries', 'rtx-30')
            ->assertSet('series', '');

        $this->assertResults($page, [$rtx30, $rtx40]);
    }

    public function test_all_graphics_cards_lets_go_of_the_series(): void
    {
        $page = $this->gpuPage()->call('pickSeries', 'rtx-30')->call('pickSeries', '');

        $page->assertSet('series', '')->assertSet('category', 'gpu');
    }

    public function test_a_shared_link_opens_on_its_series(): void
    {
        $amd    = $this->listingFor('Radeon RX 6700 XT');
        $nvidia = $this->listingFor('GeForce RTX 3070');

        $page = Livewire::withQueryParams(['kat' => 'gpu', 'seriya' => 'rx-6000'])
            ->test(BrowseListings::class)
            ->assertSet('series', 'rx-6000');

        $this->assertResults($page, [$amd], [$nvidia]);
    }

    /**
     * `seriya` is whatever somebody typed after it. A key that is not a series
     * must narrow nothing - and must not be reported as a filter either.
     */
    public function test_a_series_that_does_not_exist_is_ignored(): void
    {
        $a = $this->listingFor('GeForce RTX 3070');
        $b = $this->listingFor('Radeon RX 7800 XT');

        $page = Livewire::withQueryParams(['kat' => 'gpu', 'seriya' => "rtx-30' or 1=1--"])
            ->test(BrowseListings::class)
            ->assertOk();

        $this->assertResults($page, [$a, $b]);
        $this->assertNull($page->instance()->activeSeries());
        $this->assertNotContains('series', array_column($page->instance()->activeFilters(), 'key'));
    }

    public function test_a_series_means_nothing_outside_graphics_cards(): void
    {
        $cpu = $this->listingFor('Ryzen 7 7800X3D');

        $page = Livewire::withQueryParams(['kat' => 'cpu', 'seriya' => 'rtx-30'])
            ->test(BrowseListings::class);

        $this->assertResults($page, [$cpu]);
        $this->assertNull($page->instance()->activeSeries());
        // Not a search worth saving on the strength of a stale parameter.
        $this->assertSame('', $page->instance()->criteria()['seriya']);
    }

    public function test_changing_category_drops_the_series(): void
    {
        $this->gpuPage()
            ->call('pickSeries', 'rtx-30')
            ->set('category', 'cpu')
            ->assertSet('series', '');
    }

    // --- the numbers on the tiles ------------------------------------------

    public function test_tile_counts_are_the_listings_behind_them(): void
    {
        $this->listingFor('GeForce RTX 3070');
        $this->listingFor('GeForce RTX 3080');
        $this->listingFor('GeForce RTX 4090');
        // Not publicly visible, so not counted.
        $this->listingFor('GeForce RTX 3090', ['status' => ListingStatus::Draft]);

        $menu = $this->gpuPage()->instance()->seriesMenu();

        $this->assertSame(2, $this->tile($menu, 'rtx-30')['count']);
        $this->assertSame(1, $this->tile($menu, 'rtx-40')['count']);
        $this->assertSame(0, $this->tile($menu, 'rx-7000')['count']);
        $this->assertSame(3, $menu['total']);
    }

    /**
     * Two catalogue rows, one name: the 8 GB and 16 GB 4060 Ti. The model chip
     * says „RTX 4060 Ti" once and has to count both.
     */
    public function test_cards_that_share_a_chipset_are_counted_together(): void
    {
        $this->listingFor('GeForce RTX 4060 Ti');
        $this->listingFor('GeForce RTX 4060 Ti 16GB');

        $tile  = $this->tile($this->gpuPage()->instance()->seriesMenu(), 'rtx-40');
        $model = collect($tile['models'])->firstWhere('name', 'RTX 4060 Ti');

        $this->assertSame(2, $model['count']);
        $this->assertSame(2, $tile['count']);
        $this->assertCount(1, collect($tile['models'])->where('name', 'RTX 4060 Ti'));
    }

    /** A tile says how many listings pressing it will show - with the filters as they are. */
    public function test_tile_counts_follow_the_other_filters(): void
    {
        $this->listingFor('GeForce RTX 3070', ['price_cents' => 30000]);
        $this->listingFor('GeForce RTX 3080', ['price_cents' => 50000]);
        $this->listingFor('GeForce RTX 4090', ['price_cents' => 150000]);

        $menu = $this->gpuPage()->set('priceMax', '400')->instance()->seriesMenu();

        $this->assertSame(1, $this->tile($menu, 'rtx-30')['count']);
        $this->assertSame(0, $this->tile($menu, 'rtx-40')['count']);
        $this->assertSame(1, $menu['total']);
    }

    /**
     * The menu chooses between series and models, so it cannot be narrowed by
     * the one that is chosen - every other tile would read as empty.
     */
    public function test_tile_counts_ignore_the_series_and_model_that_are_selected(): void
    {
        $this->listingFor('GeForce RTX 3070');
        $this->listingFor('GeForce RTX 3080');
        $this->listingFor('GeForce RTX 4090');

        $menu = $this->gpuPage()
            ->call('pickSeries', 'rtx-30')
            ->call('pickModel', 'RTX 3070')
            ->instance()->seriesMenu();

        $this->assertSame(2, $this->tile($menu, 'rtx-30')['count']);
        $this->assertSame(1, $this->tile($menu, 'rtx-40')['count']);
        $this->assertSame(3, $menu['total']);
        $this->assertSame('rtx-30', $menu['active']['key']);
        $this->assertSame(['RTX 3070'], $menu['chosen']);
    }

    /**
     * A seller who named a model the catalogue does not have still posted a
     * graphics card. It has no series, but „Всички" has to count it or the
     * number disagrees with the grid underneath.
     */
    public function test_a_card_outside_the_catalogue_is_counted_under_all_only(): void
    {
        $this->listingFor('GeForce RTX 3070');

        $loose = Listing::factory()->create([
            'part_id'         => null,
            // What the seller typed when the catalogue had no such model.
            'custom_part'     => 'Matrox G200',
            'category'        => 'gpu',
            'title'           => 'Рядка видеокарта, която каталогът не познава',
            'status'          => ListingStatus::Active,
            'min_offer_cents' => null,
        ]);

        $page = $this->gpuPage();
        $menu = $page->instance()->seriesMenu();

        $this->assertSame(2, $menu['total']);
        $this->assertSame(1, array_sum(array_column($menu['tiles'], 'count')));
        $this->assertResults($page, [$loose]);

        // And it is not in a series, so choosing one leaves it out.
        $this->assertResults($page->call('pickSeries', 'rtx-30'), [], [$loose]);
    }

    // --- models -----------------------------------------------------------

    public function test_a_model_chip_sets_the_ordinary_chipset_filter(): void
    {
        $r3070 = $this->listingFor('GeForce RTX 3070');
        $r3080 = $this->listingFor('GeForce RTX 3080');

        $page = $this->gpuPage()
            ->call('pickSeries', 'rtx-30')
            ->assertSeeHtml("pickModel('RTX 3070')")
            ->call('pickModel', 'RTX 3070')
            ->assertSet('specs', ['chipset' => ['RTX 3070']]);

        $this->assertResults($page, [$r3070], [$r3080]);

        // Pressed again, it comes off - and takes the empty key with it, so
        // the URL does not keep a filter that filters nothing.
        $page->call('pickModel', 'RTX 3070')->assertSet('specs', []);

        $this->assertResults($page, [$r3070, $r3080]);
    }

    public function test_the_model_row_is_only_there_once_a_series_is_chosen(): void
    {
        $this->gpuPage()
            ->assertDontSeeHtml('pickModel(')
            ->call('pickSeries', 'rx-6000')
            ->assertSeeHtml("pickModel('RX 6700 XT')")
            ->assertDontSeeHtml("pickModel('RTX 3070')");
    }

    public function test_a_model_from_another_series_is_refused(): void
    {
        $this->gpuPage()
            ->call('pickSeries', 'rtx-30')
            ->call('pickModel', 'RX 6800')
            ->assertSet('specs', []);

        // And with no series chosen there is no row to have pressed.
        $this->gpuPage()->call('pickModel', 'RTX 3070')->assertSet('specs', []);
    }

    /** „RTX 3070" inside „RX 6000" is an empty page with no visible reason. */
    public function test_changing_series_drops_the_model(): void
    {
        $amd = $this->listingFor('Radeon RX 6700 XT');

        $page = $this->gpuPage()
            ->call('pickSeries', 'rtx-30')
            ->call('pickModel', 'RTX 3070')
            ->call('pickSeries', 'rx-6000')
            ->assertSet('series', 'rx-6000')
            ->assertSet('specs', []);

        $this->assertResults($page, [$amd]);
    }

    public function test_the_sidebar_model_list_narrows_to_the_series(): void
    {
        $spec = config('catalog.categories.gpu.specs.chipset');

        $page = $this->gpuPage();
        $all  = $page->instance()->facetOptions('chipset', $spec);

        $this->assertContains('RTX 3070', $all);
        $this->assertContains('RX 6800', $all);

        $narrowed = $page->call('pickSeries', 'rtx-30')->instance()->facetOptions('chipset', $spec);

        $this->assertContains('RTX 3070', $narrowed);
        $this->assertNotContains('RX 6800', $narrowed);
        $this->assertNotContains('RTX 4090', $narrowed);

        // Other facets are none of the series' business.
        $this->assertSame(
            $this->gpuPage()->instance()->facetOptions('vram_gb', config('catalog.categories.gpu.specs.vram_gb')),
            $page->instance()->facetOptions('vram_gb', config('catalog.categories.gpu.specs.vram_gb')),
        );
    }

    // --- chips, clearing, empty -------------------------------------------

    public function test_the_series_is_a_chip_that_can_be_removed_on_its_own(): void
    {
        $page = $this->gpuPage()->call('pickSeries', 'rtx-30')->call('pickModel', 'RTX 3070');

        $labels = array_column($page->instance()->activeFilters(), 'label');

        $this->assertContains('Серия: RTX 30', $labels);
        $page->assertSee('Серия: RTX 30');

        // The model stays: on its own it is still a real search.
        $page->call('clearFilter', 'series')
            ->assertSet('series', '')
            ->assertSet('category', 'gpu')
            ->assertSet('specs', ['chipset' => ['RTX 3070']]);
    }

    public function test_clearing_the_filters_clears_the_series_and_keeps_the_category(): void
    {
        $this->gpuPage()
            ->call('pickSeries', 'rtx-30')
            ->call('clearFilters')
            ->assertSet('series', '')
            ->assertSet('category', 'gpu');
    }

    public function test_removing_the_category_chip_takes_the_series_with_it(): void
    {
        $this->gpuPage()
            ->call('pickSeries', 'rtx-30')
            ->call('clearFilter', 'category')
            ->assertSet('category', '')
            ->assertSet('series', '');
    }

    public function test_an_empty_series_says_which_series_is_empty(): void
    {
        $this->listingFor('GeForce RTX 3070');

        $page = $this->gpuPage()->call('pickSeries', 'rtx-50');

        $page->assertSee('В момента няма обяви за RTX 50')
            ->assertSee('Извести ме при нова обява')
            ->assertSeeHtml(route('wanted.create'));

        // One model chosen, so that is the thing that is missing.
        $page->call('pickModel', 'RTX 5090')->assertSee('В момента няма обяви за RTX 5090');
    }

    public function test_an_ordinary_empty_result_keeps_its_ordinary_wording(): void
    {
        $this->gpuPage()
            ->set('priceMax', '1')
            ->assertSee('Няма обяви по тези филтри')
            ->assertDontSee('В момента няма обяви за');
    }

    // --- what a crawler is told -------------------------------------------

    public function test_a_series_page_is_a_slice_of_the_category(): void
    {
        config(['remarket.seo.indexable' => true]);

        $response = $this->get(route('browse', ['kat' => 'gpu', 'seriya' => 'rtx-30']));

        $response->assertOk();
        $response->assertSee('content="noindex, follow"', false);
        $response->assertSee('rel="canonical" href="'.route('browse', ['kat' => 'gpu']).'"', false);
        $response->assertSee('<title>'.SpecFilter::categoryLabel('gpu').' RTX 30 втора употреба', false);
    }

    public function test_an_unknown_series_does_not_turn_the_category_page_into_a_slice(): void
    {
        config(['remarket.seo.indexable' => true]);

        $this->get(route('browse', ['kat' => 'gpu', 'seriya' => 'nope']))
            ->assertOk()
            ->assertDontSee('name="robots"', false);
    }

    // --- saved searches ---------------------------------------------------

    public function test_a_series_can_be_saved_and_alerts_on_that_series(): void
    {
        $page = Livewire::actingAs($this->user)
            ->test(BrowseListings::class)
            ->set('category', 'gpu')
            ->call('pickSeries', 'rtx-50')
            ->call('startSaveSearch')
            ->assertSet('savingSearch', true);

        // The suggested name says what was saved.
        $this->assertStringContainsString('RTX 50', $page->get('searchName'));

        $page->call('saveSearch')->assertHasNoErrors();

        $saved = SavedSearch::where('user_id', $this->user->id)->firstOrFail();

        // Stored as the key, in the URL's own word for it - so it re-opens as
        // a browse page, and a card that joins the series later still alerts.
        $this->assertSame('rtx-50', $saved->criteria['seriya']);
        $this->assertSame('gpu', $saved->criteria['kat']);

        $match = $this->listingFor('GeForce RTX 5090');
        $other = $this->listingFor('GeForce RTX 3070');

        $ids = app(SavedSearchService::class)->matches($saved)->get()->modelKeys();

        $this->assertContains($match->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_a_saved_search_with_a_made_up_series_narrows_nothing(): void
    {
        $searches = app(SavedSearchService::class);

        $a = $this->listingFor('GeForce RTX 5090');
        $b = $this->listingFor('Radeon RX 6700 XT');

        $saved = $searches->save($this->user, 'Странно', ['kat' => 'gpu', 'seriya' => 'rtx-99']);

        $ids = $searches->matches($saved)->get()->modelKeys();

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }
}
