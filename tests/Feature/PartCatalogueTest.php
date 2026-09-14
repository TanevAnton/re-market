<?php

namespace Tests\Feature;

use App\Models\Part;
use Database\Seeders\PartCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The other eleven categories of the catalogue.
 *
 * Until now PartSeeder covered GPUs and CPUs, so thirteen categories had no
 * `/model/{slug}` page, could never show a price band, and gave a listing
 * nothing to attach to - which meant their specs stayed free text and their
 * filters matched nothing. The catalogue pages are the organic-search play;
 * running it on two categories runs it on a fraction of the site.
 *
 * The test that matters here is the schema one. A seeded value outside a
 * spec's `options` list does not error anywhere - it simply never matches the
 * facet it was meant to populate, which is the quietest possible way for a
 * filter to be broken. Writing this test is how the Odyssey G8's 175 Hz was
 * found sitting outside a refresh-rate list that stopped at 165 and jumped to
 * 180.
 */
class PartCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /** The categories this seeder claims. laptop and prebuilt are excluded by design. */
    private const SEEDED = [
        'motherboard', 'ram', 'psu', 'storage', 'monitor',
        'cooler', 'case', 'keyboard', 'mouse', 'headset', 'console',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PartCatalogueSeeder::class);
    }

    public function test_every_claimed_category_has_parts(): void
    {
        foreach (self::SEEDED as $category) {
            $this->assertGreaterThan(
                0,
                Part::where('category', $category)->count(),
                "[{$category}] has no catalogue parts, so it has no landing pages and no price bands",
            );
        }
    }

    /**
     * Every seeded value is one the schema will actually accept.
     *
     * A value outside an `options` list is invisible: nothing throws, the part
     * saves, and the facet it was supposed to feed quietly never matches it.
     * The same goes for a listing-scoped spec seeded onto a part - it puts a
     * number on the model page that the item in the photograph may not have.
     */
    public function test_every_seeded_spec_is_valid_against_the_schema(): void
    {
        foreach (Part::whereIn('category', self::SEEDED)->cursor() as $part) {
            $schema = config("catalog.categories.{$part->category}.specs", []);
            $where  = "{$part->category}/{$part->model}";

            $this->assertNotEmpty($schema, "[{$part->category}] has no schema at all");

            foreach ($part->specs ?? [] as $key => $value) {
                $this->assertArrayHasKey($key, $schema, "{$where}: unknown spec `{$key}`");

                $def = $schema[$key];

                $this->assertSame(
                    'part',
                    $def['scope'] ?? 'part',
                    "{$where}: `{$key}` is listing-scoped and belongs to the item, not the model",
                );

                if (isset($def['options'])) {
                    foreach (is_array($value) ? $value : [$value] as $one) {
                        $this->assertContains(
                            $one,
                            $def['options'],
                            "{$where}: `{$key}` = ".json_encode($one, JSON_UNESCAPED_UNICODE)
                                .' is outside the options this facet accepts',
                        );
                    }
                }

                match ($def['type'] ?? 'string') {
                    'int'         => $this->assertIsInt($value, "{$where}: `{$key}` is not an int"),
                    'bool'        => $this->assertIsBool($value, "{$where}: `{$key}` is not a bool"),
                    'multiselect' => $this->assertIsArray($value, "{$where}: `{$key}` is not an array"),
                    'select'      => $this->assertFalse(is_array($value), "{$where}: `{$key}` is a select, not a list"),
                    default       => null,
                };
            }

            foreach ($schema as $key => $def) {
                if (($def['required'] ?? false) && ($def['scope'] ?? 'part') === 'part') {
                    $this->assertArrayHasKey(
                        $key,
                        $part->specs ?? [],
                        "{$where}: required spec `{$key}` was not seeded",
                    );
                }
            }
        }
    }

    /** The upsert key. Two parts sharing a slug would collide on the unique index. */
    public function test_slugs_are_unique(): void
    {
        $slugs = Part::pluck('slug');

        $this->assertSame($slugs->count(), $slugs->unique()->count());
    }

    /**
     * upsert on the slug, so a second run corrects data rather than
     * duplicating rows - which matters because this seeder WILL be re-run
     * every time a model is added to it.
     */
    public function test_running_the_seeder_twice_changes_nothing(): void
    {
        $before = Part::count();

        $this->seed(PartCatalogueSeeder::class);

        $this->assertSame($before, Part::count());
    }

    /**
     * The payoff, from the side a user sees it.
     *
     * Nobody types "MSI MAG B550 TOMAHAWK". They type "tomahawk", or "b550",
     * or the same thing with a Cyrillic keyboard still switched on - which is
     * a specifically Bulgarian failure mode and the reason aliases exist.
     */
    public function test_the_things_people_actually_type_find_the_part(): void
    {
        foreach (['tomahawk', 'b550 tomahawk', 'томахоук'] as $term) {
            $this->assertNotNull(
                Part::where('category', 'motherboard')->search($term)->first(),
                "searching [{$term}] found no motherboard",
            );
        }

        $this->assertNotNull(Part::where('category', 'console')->search('ps5')->first()
            ?? Part::where('category', 'console')->search('playstation 5')->first());

        $this->assertNotNull(Part::where('category', 'storage')->search('990 pro')->first());
    }

    /**
     * `prebuilt` cannot be catalogued, and that is a fact rather than an
     * opinion: it has no part-scoped specs at all. Every single thing the
     * schema records about a complete PC belongs to the individual machine.
     *
     * `laptop` is NOT asserted here. It has four part-scoped specs, so the
     * schema would allow a catalogue - leaving it out is a judgement about
     * whether a row per SKU would earn its keep, and a judgement does not
     * belong in an assertion. The reasoning lives in the seeder.
     */
    public function test_prebuilts_have_nothing_a_catalogue_could_hold(): void
    {
        $partScoped = array_filter(
            config('catalog.categories.prebuilt.specs', []),
            fn ($def) => ($def['scope'] ?? 'part') === 'part',
        );

        $this->assertSame([], $partScoped);
    }
}
