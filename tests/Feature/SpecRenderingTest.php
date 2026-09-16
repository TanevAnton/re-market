<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\BrowseListings;
use App\Livewire\Listings\CreateListing;
use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A spec value is not always a string, and two views assumed it was.
 *
 * `{{ $v }}` runs htmlspecialchars(), which FATALS on an array rather than
 * degrading to something ugly. Four specs in the schema are `multiselect` and
 * hold arrays — cooler.sockets, case.form_factor, gpu.outputs, macbook.ports —
 * so any page printing raw spec values died with a 500 as soon as one of those
 * parts appeared on it.
 *
 * WHY IT HID FOR SO LONG, which is the part worth remembering. The card prints
 * `array_slice($specs, 0, 3)` and the wizard prints the first five, so whether
 * the bug fires depends on where the array-valued spec happens to sit in its
 * category's schema. `case.form_factor` is first and `cooler.sockets` second —
 * both fatal. `gpu.outputs` is ninth, so graphics cards, which are most of the
 * site and all of the manual testing, were always fine. Adding a spec to the
 * top of the GPU schema would have broken browse for everybody, and the commit
 * would have looked like a config edit.
 *
 * These tests pin the categories by name rather than trusting a seeded sample,
 * because a fixture that happens not to contain a cooler proves nothing.
 */
class SpecRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
    }

    /** @return list<array{string, string}> */
    public static function multiselectSpecs(): array
    {
        return [
            'case form_factor (first spec)' => ['case', 'form_factor'],
            'cooler sockets (second spec)'  => ['cooler', 'sockets'],
            'gpu outputs (ninth spec)'      => ['gpu', 'outputs'],
            'macbook ports (seventh spec)'  => ['macbook', 'ports'],
        ];
    }

    /**
     * The schema still declares these as multiselect.
     *
     * If somebody changes one to a plain string the tests below stop covering
     * anything, and would keep passing while doing it.
     */
    #[DataProvider('multiselectSpecs')]
    public function test_the_spec_really_is_an_array_type(string $category, string $key): void
    {
        $this->assertSame(
            'multiselect',
            config("catalog.categories.{$category}.specs.{$key}.type"),
            "[{$category}.{$key}] is no longer multiselect, so this test guards nothing",
        );
    }

    #[DataProvider('multiselectSpecs')]
    public function test_browse_survives_a_part_whose_spec_is_an_array(string $category, string $key): void
    {
        $options = config("catalog.categories.{$category}.specs.{$key}.options", ['A', 'B']);

        $part = Part::create([
            'category'     => $category,
            'manufacturer' => 'Тест',
            'model'        => 'Многоцветен '.$key,
            'slug'         => 'test-'.$category.'-'.$key,
            // First in the array on purpose: the card only prints the first
            // three, so a spec added at the end would not exercise the bug.
            'specs'        => [$key => array_slice($options, 0, 3)],
            'is_published' => true,
        ]);

        Listing::factory()->create([
            'user_id'  => User::factory()->create()->id,
            'part_id'  => $part->id,
            'category' => $category,
            'city_id'  => City::first()->id,
            'status'   => ListingStatus::Active,
        ]);

        Livewire::test(BrowseListings::class)
            ->assertOk()
            ->assertSee($part->model);
    }

    /** The same values, rendered on step 2 of the wizard. */
    #[DataProvider('multiselectSpecs')]
    public function test_the_wizard_survives_it_too(string $category, string $key): void
    {
        $options = config("catalog.categories.{$category}.specs.{$key}.options", ['A', 'B']);

        $part = Part::create([
            'category'     => $category,
            'manufacturer' => 'Тест',
            'model'        => 'Многоцветен '.$key,
            'slug'         => 'test-wizard-'.$category.'-'.$key,
            'specs'        => [$key => array_slice($options, 0, 3)],
            'is_published' => true,
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(CreateListing::class)
            ->set('category', $category)
            ->call('next')
            ->set('partId', $part->id)
            ->assertOk();
    }

    public function test_the_label_is_total_over_every_shape_a_spec_can_hold(): void
    {
        $this->assertSame('да', Part::specLabel(true));
        $this->assertSame('не', Part::specLabel(false));
        $this->assertSame('', Part::specLabel(null));
        $this->assertSame('16', Part::specLabel(16));
        $this->assertSame('16.5', Part::specLabel(16.5));
        $this->assertSame('ATX', Part::specLabel('ATX'));

        $this->assertSame('ATX · Micro-ATX', Part::specLabel(['ATX', 'Micro-ATX']));

        // Bools inside an array too - the old inline ternary checked is_bool
        // BEFORE is_array and so never reached them.
        $this->assertSame('да · не', Part::specLabel([true, false]));

        $this->assertSame('', Part::specLabel([]));
    }
}
