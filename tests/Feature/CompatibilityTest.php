<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\ShowListing;
use App\Livewire\ShowPart;
use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Support\Compatibility;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „Тази карта иска захранване от 750 W нагоре" — with the words as a link.
 *
 * This is the one feature on the site that a general classifieds board cannot
 * copy without first building a typed catalogue: OLX knows a listing is called
 * „RTX 4070", but not that the card is 200 W, so it cannot walk from there to a
 * power supply. We store both numbers already, and the facet filters already
 * know how to query them, so the link is only those two facts pointed at each
 * other.
 *
 * Two kinds of test here, and the first kind matters more. The config is the
 * feature; a rule naming a spec that does not exist, or a range filter pointed
 * at a terms facet, does not throw - it silently produces a link to unfiltered
 * results, which is a confident recommendation of the wrong hardware.
 */
class CompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
    }

    /** @param  array<string, mixed>  $specs */
    private function part(string $category, array $specs, string $model): Part
    {
        return Part::create([
            'category'     => $category,
            'manufacturer' => 'Test',
            'model'        => $model,
            'slug'         => \Illuminate\Support\Str::slug($category.'-'.$model),
            'specs'        => $specs,
            'is_published' => true,
        ]);
    }

    private function listing(Part $part, string $title): Listing
    {
        return Listing::factory()->create([
            'user_id'  => User::factory()->create()->id,
            'part_id'  => $part->id,
            'category' => $part->category,
            'status'   => ListingStatus::Active,
            'city_id'  => City::first()->id,
            'title'    => $title,
        ]);
    }

    // --- the config is the feature ----------------------------------------

    /**
     * Every rule points at specs that exist, on categories that exist, with an
     * operator the facet can actually carry.
     *
     * A `min` on a terms facet is the quiet failure this guards: SpecFilter
     * dispatches on the target spec's declared facet, so the range payload
     * would be handed to the terms branch, stringified, and matched against
     * nothing - producing a link that returns an empty page, or worse, one
     * that drops the filter and returns every power supply on the site.
     */
    public function test_every_rule_is_wired_to_specs_that_exist(): void
    {
        foreach (config('compatibility') as $from => $rules) {
            $this->assertArrayHasKey($from, config('catalog.categories'),
                "[{$from}] is not a category");

            foreach ($rules as $i => $rule) {
                $where = "{$from}[{$i}]";

                $this->assertArrayHasKey($rule['to'], config('catalog.categories'),
                    "{$where}: target `{$rule['to']}` is not a category");

                $sourceSpec = config("catalog.categories.{$from}.specs.{$rule['from']}");
                $this->assertNotNull($sourceSpec,
                    "{$where}: `{$rule['from']}` is not a spec of {$from}");

                /*
                 * Part-scoped only. A listing-scoped spec belongs to the unit
                 * in the photographs, and compatibility advice that changes
                 * depending on which listing you opened is not advice.
                 */
                $this->assertSame('part', $sourceSpec['scope'] ?? 'part',
                    "{$where}: `{$rule['from']}` is listing-scoped");

                $targetSpec = config("catalog.categories.{$rule['to']}.specs.{$rule['facet']}");
                $this->assertNotNull($targetSpec,
                    "{$where}: `{$rule['facet']}` is not a spec of {$rule['to']}");

                $this->assertArrayHasKey('facet', $targetSpec,
                    "{$where}: `{$rule['facet']}` is not facetable, so the browse page will ignore it");

                $expected = in_array($rule['op'], ['min', 'max'], true) ? 'range' : 'terms';
                $this->assertSame($expected, $targetSpec['facet'],
                    "{$where}: op `{$rule['op']}` needs a {$expected} facet");
            }
        }
    }

    /** Arithmetic on a socket name is nonsense; the config must not ask for it. */
    public function test_only_numeric_sources_carry_arithmetic(): void
    {
        foreach (config('compatibility') as $from => $rules) {
            foreach ($rules as $i => $rule) {
                if (! isset($rule['transform']) && ! isset($rule['from_min'])) {
                    continue;
                }

                $type = config("catalog.categories.{$from}.specs.{$rule['from']}.type");

                $this->assertContains($type, ['int', 'decimal'],
                    "{$from}[{$i}]: `{$rule['from']}` is {$type}, which cannot be transformed");
            }
        }
    }

    /** A placeholder that is never substituted is printed to the user raw. */
    public function test_every_placeholder_is_one_the_renderer_substitutes(): void
    {
        foreach (config('compatibility') as $from => $rules) {
            foreach ($rules as $i => $rule) {
                foreach (['bg', 'why'] as $key) {
                    if (! isset($rule[$key])) {
                        continue;
                    }

                    $this->assertNotSame('', trim($rule[$key]), "{$from}[{$i}]: `{$key}` is blank");

                    preg_match_all('/:[a-z_]+/', $rule[$key], $found);

                    foreach ($found[0] as $placeholder) {
                        $this->assertContains($placeholder, [':from', ':value'],
                            "{$from}[{$i}]: `{$key}` uses {$placeholder}, which nothing replaces");
                    }
                }
            }
        }
    }

    // --- what it produces -------------------------------------------------

    public function test_a_card_asks_for_a_power_supply_with_headroom(): void
    {
        $gpu = $this->part('gpu', ['tdp_w' => 285, 'length_mm' => 336], 'Oracle 9999');
        $psu = $this->part('psu', ['wattage' => 850], 'Volt 850');
        $this->listing($psu, 'Захранване 850W');

        $links = collect(Compatibility::forPart($gpu));
        $power = $links->firstWhere('category', 'psu');

        $this->assertNotNull($power, 'a 285 W card produced no power-supply link');

        // 285 + 200 of headroom = 485, rounded up to a wattage people sell.
        $this->assertStringContainsString('500 W', $power['label']);
        $this->assertStringContainsString('285', $power['why']);
        $this->assertSame(1, $power['count']);
    }

    /**
     * The link is followed, not merely built.
     *
     * This is the assertion that catches a facet wired to the wrong operator:
     * the URL is handed to the real browse page, and the wrong power supply
     * must not come back.
     */
    public function test_following_the_link_filters_the_way_it_promises(): void
    {
        $gpu = $this->part('gpu', ['tdp_w' => 285], 'Oracle 9999');

        $this->listing($this->part('psu', ['wattage' => 850], 'Volt 850'), 'Голямото захранване');
        $this->listing($this->part('psu', ['wattage' => 450], 'Volt 450'), 'Малкото захранване');

        $url = collect(Compatibility::forPart($gpu))->firstWhere('category', 'psu')['url'];

        $this->get($url)
            ->assertOk()
            ->assertSee('Голямото захранване')
            ->assertDontSee('Малкото захранване');
    }

    /**
     * A link to an empty result page teaches a visitor the site is empty, and
     * that lesson is expensive to unteach. So an edge with nothing behind it
     * is not shown at all.
     */
    public function test_an_edge_with_nothing_behind_it_is_dropped(): void
    {
        $gpu = $this->part('gpu', ['tdp_w' => 285, 'length_mm' => 336], 'Oracle 9999');

        $this->assertSame([], Compatibility::forPart($gpu));
    }

    /** Most parts do not carry every spec, and a filter built from a null
     *  matches everything - which reads as a recommendation. */
    public function test_a_missing_source_spec_produces_no_link(): void
    {
        $gpu = $this->part('gpu', ['vram_gb' => 12], 'Oracle 9999');
        $this->listing($this->part('psu', ['wattage' => 850], 'Volt 850'), 'Захранване');

        $this->assertSame([], Compatibility::forPart($gpu));
    }

    /**
     * from_min. A 300 W office unit would otherwise advertise "cards up to
     * 100 W", which is true, useless, and makes the site look stupid.
     */
    public function test_a_small_power_supply_recommends_no_cards(): void
    {
        $small = $this->part('psu', ['wattage' => 300], 'Volt 300');
        $this->listing($this->part('gpu', ['tdp_w' => 75], 'Tiny 1'), 'Малка карта');

        $this->assertSame([], Compatibility::forPart($small));
    }

    public function test_a_big_power_supply_does_recommend_cards(): void
    {
        $big = $this->part('psu', ['wattage' => 850], 'Volt 850');
        $this->listing($this->part('gpu', ['tdp_w' => 285], 'Oracle 9999'), 'Голямата карта');

        $link = collect(Compatibility::forPart($big))->firstWhere('category', 'gpu');

        $this->assertNotNull($link);
        $this->assertStringContainsString('650 W', $link['label']);
    }

    /**
     * A multiselect source. A cooler lists every socket it mounts on, and the
     * terms filter turns that list into an OR - so one cooler links to the
     * processors of two platforms at once.
     */
    public function test_a_list_valued_spec_links_to_all_of_its_values(): void
    {
        $cooler = $this->part('cooler', ['sockets' => ['AM4', 'LGA1700'], 'height_mm' => 158], 'Tower X');

        $this->listing($this->part('cpu', ['socket' => 'AM4'], 'Chip A'), 'Процесор AM4');
        $this->listing($this->part('cpu', ['socket' => 'LGA1700'], 'Chip B'), 'Процесор LGA1700');
        $this->listing($this->part('cpu', ['socket' => 'AM5'], 'Chip C'), 'Процесор AM5');

        $link = collect(Compatibility::forPart($cooler))->firstWhere('category', 'cpu');

        $this->assertNotNull($link);
        $this->assertSame(2, $link['count']);

        $this->get($link['url'])
            ->assertSee('Процесор AM4')
            ->assertSee('Процесор LGA1700')
            ->assertDontSee('Процесор AM5');
    }

    /** Free-text listings are most of a young marketplace and must not error. */
    public function test_a_listing_with_no_catalogue_model_says_nothing(): void
    {
        $listing = Listing::factory()->create([
            'user_id'  => User::factory()->create()->id,
            'part_id'  => null,
            'category' => 'other',
            'status'   => ListingStatus::Active,
            'city_id'  => City::first()->id,
        ]);

        $this->assertSame([], Compatibility::forListing($listing));
    }

    // --- where it appears -------------------------------------------------

    public function test_the_model_page_carries_the_links(): void
    {
        $gpu = $this->part('gpu', ['tdp_w' => 285], 'Oracle 9999');
        $this->listing($this->part('psu', ['wattage' => 850], 'Volt 850'), 'Захранване 850W');

        Livewire::test(ShowPart::class, ['part' => $gpu])
            ->assertSee('Какво пасва с този модел')
            ->assertSee('Захранвания от 500 W нагоре');
    }

    public function test_the_listing_page_carries_the_links(): void
    {
        $gpu = $this->part('gpu', ['tdp_w' => 285], 'Oracle 9999');
        $this->listing($this->part('psu', ['wattage' => 850], 'Volt 850'), 'Захранване 850W');

        $listing = $this->listing($gpu, 'Видеокартата');

        Livewire::test(ShowListing::class, ['listing' => $listing])
            ->assertSee('Захранвания от 500 W нагоре');
    }

    /** Nothing to say, nothing rendered - not an empty box with a heading. */
    public function test_a_model_with_no_edges_shows_no_section(): void
    {
        $mouse = $this->part('mouse', ['dpi' => 16000], 'Clicker 1');

        Livewire::test(ShowPart::class, ['part' => $mouse])
            ->assertDontSee('Какво пасва с този модел');
    }
}
