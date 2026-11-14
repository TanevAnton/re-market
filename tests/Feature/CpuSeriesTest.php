<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\BrowseListings;
use App\Models\Listing;
use App\Models\Part;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\Search\SavedSearchService;
use App\Support\PartSeries;
use App\Support\SpecFilter;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The series menu above the processors: „Ryzen 5000", „Core 13".
 *
 * The menu itself is the one GpuSeriesTest already covers - same markup, same
 * counting, same rules about what a tile may say. What is different here, and
 * so what this file is about, is the two things processors do not share with
 * graphics cards:
 *
 *   - the name a series is read from is the model, not a chipset in the spec
 *     sheet, and Intel's naming makes the patterns easier to get subtly wrong;
 *   - a processor has no model facet in the sidebar, so picking one is a
 *     filter of its own (`model=`) rather than a facet being set - with its
 *     own chip, its own place in a saved search, and its own way to go stale.
 */
class CpuSeriesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private PartSeries $series;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->user   = User::factory()->create();
        $this->series = PartSeries::for('cpu');
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

    /** By uuid - model names are printed by the menu whether or not a listing matched. */
    private function assertResults(object $page, array $shown, array $hidden = []): void
    {
        foreach ($shown as $listing) {
            $page->assertSee($listing->uuid);
        }

        foreach ($hidden as $listing) {
            $page->assertDontSee($listing->uuid);
        }
    }

    private function cpuPage(): object
    {
        return Livewire::test(BrowseListings::class)->set('category', 'cpu');
    }

    private function tile(array $menu, string $key): array
    {
        $tile = collect($menu['tiles'])->firstWhere('key', $key);

        $this->assertNotNull($tile, "No tile for {$key}.");

        return $tile;
    }

    // --- the rule ---------------------------------------------------------

    public function test_every_processor_in_the_catalogue_is_in_exactly_one_series(): void
    {
        $models = $this->series->namesByPart();

        $this->assertNotEmpty($models);

        foreach ($models as $model) {
            $claimedBy = array_values(array_filter(
                $this->series->keys(),
                fn (string $key) => $this->series->contains($key, $model),
            ));

            $this->assertCount(1, $claimedBy, "{$model} is in ".count($claimedBy).' series.');
            $this->assertSame($claimedBy[0], $this->series->forName($model));
        }
    }

    /**
     * Intel's numbers are four digits for a decade and then five, and the
     * generation is the first one or two of them. These are the pairs a
     * careless pattern puts in the wrong tile.
     */
    public function test_look_alike_names_land_in_the_right_series(): void
    {
        $this->assertSame('core-12', $this->series->forName('Core i5-12400F'));
        $this->assertSame('core-13', $this->series->forName('Core i9-13900K'));
        $this->assertSame('core-14', $this->series->forName('Core i7-14700K'));
        $this->assertSame('core-6-11', $this->series->forName('Core i7-7700K'));
        $this->assertSame('core-6-11', $this->series->forName('Core i5-10400F'));
        $this->assertSame('core-6-11', $this->series->forName('Core i7-11700K'));
        $this->assertSame('core-ultra', $this->series->forName('Core Ultra 7 265K'));

        // The tier digit is not the generation: a Ryzen 9 is not a Ryzen 9000.
        $this->assertSame('ryzen-5000', $this->series->forName('Ryzen 9 5950X'));
        $this->assertSame('ryzen-9000', $this->series->forName('Ryzen 5 9600X'));
        $this->assertSame('ryzen-7000', $this->series->forName('Ryzen 7 7800X3D'));

        // Not in the catalogue and not in a tile - reachable under „Всички".
        $this->assertNull($this->series->forName('Ryzen 5 1600'));
        $this->assertNull($this->series->forName('Xeon E5-2680 v4'));
        $this->assertNull($this->series->find('rtx-30'));
    }

    /**
     * Smallest to largest by the number, not by the full name - „i5" sorts
     * before „i7", which would otherwise put a 10400F ahead of a 7700K.
     */
    public function test_models_are_ordered_by_their_number(): void
    {
        $menu = $this->cpuPage()->instance()->seriesMenu();

        $old = array_column($this->tile($menu, 'core-6-11')['models'], 'name');

        $this->assertSame('Core i7-7700K', $old[0]);
        $this->assertSame('Core i7-11700K', end($old));
        $this->assertSame('7700K – 11700K', $this->tile($menu, 'core-6-11')['range']);

        $ryzen = array_column($this->tile($menu, 'ryzen-5000')['models'], 'name');

        $this->assertSame('Ryzen 5 5500', $ryzen[0]);
        $this->assertSame('Ryzen 9 5950X', end($ryzen));
        $this->assertSame('5500 – 5950X', $this->tile($menu, 'ryzen-5000')['range']);
        $this->assertSame('AMD', $this->tile($menu, 'ryzen-5000')['brand']);
        $this->assertSame('Intel', $this->tile($menu, 'core-13')['brand']);
    }

    // --- where the menu appears -------------------------------------------

    public function test_the_processor_page_has_its_own_menu(): void
    {
        $this->cpuPage()
            ->assertSeeHtml("pickSeries('ryzen-5000')")
            ->assertSeeHtml("pickSeries('core-13')")
            ->assertSee('Всички процесори')
            // Its own, not the graphics cards'.
            ->assertDontSeeHtml("pickSeries('rtx-30')")
            ->assertDontSee('Всички видеокарти');
    }

    public function test_each_category_gets_only_its_own_series(): void
    {
        Livewire::test(BrowseListings::class)
            ->set('category', 'gpu')
            ->assertSeeHtml("pickSeries('rtx-30')")
            ->assertDontSeeHtml("pickSeries('ryzen-5000')")
            ->assertSee('Всички видеокарти');
    }

    public function test_a_category_without_series_has_no_menu(): void
    {
        $page = Livewire::test(BrowseListings::class)->set('category', 'ram');

        $this->assertNull($page->instance()->seriesMenu());
        $page->assertDontSeeHtml('pickSeries(');

        // And a press that somehow arrives anyway changes nothing.
        $page->call('pickSeries', 'ryzen-5000')->assertSet('series', '');
    }

    // --- filtering by series ----------------------------------------------

    public function test_picking_a_series_shows_only_that_series(): void
    {
        $am4   = $this->listingFor('Ryzen 7 5800X3D');
        $am5   = $this->listingFor('Ryzen 7 7800X3D');
        $intel = $this->listingFor('Core i5-13600K');

        $page = $this->cpuPage()->call('pickSeries', 'ryzen-5000')->assertSet('series', 'ryzen-5000');

        $this->assertResults($page, [$am4], [$am5, $intel]);

        $page->call('pickSeries', 'core-13');

        $this->assertResults($page, [$intel], [$am4, $am5]);

        // Pressed again, it lets go.
        $page->call('pickSeries', 'core-13')->assertSet('series', '');

        $this->assertResults($page, [$am4, $am5, $intel]);
    }

    public function test_a_shared_link_opens_on_its_series(): void
    {
        $old = $this->listingFor('Core i7-10700K');
        $new = $this->listingFor('Core i9-14900K');

        $page = Livewire::withQueryParams(['kat' => 'cpu', 'seriya' => 'core-6-11'])
            ->test(BrowseListings::class);

        $this->assertResults($page, [$old], [$new]);
    }

    /** A graphics-card series in the address of the processor page is not a series there. */
    public function test_another_categorys_series_is_ignored(): void
    {
        $cpu = $this->listingFor('Ryzen 7 5800X3D');

        $page = Livewire::withQueryParams(['kat' => 'cpu', 'seriya' => 'rtx-30'])
            ->test(BrowseListings::class);

        $this->assertResults($page, [$cpu]);
        $this->assertNull($page->instance()->activeSeries());
    }

    // --- the numbers on the tiles ------------------------------------------

    public function test_tile_counts_are_the_listings_behind_them(): void
    {
        $this->listingFor('Ryzen 7 5800X3D');
        $this->listingFor('Ryzen 5 5600');
        $this->listingFor('Core i5-12400F');
        $this->listingFor('Ryzen 9 5900X', ['status' => ListingStatus::Draft]);

        $menu = $this->cpuPage()->instance()->seriesMenu();

        $this->assertSame(2, $this->tile($menu, 'ryzen-5000')['count']);
        $this->assertSame(1, $this->tile($menu, 'core-12')['count']);
        $this->assertSame(0, $this->tile($menu, 'core-ultra')['count']);
        $this->assertSame(3, $menu['total']);
    }

    public function test_tile_counts_follow_the_other_filters_and_ignore_what_is_selected(): void
    {
        $this->listingFor('Ryzen 7 5800X3D', ['price_cents' => 21000]);
        $this->listingFor('Ryzen 5 5600', ['price_cents' => 8000]);
        $this->listingFor('Core i5-12400F', ['price_cents' => 9500]);

        $page = $this->cpuPage()->set('priceMax', '100');
        $menu = $page->instance()->seriesMenu();

        $this->assertSame(1, $this->tile($menu, 'ryzen-5000')['count']);
        $this->assertSame(1, $this->tile($menu, 'core-12')['count']);
        $this->assertSame(2, $menu['total']);

        // Choosing a series and a model must not empty the other tiles.
        $menu = $page->set('priceMax', '')
            ->call('pickSeries', 'ryzen-5000')
            ->call('pickModel', 'Ryzen 5 5600')
            ->instance()->seriesMenu();

        $this->assertSame(2, $this->tile($menu, 'ryzen-5000')['count']);
        $this->assertSame(1, $this->tile($menu, 'core-12')['count']);
        $this->assertSame(3, $menu['total']);
        $this->assertSame(['Ryzen 5 5600'], $menu['chosen']);
    }

    // --- picking one model ------------------------------------------------

    public function test_a_model_chip_filters_to_that_model(): void
    {
        $x3d   = $this->listingFor('Ryzen 7 5800X3D');
        $plain = $this->listingFor('Ryzen 7 5800X');

        $page = $this->cpuPage()
            ->call('pickSeries', 'ryzen-5000')
            ->assertSeeHtml("pickModel('Ryzen 7 5800X3D')")
            ->call('pickModel', 'Ryzen 7 5800X3D')
            ->assertSet('partModel', 'Ryzen 7 5800X3D')
            // Its own filter - a processor has no model facet to borrow.
            ->assertSet('specs', []);

        // The exact model, not everything that starts with it.
        $this->assertResults($page, [$x3d], [$plain]);

        $page->call('pickModel', 'Ryzen 7 5800X3D')->assertSet('partModel', '');

        $this->assertResults($page, [$x3d, $plain]);
    }

    public function test_a_model_from_another_series_is_refused(): void
    {
        $this->cpuPage()
            ->call('pickSeries', 'ryzen-5000')
            ->call('pickModel', 'Core i5-13600K')
            ->assertSet('partModel', '');

        // And with no series chosen there is no row to have pressed.
        $this->cpuPage()->call('pickModel', 'Ryzen 5 5600')->assertSet('partModel', '');
    }

    public function test_changing_series_or_category_drops_the_model(): void
    {
        $page = $this->cpuPage()
            ->call('pickSeries', 'ryzen-5000')
            ->call('pickModel', 'Ryzen 5 5600')
            ->call('pickSeries', 'core-13')
            ->assertSet('series', 'core-13')
            ->assertSet('partModel', '');

        $page->call('pickModel', 'Core i5-13600K')
            ->set('category', 'gpu')
            ->assertSet('series', '')
            ->assertSet('partModel', '');
    }

    public function test_the_model_arrives_from_the_address_and_works_without_a_series(): void
    {
        $wanted = $this->listingFor('Ryzen 5 5600');
        $other  = $this->listingFor('Ryzen 5 5600X');

        $page = Livewire::withQueryParams(['kat' => 'cpu', 'model' => 'Ryzen 5 5600'])
            ->test(BrowseListings::class)
            ->assertSet('partModel', 'Ryzen 5 5600');

        $this->assertResults($page, [$wanted], [$other]);
    }

    /**
     * On the graphics-card page the model is the chipset facet. A `model=`
     * left over in the address there must do nothing - applied, it would be a
     * second, invisible filter emptying the grid.
     */
    public function test_a_stale_model_does_nothing_where_the_model_is_a_facet(): void
    {
        $gpu = $this->listingFor('GeForce RTX 3070');

        $page = Livewire::withQueryParams(['kat' => 'gpu', 'model' => 'Ryzen 5 5600'])
            ->test(BrowseListings::class);

        $this->assertResults($page, [$gpu]);
        $this->assertNull($page->instance()->activeModel());
        $this->assertSame('', $page->instance()->criteria()['model']);
        $this->assertNotContains('model', array_column($page->instance()->activeFilters(), 'key'));
    }

    public function test_a_model_typed_into_the_address_cannot_reach_the_query_as_sql(): void
    {
        $listing = $this->listingFor('Ryzen 5 5600');

        $page = Livewire::withQueryParams(['kat' => 'cpu', 'model' => "x' OR 1=1--"])
            ->test(BrowseListings::class)
            ->assertOk();

        // Bound as a value, so it is simply a model nobody is selling.
        $this->assertResults($page, [], [$listing]);
    }

    // --- chips, clearing, empty -------------------------------------------

    public function test_series_and_model_are_chips_that_come_off_one_at_a_time(): void
    {
        $page = $this->cpuPage()->call('pickSeries', 'ryzen-5000')->call('pickModel', 'Ryzen 7 5800X3D');

        $labels = array_column($page->instance()->activeFilters(), 'label');

        $this->assertContains('Серия: Ryzen 5000', $labels);
        $this->assertContains('Модел: Ryzen 7 5800X3D', $labels);

        // The model stays when the series goes: on its own it is still a search.
        $page->call('clearFilter', 'series')
            ->assertSet('series', '')
            ->assertSet('partModel', 'Ryzen 7 5800X3D');

        $page->call('clearFilter', 'model')
            ->assertSet('partModel', '')
            ->assertSet('category', 'cpu');
    }

    public function test_clearing_the_filters_clears_both_and_keeps_the_category(): void
    {
        $this->cpuPage()
            ->call('pickSeries', 'ryzen-5000')
            ->call('pickModel', 'Ryzen 5 5600')
            ->call('clearFilters')
            ->assertSet('series', '')
            ->assertSet('partModel', '')
            ->assertSet('category', 'cpu');
    }

    public function test_removing_the_category_chip_takes_both_with_it(): void
    {
        $this->cpuPage()
            ->call('pickSeries', 'ryzen-5000')
            ->call('pickModel', 'Ryzen 5 5600')
            ->call('clearFilter', 'category')
            ->assertSet('category', '')
            ->assertSet('series', '')
            ->assertSet('partModel', '');
    }

    public function test_an_empty_series_says_which_series_is_empty(): void
    {
        $this->listingFor('Ryzen 5 5600');

        $page = $this->cpuPage()->call('pickSeries', 'core-ultra');

        $page->assertSee('В момента няма обяви за Core Ultra')
            ->assertSee('Извести ме при нова обява');

        $page->call('pickModel', 'Core Ultra 9 285K')
            ->assertSee('В момента няма обяви за Core Ultra 9 285K');
    }

    /** The socket filter is the sidebar's own business; a series must not thin it out. */
    public function test_the_sidebar_filters_are_not_narrowed_by_a_series(): void
    {
        $spec = config('catalog.categories.cpu.specs.socket');

        $before = $this->cpuPage()->instance()->facetOptions('socket', $spec);
        $after  = $this->cpuPage()->call('pickSeries', 'ryzen-5000')->instance()->facetOptions('socket', $spec);

        $this->assertNotEmpty($before);
        $this->assertSame($before, $after);
    }

    // --- what a crawler is told -------------------------------------------

    public function test_a_series_page_is_a_slice_of_the_category(): void
    {
        config(['remarket.seo.indexable' => true]);

        $response = $this->get(route('browse', ['kat' => 'cpu', 'seriya' => 'ryzen-5000']));

        $response->assertOk();
        $response->assertSee('content="noindex, follow"', false);
        $response->assertSee('rel="canonical" href="'.route('browse', ['kat' => 'cpu']).'"', false);
        $response->assertSee('<title>'.SpecFilter::categoryLabel('cpu').' Ryzen 5000 втора употреба', false);
    }

    public function test_a_single_model_is_a_slice_too(): void
    {
        config(['remarket.seo.indexable' => true]);

        $this->get(route('browse', ['kat' => 'cpu', 'model' => 'Ryzen 5 5600']))
            ->assertOk()
            ->assertSee('content="noindex, follow"', false);
    }

    // --- saved searches ---------------------------------------------------

    public function test_a_series_can_be_saved_and_alerts_on_that_series(): void
    {
        $page = Livewire::actingAs($this->user)
            ->test(BrowseListings::class)
            ->set('category', 'cpu')
            ->call('pickSeries', 'ryzen-7000')
            ->call('startSaveSearch');

        $this->assertStringContainsString('Ryzen 7000', $page->get('searchName'));

        $page->call('saveSearch')->assertHasNoErrors();

        $saved = SavedSearch::where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame('ryzen-7000', $saved->criteria['seriya']);
        $this->assertArrayNotHasKey('model', $saved->criteria);

        $match = $this->listingFor('Ryzen 7 7800X3D');
        $other = $this->listingFor('Ryzen 7 5800X3D');

        $ids = app(SavedSearchService::class)->matches($saved)->get()->modelKeys();

        $this->assertContains($match->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_a_single_model_can_be_saved_and_alerts_on_that_model_only(): void
    {
        $page = Livewire::actingAs($this->user)
            ->test(BrowseListings::class)
            ->set('category', 'cpu')
            ->call('pickSeries', 'ryzen-5000')
            ->call('pickModel', 'Ryzen 7 5800X3D')
            ->call('startSaveSearch');

        // The suggested name is the model - it already says which series.
        $this->assertStringContainsString('Ryzen 7 5800X3D', $page->get('searchName'));

        $page->call('saveSearch')->assertHasNoErrors();

        $saved = SavedSearch::where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame('Ryzen 7 5800X3D', $saved->criteria['model']);

        $match   = $this->listingFor('Ryzen 7 5800X3D');
        $sibling = $this->listingFor('Ryzen 7 5800X');

        $ids = app(SavedSearchService::class)->matches($saved)->get()->modelKeys();

        $this->assertContains($match->id, $ids);
        $this->assertNotContains($sibling->id, $ids);
    }

    /** A `model` saved against graphics cards is not a filter there, so it narrows nothing. */
    public function test_a_saved_model_is_ignored_where_the_model_is_a_facet(): void
    {
        $searches = app(SavedSearchService::class);

        $gpu = $this->listingFor('GeForce RTX 3070');

        $saved = $searches->save($this->user, 'Странно', ['kat' => 'gpu', 'model' => 'Ryzen 5 5600']);

        $this->assertContains($gpu->id, $searches->matches($saved)->get()->modelKeys());
    }
}
