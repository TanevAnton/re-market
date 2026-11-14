<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\BrowseListings;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Support\SpecFilter;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The trail at the top of the listings page: Начало / Обяви / Процесори /
 * Ryzen 5000.
 *
 * Two things are worth holding on to here. The trail has to say where the page
 * actually is - a step too many or too few is a link that goes somewhere other
 * than „up". And going up must let go of exactly what was below that level and
 * nothing else: losing the city or the price somebody set, for the sake of one
 * step back, is the trail costing more than the header link it replaced.
 */
class BrowseBreadcrumbTest extends TestCase
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
            'part_id'         => $part->id,
            'category'        => $part->category,
            'title'           => $part->fullName(),
            'status'          => ListingStatus::Active,
            'min_offer_cents' => null,
        ], $overrides));
    }

    /** @return list<string> */
    private function labels(object $page): array
    {
        return array_column($page->instance()->breadcrumbs(), 'label');
    }

    // --- what the trail says ----------------------------------------------

    public function test_the_listings_page_is_two_steps_from_home(): void
    {
        $trail = Livewire::test(BrowseListings::class)->instance()->breadcrumbs();

        $this->assertSame(['Начало', 'Обяви'], array_column($trail, 'label'));
        $this->assertSame(route('home'), $trail[0]['url']);
        // Where you are leads nowhere.
        $this->assertNull($trail[1]['url']);
        $this->assertNull($trail[1]['level']);
    }

    public function test_a_category_is_the_last_step_and_the_listings_become_a_link(): void
    {
        $trail = Livewire::test(BrowseListings::class)->set('category', 'cpu')->instance()->breadcrumbs();

        $this->assertSame(['Начало', 'Обяви', SpecFilter::categoryLabel('cpu')], array_column($trail, 'label'));
        $this->assertSame(route('browse'), $trail[1]['url']);
        $this->assertSame('all', $trail[1]['level']);
        $this->assertNull($trail[2]['url']);
    }

    /** Every category, not only the two that have a series menu. */
    public function test_a_category_without_series_has_a_trail_too(): void
    {
        $page = Livewire::test(BrowseListings::class)->set('category', 'ram');

        $this->assertSame(['Начало', 'Обяви', SpecFilter::categoryLabel('ram')], $this->labels($page));
    }

    public function test_a_series_and_then_a_model_extend_the_trail(): void
    {
        $page = Livewire::test(BrowseListings::class)
            ->set('category', 'cpu')
            ->call('pickSeries', 'ryzen-5000');

        $trail = $page->instance()->breadcrumbs();

        $this->assertSame(['Начало', 'Обяви', SpecFilter::categoryLabel('cpu'), 'Ryzen 5000'], array_column($trail, 'label'));
        $this->assertSame(route('browse', ['kat' => 'cpu']), $trail[2]['url']);
        $this->assertSame('category', $trail[2]['level']);
        $this->assertNull($trail[3]['url']);

        $trail = $page->call('pickModel', 'Ryzen 7 5800X3D')->instance()->breadcrumbs();

        $this->assertSame('Ryzen 7 5800X3D', end($trail)['label']);
        $this->assertSame(route('browse', ['kat' => 'cpu', 'seriya' => 'ryzen-5000']), $trail[3]['url']);
        $this->assertSame('series', $trail[3]['level']);
    }

    public function test_it_works_the_same_for_graphics_cards(): void
    {
        $page = Livewire::test(BrowseListings::class)
            ->set('category', 'gpu')
            ->call('pickSeries', 'rtx-30')
            ->call('pickModel', 'RTX 3070');

        $this->assertSame(
            ['Начало', 'Обяви', SpecFilter::categoryLabel('gpu'), 'RTX 30', 'RTX 3070'],
            $this->labels($page),
        );
    }

    /** Two models ticked in the sidebar are a filter, not a place. */
    public function test_several_models_are_not_a_step_on_the_trail(): void
    {
        $page = Livewire::test(BrowseListings::class)
            ->set('category', 'gpu')
            ->call('pickSeries', 'rtx-30')
            ->set('specs', ['chipset' => ['RTX 3070', 'RTX 3080']]);

        $this->assertSame('RTX 30', collect($this->labels($page))->last());
    }

    /** A series from another category in the address is not a step either. */
    public function test_a_series_that_does_not_apply_is_left_off(): void
    {
        $page = Livewire::withQueryParams(['kat' => 'cpu', 'seriya' => 'rtx-30'])->test(BrowseListings::class);

        $this->assertSame(['Начало', 'Обяви', SpecFilter::categoryLabel('cpu')], $this->labels($page));
    }

    // --- going up ---------------------------------------------------------

    public function test_going_up_to_the_series_lets_go_of_the_model_only(): void
    {
        $x3d   = $this->listingFor('Ryzen 7 5800X3D');
        $plain = $this->listingFor('Ryzen 5 5600');

        $page = Livewire::test(BrowseListings::class)
            ->set('category', 'cpu')
            ->call('pickSeries', 'ryzen-5000')
            ->call('pickModel', 'Ryzen 7 5800X3D')
            ->call('crumb', 'series')
            ->assertSet('partModel', '')
            ->assertSet('series', 'ryzen-5000')
            ->assertSet('category', 'cpu');

        $page->assertSee($x3d->uuid)->assertSee($plain->uuid);
    }

    public function test_going_up_to_the_category_lets_go_of_series_and_model(): void
    {
        Livewire::test(BrowseListings::class)
            ->set('category', 'gpu')
            ->call('pickSeries', 'rtx-30')
            ->call('pickModel', 'RTX 3070')
            ->call('crumb', 'category')
            ->assertSet('series', '')
            ->assertSet('specs', [])
            ->assertSet('category', 'gpu');
    }

    public function test_going_up_to_all_listings_lets_go_of_the_category(): void
    {
        $cpu = $this->listingFor('Ryzen 5 5600');
        $gpu = $this->listingFor('GeForce RTX 3070');

        $page = Livewire::test(BrowseListings::class)
            ->set('category', 'cpu')
            ->call('pickSeries', 'ryzen-5000')
            ->call('crumb', 'all')
            ->assertSet('category', '')
            ->assertSet('series', '')
            ->assertSet('partModel', '');

        $page->assertSee($cpu->uuid)->assertSee($gpu->uuid);
    }

    /**
     * The reason an ordinary click is handled in place rather than followed:
     * the price and the search are how somebody is looking, not
     * where they are, and one step back should not cost them.
     */
    public function test_going_up_keeps_the_other_filters(): void
    {
        $page = Livewire::test(BrowseListings::class)
            ->set('category', 'cpu')
            ->set('priceMax', '300')
            ->set('q', 'ryzen')
            ->call('pickSeries', 'ryzen-5000')
            ->call('crumb', 'category');

        $page->assertSet('priceMax', '300')
            ->assertSet('q', 'ryzen');

        $page->call('crumb', 'all')
            ->assertSet('category', '')
            ->assertSet('priceMax', '300')
            ->assertSet('q', 'ryzen');
    }

    public function test_a_level_that_is_not_on_the_trail_changes_nothing(): void
    {
        Livewire::test(BrowseListings::class)
            ->set('category', 'cpu')
            ->call('pickSeries', 'ryzen-5000')
            ->call('crumb', 'nonsense')
            ->assertSet('category', 'cpu')
            ->assertSet('series', 'ryzen-5000');
    }

    // --- on the page ------------------------------------------------------

    public function test_the_trail_is_drawn_with_real_links_and_one_heading(): void
    {
        $html = $this->get(route('browse', ['kat' => 'cpu', 'seriya' => 'ryzen-5000']))
            ->assertOk()
            ->getContent();

        // An address for a new tab or a crawler, and the in-place step for a click.
        $this->assertStringContainsString('href="'.route('browse', ['kat' => 'cpu']).'" wire:click.prevent="crumb(\'category\')"', $html);
        $this->assertStringContainsString('href="'.route('browse').'" wire:click.prevent="crumb(\'all\')"', $html);

        // Where you are is the page's one heading, and not a link.
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertMatchesRegularExpression('/<h1 aria-current="page"[^>]*>\s*Ryzen 5000\s*<\/h1>/u', $html);
    }

    public function test_the_plain_listings_page_has_the_trail_as_well(): void
    {
        $this->get(route('browse'))
            ->assertOk()
            ->assertSee('aria-label="Път до страницата"', false)
            ->assertSee('href="'.route('home').'"', false);
    }

    /** The label comes from the address, so it goes onto the page escaped. */
    public function test_a_model_name_from_the_address_is_escaped(): void
    {
        $this->get(route('browse', ['kat' => 'cpu', 'seriya' => 'ryzen-5000', 'model' => '<script>alert(1)</script>']))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
