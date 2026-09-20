<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\AppleSection;
use App\Livewire\Listings\CreateListing;
use App\Livewire\ShowPart;
use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Support\Checklist;
use Database\Seeders\AppleCatalogueSeeder;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * iPhone, iPad and MacBook — three categories and one door in front of them.
 *
 * The interesting part of this feature is not that three categories exist; it
 * is the schema, and specifically WHERE THE LINE BETWEEN MODEL AND UNIT FALLS.
 * Storage and RAM belong to the catalogue row because on Apple hardware they
 * are soldered and they are most of the price — a 128GB and a 1TB iPhone 13 Pro
 * sharing one median would give a useless number to both sides of every trade.
 * Battery health and iCloud status belong to the individual device.
 *
 * The other half is the risk surface, which is genuinely different from a
 * graphics card: a card cannot be remotely bricked, and a MacBook from a
 * company liquidation re-enrols itself into that company's management after
 * every wipe. `required` on a listing-scoped spec is enforced nowhere, so the
 * checklist is what actually makes a seller answer — which is why the tests
 * below check the checklist and not the flag.
 */
class AppleSectionTest extends TestCase
{
    use RefreshDatabase;

    private const LINES = ['iphone', 'ipad', 'macbook'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
    }

    private function listing(string $category, array $attributes = []): Listing
    {
        return Listing::factory()->create([
            'user_id'  => User::factory()->create()->id,
            'category' => $category,
            'part_id'  => null,
            'status'   => ListingStatus::Active,
            'city_id'  => City::first()->id,
            ...$attributes,
        ]);
    }

    // --- the schema, which is the actual feature --------------------------

    /**
     * The line between model and unit.
     *
     * Soldered means the configuration IS the product: a buyer cannot change it
     * later, which is why they filter on it this hard and why it has to be part
     * of the catalogue row rather than a note on one listing.
     */
    public function test_what_is_soldered_belongs_to_the_model(): void
    {
        foreach (['iphone' => ['storage_gb'], 'ipad' => ['storage_gb', 'cellular'],
                  'macbook' => ['ram_gb', 'storage_gb', 'chip']] as $category => $keys) {
            foreach ($keys as $key) {
                $spec = config("catalog.categories.{$category}.specs.{$key}");

                $this->assertNotNull($spec, "[{$category}] has no `{$key}`");
                $this->assertSame('part', $spec['scope'] ?? 'part',
                    "[{$category}.{$key}] is a fact about the model, not about one unit");
            }
        }
    }

    /**
     * And what wears out belongs to the unit. Battery health on a catalogue row
     * would put a number on the model page that the device in the photographs
     * does not have — the same mistake the seeding rules already warn about.
     */
    public function test_what_wears_out_belongs_to_the_unit(): void
    {
        foreach (['iphone' => ['battery_health', 'icloud_signed_out', 'parts_status'],
                  'ipad'    => ['icloud_signed_out', 'parts_status'],
                  'macbook' => ['battery_cycles', 'icloud_signed_out', 'mdm_free']] as $category => $keys) {
            foreach ($keys as $key) {
                $spec = config("catalog.categories.{$category}.specs.{$key}");

                $this->assertNotNull($spec, "[{$category}] has no `{$key}`");
                $this->assertSame('listing', $spec['scope'] ?? 'part',
                    "[{$category}.{$key}] belongs to the device in the photographs");
            }
        }
    }

    /**
     * The risks that make this category different, each with a checklist line
     * pointing at its spec.
     *
     * `required` on a listing-scoped spec USED TO BE decorative — the wizard
     * validated the scalar fields and left the spec blob alone. `RequiredSpecs`
     * enforces it now, and `icloud_signed_out` is required on all three
     * categories as of 20 Sep.
     *
     * The checklist still matters for everything that is NOT required, which is
     * most of this schema: it turns a blank into „без отговор" rather than
     * silence. A schema risk with no checklist line behind it and no `required`
     * flag is a question nobody ever gets asked.
     */
    public function test_every_apple_specific_risk_has_a_checklist_line_behind_it(): void
    {
        $required = [
            'iphone'  => ['icloud_signed_out', 'battery_health', 'parts_status', 'network_locked'],
            'ipad'    => ['icloud_signed_out', 'parts_status'],
            'macbook' => ['icloud_signed_out', 'mdm_free', 'battery_cycles', 'parts_status'],
        ];

        foreach ($required as $category => $specs) {
            $covered = collect(config("checklists.{$category}"))
                ->filter(fn ($item) => array_key_exists('missing', $item) && ($item['missing'] ?? '') !== '')
                ->pluck('spec')
                ->all();

            foreach ($specs as $spec) {
                $this->assertContains($spec, $covered,
                    "[{$category}] has no checklist line that turns a blank `{$spec}` into a question");
            }
        }
    }

    // --- the catalogue ----------------------------------------------------

    public function test_the_seeder_produces_one_row_per_configuration(): void
    {
        $this->seed(AppleCatalogueSeeder::class);

        foreach (self::LINES as $category) {
            $this->assertGreaterThan(20, Part::where('category', $category)->count(),
                "[{$category}] has too few configurations to be a catalogue");
        }

        // The same model at two capacities is two rows with two bands, which is
        // the whole reason storage sits in the variant.
        $capacities = Part::where('model', 'iPhone 13 Pro')->pluck('variant');

        $this->assertGreaterThan(1, $capacities->count());
        $this->assertSame($capacities->count(), $capacities->unique()->count());
    }

    public function test_every_seeded_spec_is_valid_against_the_schema(): void
    {
        $this->seed(AppleCatalogueSeeder::class);

        foreach (Part::whereIn('category', self::LINES)->cursor() as $part) {
            $schema = config("catalog.categories.{$part->category}.specs", []);
            $where  = "{$part->category}/{$part->fullName()}";

            foreach ($part->specs ?? [] as $key => $value) {
                $this->assertArrayHasKey($key, $schema, "{$where}: unknown spec `{$key}`");
                $this->assertSame('part', $schema[$key]['scope'] ?? 'part',
                    "{$where}: `{$key}` is listing-scoped and belongs to the device");

                if (isset($schema[$key]['options'])) {
                    foreach (is_array($value) ? $value : [$value] as $one) {
                        // assertEquals, not assertSame: json_encode writes a
                        // whole float as an int, so 16.0" comes back from jsonb
                        // as the integer 16 and a strict check against the
                        // option list would fail on correct data.
                        $this->assertTrue(
                            in_array($one, $schema[$key]['options'], false),
                            "{$where}: `{$key}` = ".json_encode($one, JSON_UNESCAPED_UNICODE).' is outside its options',
                        );
                    }
                }
            }

            foreach ($schema as $key => $definition) {
                if (($definition['required'] ?? false) && ($definition['scope'] ?? 'part') === 'part') {
                    $this->assertArrayHasKey($key, $part->specs ?? [],
                        "{$where}: required spec `{$key}` was not seeded");
                }
            }
        }
    }

    public function test_running_the_seeder_twice_changes_nothing(): void
    {
        $this->seed(AppleCatalogueSeeder::class);
        $before = Part::count();

        $this->seed(AppleCatalogueSeeder::class);

        $this->assertSame($before, Part::count());
    }

    /**
     * „айфон" has been a Bulgarian loanword for fifteen years and is written in
     * Cyrillic more often than not. Without the twins the whole Apple catalogue
     * is invisible to anybody whose keyboard is where it usually is — which, on
     * a phone in Bulgaria, is most of the time.
     */
    public function test_the_things_people_actually_type_find_the_device(): void
    {
        $this->seed(AppleCatalogueSeeder::class);

        foreach (['13 pro', 'айфон 13 про', 'iphone13'] as $term) {
            $this->assertNotNull(
                Part::where('category', 'iphone')->search($term)->first(),
                "searching [{$term}] found no iPhone",
            );
        }

        $this->assertNotNull(Part::where('category', 'macbook')->search('макбук еър')->first());
        $this->assertNotNull(Part::where('category', 'ipad')->search('айпад')->first());
    }

    // --- the section page -------------------------------------------------

    public function test_the_section_is_public(): void
    {
        $this->get(route('apple'))->assertOk();
    }

    public function test_it_counts_each_line_separately(): void
    {
        $this->listing('iphone');
        $this->listing('iphone');
        $this->listing('macbook');

        $lines = collect(Livewire::test(AppleSection::class)->viewData('lines'))->keyBy('key');

        $this->assertSame(2, $lines['iphone']['listings']);
        $this->assertSame(0, $lines['ipad']['listings']);
        $this->assertSame(1, $lines['macbook']['listings']);
    }

    /** Sold and expired devices are not something a visitor can act on. */
    public function test_only_live_listings_are_counted(): void
    {
        $this->listing('iphone');
        $this->listing('iphone', ['status' => ListingStatus::Sold]);

        $this->assertSame(1, Livewire::test(AppleSection::class)->viewData('total'));
    }

    /**
     * An empty section says so and offers a standing alert, rather than showing
     * three zeroes. Same rule as the catalogue pages: a visitor who learns the
     * site is empty is the hardest one to get back.
     */
    public function test_an_empty_section_offers_something_rather_than_zeroes(): void
    {
        Livewire::test(AppleSection::class)
            ->assertSee('Още няма обяви тук')
            ->assertSee('Запази търсене');
    }

    public function test_it_only_promotes_models_that_have_something_live(): void
    {
        $this->seed(AppleCatalogueSeeder::class);

        $this->assertCount(0, Livewire::test(AppleSection::class)->viewData('busiest'),
            'a catalogue with no listings behind it was promoted as „най-търсени"');
    }

    // --- the payoff a buyer sees ------------------------------------------

    public function test_a_macbook_model_page_carries_its_configuration(): void
    {
        $this->seed(AppleCatalogueSeeder::class);

        $part = Part::where('category', 'macbook')->firstOrFail();

        Livewire::test(ShowPart::class, ['part' => $part])
            ->assertSee('Спецификации')
            ->assertSee($part->fullName());
    }

    /**
     * The wizard asks the seller the questions this category turns on — but
     * note WHERE it asks them.
     *
     * TWO FIELDS, TWO DIFFERENT MECHANISMS, and the split is the point.
     *
     * The iCloud question is required as of 20 Sep, so it is rendered above
     * „Подробности по желание" and is on screen the moment step 3 loads. The
     * battery is optional, so it lives inside that panel and is not in the DOM
     * until the seller opens it.
     *
     * This test used to assert that BOTH were inside the collapsed panel, and
     * its own docblock ended by saying that whether iCloud should be optional
     * at all was a product question — „a phone locked to someone else's Apple
     * ID is the single most expensive mistake a buyer on this site can make".
     * That question has been answered; this is what the answer looks like from
     * the wizard's side.
     */
    public function test_the_wizard_asks_about_icloud_and_the_battery(): void
    {
        $seller = User::factory()->create();

        $wizard = Livewire::actingAs($seller)
            ->test(CreateListing::class)
            ->set('category', 'iphone')
            ->call('next')
            ->call('next')
            ->assertSet('step', 3);

        // Unprompted, on arrival: the required question and the warning that
        // explains why it is being asked.
        $wizard->assertSee('iCloud / Find My')
               ->assertSee('Apple ID')
               ->assertSee('Здраве на батерията');

        // The optional fields only after the panel is opened. Asserted through
        // the input's own id rather than its label, because the label text for
        // this one also appears in the checklist above it — and a test that
        // passes on the checklist while the field is missing is a test that
        // proves nothing.
        $wizard->call('toggleOptional')
               ->assertSee('spec-battery_health');
    }

    /**
     * And a blank answer becomes the question to ask, which is the only
     * mechanism that actually holds here.
     */
    public function test_an_unanswered_icloud_question_becomes_a_warning(): void
    {
        $listing = $this->listing('iphone', ['specs' => []]);

        $gap = collect(Checklist::forListing($listing))
            ->first(fn ($line) => $line['gap'] && str_contains($line['text'], 'iCloud'));

        $this->assertNotNull($gap, 'a listing that says nothing about iCloud raised no question');
        $this->assertTrue($gap['critical']);
    }

    public function test_an_unanswered_mdm_question_becomes_a_warning(): void
    {
        $listing = $this->listing('macbook', ['specs' => []]);

        $gap = collect(Checklist::forListing($listing))
            ->first(fn ($line) => $line['gap'] && str_contains($line['text'], 'фирмена регистрация'));

        $this->assertNotNull($gap, 'a MacBook that says nothing about MDM raised no question');
    }
}
