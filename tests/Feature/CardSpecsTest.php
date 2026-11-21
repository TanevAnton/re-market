<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Support\CardSpecs;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The specs a listing card prints.
 *
 * Most of this is plain arithmetic on arrays and needs no database; the last
 * two tests are the ones that matter - that every key the map names exists in
 * the schema (a typo is a silent blank on every card in a category), and that
 * the card really prints what the class says it should.
 */
class CardSpecsTest extends TestCase
{
    use RefreshDatabase;

    private function listingOf(string $category, array $partSpecs = [], array $listingSpecs = [], bool $withPart = true): Listing
    {
        $listing = (new Listing)->forceFill(['category' => $category, 'specs' => $listingSpecs]);

        if ($withPart) {
            $listing->setRelation('part', (new Part)->forceFill(['category' => $category, 'specs' => $partSpecs]));
        } else {
            $listing->setRelation('part', null);
        }

        return $listing;
    }

    public function test_a_graphics_card_shows_memory_length_and_power_with_units(): void
    {
        $listing = $this->listingOf('gpu', [
            'chipset' => 'RTX 4070', 'brand' => 'ASUS', 'vram_gb' => 12, 'length_mm' => 285, 'tdp_w' => 200,
        ]);

        // Not the chipset (it is the title) and not the board partner.
        $this->assertSame(['12 GB', '285 мм', '200 W'], CardSpecs::for($listing));
    }

    public function test_a_missing_spec_is_skipped_rather_than_left_as_a_hole(): void
    {
        $listing = $this->listingOf('gpu', ['vram_gb' => 8, 'tdp_w' => 130]);

        $this->assertSame(['8 GB', '130 W'], CardSpecs::for($listing));
    }

    public function test_optional_specs_hand_over_to_the_next_one_in_the_list(): void
    {
        // No discrete GPU: the laptop falls through to its processor instead
        // of showing nothing where the graphics card would have been.
        $listing = $this->listingOf(
            'laptop',
            ['cpu_model' => 'Intel Core i7-12700H'],
            ['ram_gb' => 16, 'storage_gb' => 512],
        );

        $this->assertSame(['Intel Core i7-12700H', '16 GB', '512 GB'], CardSpecs::for($listing));
    }

    public function test_decimals_lose_their_trailing_zeros_and_the_inch_mark_hugs_its_number(): void
    {
        $this->assertSame(
            ['AM5', '8 ядра', '5.4 GHz'],
            CardSpecs::for($this->listingOf('cpu', ['socket' => 'AM5', 'cores' => 8, 'boost_clock_ghz' => 5.40])),
        );

        $this->assertSame(
            ['27"', '2560x1440', '165 Hz'],
            CardSpecs::for($this->listingOf('monitor', ['size_inch' => 27.0, 'resolution' => '2560x1440', 'refresh_hz' => 165])),
        );
    }

    public function test_a_thousand_gigabytes_is_a_terabyte(): void
    {
        $disk = $this->listingOf('storage', ['drive_type' => 'NVMe SSD', 'capacity_gb' => 1000, 'interface' => 'PCIe 4.0']);

        $this->assertSame(['NVMe SSD', '1 TB', 'PCIe 4.0'], CardSpecs::for($disk));
        $this->assertSame(['SSD', '512 GB'], CardSpecs::for($this->listingOf('storage', ['drive_type' => 'SSD', 'capacity_gb' => 512])));

        // Memory is not storage: 1000 of anything else stays what it says.
        $this->assertSame(['1000 GB'], CardSpecs::for($this->listingOf('gpu', ['vram_gb' => 1000])));
    }

    public function test_a_unit_reads_the_way_a_person_would_write_it(): void
    {
        $phone = $this->listingOf('iphone', ['storage_gb' => 128, 'chip' => 'A15'], ['battery_health' => 87]);

        $this->assertSame(['128 GB', 'A15', 'батерия 87%'], CardSpecs::for($phone));
    }

    public function test_a_yes_no_spec_shows_only_when_it_is_yes(): void
    {
        $yes = $this->listingOf('headset', ['connection' => 'USB', 'has_mic' => true, 'surround' => false]);

        $this->assertSame(['USB', 'с микрофон'], CardSpecs::for($yes));
    }

    public function test_a_list_valued_spec_never_reaches_a_chip_and_never_crashes(): void
    {
        // form_factor on a case and sockets on a cooler are arrays, and an
        // array in a Blade echo is a 500 for the whole page.
        $case = $this->listingOf('case', ['max_gpu_mm' => 400, 'side_panel' => 'Стъкло', 'form_factor' => ['ATX', 'mATX']]);

        $this->assertSame(['видеокарта до 400 мм', 'Стъкло'], CardSpecs::for($case));

        $cooler = $this->listingOf('cooler', ['cooler_type' => 'Въздушно', 'sockets' => ['AM4', 'AM5'], 'height_mm' => 155]);

        $this->assertSame(['Въздушно', '155 мм'], CardSpecs::for($cooler));
    }

    public function test_a_listing_with_no_catalogue_part_still_gets_its_own_values(): void
    {
        $listing = $this->listingOf('laptop', [], ['ram_gb' => 32, 'storage_gb' => 2000, 'cpu_model' => 'i9'], withPart: false);

        $this->assertSame(['i9', '32 GB', '2 TB'], CardSpecs::for($listing));
    }

    public function test_nothing_to_show_is_an_empty_list_not_an_error(): void
    {
        $this->assertSame([], CardSpecs::for($this->listingOf('gpu', [])));
        $this->assertSame([], CardSpecs::for((new Listing)->forceFill(['category' => null, 'specs' => null])->setRelation('part', null)));
    }

    public function test_the_limit_is_respected(): void
    {
        $gpu = $this->listingOf('gpu', ['vram_gb' => 12, 'length_mm' => 285, 'tdp_w' => 200]);

        $this->assertCount(2, CardSpecs::for($gpu, 2));
    }

    /**
     * The guard that matters. A key that is not in the schema does not throw -
     * it is skipped, and every card in that category quietly shows less than it
     * should. This is the only thing that would notice.
     */
    public function test_every_key_the_map_names_exists_in_the_catalogue_schema(): void
    {
        foreach (CardSpecs::mappedKeys() as $path) {
            [$category, $key] = explode('.', $path, 2);

            $this->assertNotNull(
                config("catalog.categories.{$category}.specs.{$key}"),
                "CardSpecs names {$path}, which is not in config/catalog.php.",
            );
        }
    }

    public function test_every_mapped_category_is_a_real_category(): void
    {
        foreach (CardSpecs::categories() as $category) {
            $this->assertNotNull(config("catalog.categories.{$category}"), "{$category} is not a catalogue category.");
        }
    }

    // --- on the page -------------------------------------------------------

    public function test_the_card_on_the_home_page_prints_the_chips(): void
    {
        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $part = Part::where('category', 'gpu')->get()->first(
            fn (Part $p) => isset($p->specs['vram_gb'], $p->specs['length_mm']),
        );

        $this->assertNotNull($part, 'The seeder has no graphics card with a VRAM and a length.');

        Listing::factory()->create([
            'user_id'         => User::factory()->create()->id,
            'part_id'         => $part->id,
            'category'        => 'gpu',
            'status'          => ListingStatus::Active,
            'min_offer_cents' => null,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee($part->specs['vram_gb'].' GB', false)
            ->assertSee($part->specs['length_mm'].' мм', false);
    }
}
