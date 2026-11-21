<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Support\PartAliases;
use App\Support\PartImporter;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalogue importer: adds models from a file of facts, never overwrites,
 * never publishes unasked, and keeps only spec values the schema allows.
 */
class PartImportTest extends TestCase
{
    use RefreshDatabase;

    private function record(array $over = []): array
    {
        return array_replace_recursive([
            'manufacturer' => 'NVIDIA',
            'model'        => 'GeForce RTX 5050 8GB',
            'launch_year'  => 2025,
            'specs'        => [
                'chipset'          => 'RTX 5050',
                'vram_gb'          => 8,
                'vram_type'        => 'GDDR6',
                'tdp_w'            => 130,
                'slot_width'       => '2',
                'power_connectors' => '1x 8-pin',
            ],
        ], $over);
    }

    private function run_(array $records, bool $publish = false, bool $dry = false): array
    {
        return (new PartImporter('gpu', $publish))->import($records, $dry);
    }

    public function test_a_new_model_is_created_unpublished_with_clean_specs_and_aliases(): void
    {
        $report = $this->run_([$this->record()]);

        $this->assertSame(['GeForce RTX 5050 8GB'], $report['created']);

        $part = Part::where('category', 'gpu')->where('model', 'GeForce RTX 5050 8GB')->firstOrFail();

        $this->assertFalse($part->is_published);
        $this->assertSame('nvidia-geforce-rtx-5050-8gb', $part->slug);
        $this->assertSame(2025, (int) $part->launch_year);
        $this->assertSame(8, $part->specs['vram_gb']);
        $this->assertSame('RTX 5050', $part->specs['chipset']);
        $this->assertContains('rtx 5050 8gb', $part->aliases);
        $this->assertContains('ртх 5050 8gb', $part->aliases);
    }

    public function test_publish_flag_publishes(): void
    {
        $this->run_([$this->record()], publish: true);

        $this->assertTrue(Part::where('model', 'GeForce RTX 5050 8GB')->firstOrFail()->is_published);
    }

    public function test_a_dry_run_writes_nothing_but_reports_the_same(): void
    {
        $report = $this->run_([$this->record()], dry: true);

        $this->assertSame(['GeForce RTX 5050 8GB'], $report['created']);
        $this->assertDatabaseCount('parts', 0);
    }

    public function test_running_it_twice_adds_nothing_the_second_time(): void
    {
        $this->run_([$this->record()]);
        $second = $this->run_([$this->record()]);

        $this->assertSame([], $second['created']);
        $this->assertSame(['GeForce RTX 5050 8GB'], $second['skipped']['exists']);
        $this->assertDatabaseCount('parts', 1);
    }

    public function test_the_same_card_spelled_with_a_space_before_gb_is_already_there(): void
    {
        $this->seed(PartSeeder::class);
        $before = Part::count();

        $seeded = Part::where('category', 'gpu')->where('model', 'GeForce RTX 3060 12GB')->firstOrFail();

        $report = $this->run_([$this->record([
            'model' => 'GeForce RTX 3060 12 GB',
            'specs' => ['chipset' => 'RTX 3060', 'vram_gb' => 12, 'vram_type' => 'GDDR6'],
        ])]);

        $this->assertSame([], $report['created']);
        $this->assertSame($before, Part::count());
        $this->assertSame($seeded->specs, $seeded->fresh()->specs);
    }

    public function test_same_chipset_memory_and_type_under_a_different_name_is_skipped(): void
    {
        $this->run_([$this->record()]);

        $report = $this->run_([$this->record(['model' => 'GeForce RTX 5050 Something Else'])]);

        $this->assertSame(['GeForce RTX 5050 Something Else'], $report['skipped']['same-card']);
    }

    public function test_a_different_memory_size_is_a_different_card(): void
    {
        $this->run_([$this->record()]);

        $report = $this->run_([$this->record([
            'model' => 'GeForce RTX 5050 16GB',
            'specs' => ['vram_gb' => 16],
        ])]);

        $this->assertSame(['GeForce RTX 5050 16GB'], $report['created']);
    }

    public function test_an_existing_row_is_never_overwritten(): void
    {
        Part::create([
            'category' => 'gpu', 'manufacturer' => 'NVIDIA', 'model' => 'GeForce RTX 5050 8GB',
            'slug' => 'nvidia-geforce-rtx-5050-8gb', 'specs' => ['chipset' => 'RTX 5050', 'vram_gb' => 8, 'tdp_w' => 999],
            'aliases' => [], 'is_published' => true,
        ]);

        $this->run_([$this->record()]);

        $part = Part::where('slug', 'nvidia-geforce-rtx-5050-8gb')->firstOrFail();
        $this->assertSame(999, $part->specs['tdp_w']);
        $this->assertTrue($part->is_published);
    }

    public function test_values_outside_the_schema_are_dropped_and_the_row_is_kept(): void
    {
        $report = $this->run_([$this->record(['specs' => [
            'vram_type'        => 'GDDR9',
            'power_connectors' => '1x 12-pin',
            'tdp_w'            => 'lots',
            'colour'           => 'red',
        ]])]);

        $part = Part::where('model', 'GeForce RTX 5050 8GB')->firstOrFail();

        $this->assertArrayNotHasKey('vram_type', $part->specs);
        $this->assertArrayNotHasKey('power_connectors', $part->specs);
        $this->assertArrayNotHasKey('tdp_w', $part->specs);
        $this->assertArrayNotHasKey('colour', $part->specs);
        $this->assertSame(8, $part->specs['vram_gb']);
        $this->assertEqualsCanonicalizing(
            ['vram_type', 'power_connectors', 'tdp_w', 'colour'],
            $report['dropped']['GeForce RTX 5050 8GB'],
        );
    }

    public function test_a_record_without_chipset_or_memory_is_skipped(): void
    {
        $report = $this->run_([
            $this->record(['model' => 'No Chipset', 'specs' => ['chipset' => '']]),
            $this->record(['model' => 'No Memory', 'specs' => ['vram_gb' => 7]]),   // 7 GB is not a size the filter offers
            ['manufacturer' => '', 'model' => 'No Maker'],
        ]);

        $this->assertContains('No Chipset', $report['skipped']['invalid']);
        $this->assertContains('No Maker', $report['skipped']['invalid']);
        $this->assertContains('No Memory', $report['skipped']['invalid']);
    }

    public function test_a_file_that_lists_one_card_twice_adds_it_once(): void
    {
        $report = $this->run_([$this->record(), $this->record()]);

        $this->assertCount(1, $report['created']);
        $this->assertDatabaseCount('parts', 1);
    }

    public function test_an_imported_part_is_found_by_the_listing_forms_search_in_either_alphabet(): void
    {
        $this->run_([$this->record()]);

        $this->assertTrue(Part::where('category', 'gpu')->search('rtx 5050')->exists());
        $this->assertTrue(Part::where('category', 'gpu')->search('ртх 5050')->exists());
        $this->assertTrue(Part::where('category', 'gpu')->search('5050')->exists());
    }

    public function test_aliases_cover_the_ways_a_bulgarian_types_a_card(): void
    {
        $aliases = PartAliases::for('GeForce RTX 4090');

        foreach (['rtx 4090', 'rtx4090', 'ртх 4090', 'ртх4090'] as $want) {
            $this->assertContains($want, $aliases, "missing alias: {$want}");
        }
    }

    // --- the file that ships -------------------------------------------------

    public function test_the_shipped_gpu_file_is_valid_and_every_record_is_importable(): void
    {
        $records = json_decode((string) file_get_contents(database_path('data/gpus.json')), true);

        $this->assertIsArray($records);
        $this->assertGreaterThan(50, count($records));

        $models = array_column($records, 'model');
        $this->assertSame(count($models), count(array_unique($models)), 'duplicate model names in the file');

        $report = $this->run_($records);

        $this->assertSame([], $report['skipped']['invalid'] ?? [], 'records the importer cannot use');
        $this->assertSame([], $report['dropped'], 'spec values the schema does not allow');
    }

    public function test_the_shipped_file_adds_to_the_seeded_catalogue_without_touching_it(): void
    {
        $this->seed(PartSeeder::class);
        $seeded = Part::where('category', 'gpu')->pluck('specs', 'id')->all();

        $records = json_decode((string) file_get_contents(database_path('data/gpus.json')), true);
        $report = $this->run_($records);

        $this->assertNotEmpty($report['created']);
        foreach ($seeded as $id => $specs) {
            $this->assertSame($specs, Part::find($id)->specs);
        }
        $this->assertSame(0, Part::where('category', 'gpu')->where('id', '>', max(array_keys($seeded)))->where('is_published', true)->count());
    }

    public function test_the_command_dry_run_and_real_run(): void
    {
        $this->artisan('parts:import', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('parts', 0);

        $this->artisan('parts:import')->assertSuccessful();
        $this->assertGreaterThan(50, Part::count());

        $this->artisan('parts:import')->assertSuccessful();   // idempotent
    }

    public function test_the_command_rejects_an_unknown_category_and_a_missing_file(): void
    {
        $this->artisan('parts:import', ['--category' => 'nope'])->assertFailed();
        $this->artisan('parts:import', ['file' => 'database/data/missing.json'])->assertFailed();
    }
}
