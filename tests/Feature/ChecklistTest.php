<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\ShowListing;
use App\Livewire\ShowPart;
use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Support\Checklist;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „Какво да провериш" — the annotation layer on the facet schema.
 *
 * The facets already name the risks: repadded, has_all_cables, power_on_hours,
 * dead_pixels, has_mounting all exist because something goes wrong when they
 * are wrong. A buyer who does not already know that reads them as trivia. The
 * behaviour worth testing is the bit that makes this more than a static
 * paragraph: a facet the seller left blank turns into the question to ask.
 */
class ChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller = User::factory()->create();
    }

    private function listing(string $category, array $specs = []): Listing
    {
        return Listing::factory()->create([
            'user_id'  => $this->seller->id,
            'status'   => ListingStatus::Active,
            'category' => $category,
            'specs'    => $specs,
            'city_id'  => City::first()->id,
        ]);
    }

    // --- the config itself ------------------------------------------------

    /**
     * Every category in the catalogue has a checklist, and every item in every
     * checklist is shaped the way the renderer expects. A category quietly
     * without one shows an empty box; an item with a `missing` and no `spec`
     * is a line that can never fire.
     */
    public function test_every_category_has_a_well_formed_checklist(): void
    {
        foreach (array_keys(config('catalog.categories')) as $category) {
            $items = config("checklists.{$category}");

            $this->assertIsArray($items, "[{$category}] has no checklist");
            $this->assertNotEmpty($items, "[{$category}] has an empty checklist");

            foreach ($items as $i => $item) {
                $where = "{$category}[{$i}]";

                $this->assertArrayHasKey('bg', $item, "{$where} has no text");
                $this->assertNotSame('', trim($item['bg']), "{$where} is blank");

                if (array_key_exists('missing', $item)) {
                    $this->assertArrayHasKey('spec', $item,
                        "{$where} has a `missing` line but no `spec` to trigger it");
                }

                if (isset($item['spec'])) {
                    // A spec that is not in this category's schema can never be
                    // filled, so the item would be permanently stuck on its
                    // "missing" wording and nobody would notice.
                    $this->assertArrayHasKey(
                        $item['spec'],
                        config("catalog.categories.{$category}.specs", []),
                        "{$where} names `{$item['spec']}`, which is not a spec of {$category}",
                    );
                }
            }
        }
    }

    /** Urgent means something only while most items are not urgent. */
    public function test_the_urgent_items_stay_a_minority(): void
    {
        foreach (array_keys(config('catalog.categories')) as $category) {
            $items    = config("checklists.{$category}", []);
            $critical = array_filter($items, fn ($i) => $i['critical'] ?? false);

            $this->assertLessThanOrEqual(
                (int) ceil(count($items) / 2),
                count($critical),
                "[{$category}] marks more than half its items urgent, which makes none of them urgent",
            );
        }
    }

    // --- what a blank facet does ------------------------------------------

    public function test_a_blank_facet_becomes_the_question_to_ask(): void
    {
        $listing = $this->listing('storage', []);          // power_on_hours unanswered

        $lines = collect(Checklist::forListing($listing));

        $gap = $lines->firstWhere('gap', true);

        $this->assertNotNull($gap, 'a blank facet produced no gap line at all');
        $this->assertStringContainsString('CrystalDiskInfo', $gap['text']);
        // A gap is worth reading even if the underlying item was not urgent.
        $this->assertTrue($gap['critical']);
    }

    public function test_an_answered_facet_reads_normally(): void
    {
        $listing = $this->listing('storage', ['power_on_hours' => 4200, 'health_percent' => 96]);

        $lines = collect(Checklist::forListing($listing));

        $this->assertTrue(
            $lines->every(fn ($l) => $l['gap'] === false),
            'an answered facet was still reported as unanswered',
        );
    }

    /**
     * A part-scoped spec is answered by the catalogue, not by the seller, and
     * a buyer cannot tell which is which - both are "is this question answered
     * on the page in front of me". Checking only the listing would mark half
     * the answered facets as gaps.
     */
    public function test_a_spec_answered_by_the_catalogue_counts_as_answered(): void
    {
        $part = Part::where('category', 'psu')->first();

        if (! $part) {
            $this->markTestSkipped('no PSU in the seeded catalogue');
        }

        $part->forceFill(['specs' => ($part->specs ?? []) + ['has_all_cables' => true]])->save();

        $listing = $this->listing('psu', []);
        $listing->forceFill(['part_id' => $part->id])->save();

        $cables = collect(Checklist::forListing($listing->fresh()))
            ->first(fn ($l) => str_contains($l['text'], 'кабел'));

        $this->assertNotNull($cables);
        $this->assertFalse($cables['gap'], 'a spec the catalogue answers was reported as a gap');
    }

    /**
     * An empty `missing` means the line only makes sense when the spec IS set.
     * Telling somebody to check ECC support on a kit with no ECC is noise.
     */
    public function test_an_item_with_an_empty_missing_line_is_dropped(): void
    {
        $withEcc    = collect(Checklist::forListing($this->listing('ram', ['ecc' => true])));
        $withoutEcc = collect(Checklist::forListing($this->listing('ram', [])));

        $this->assertTrue($withEcc->contains(fn ($l) => str_contains($l['text'], 'ECC')));
        $this->assertFalse($withoutEcc->contains(fn ($l) => str_contains($l['text'], 'ECC')));
    }

    public function test_the_generic_list_marks_nothing_as_missing(): void
    {
        $lines = collect(Checklist::forCategory('storage'));

        $this->assertNotEmpty($lines);
        $this->assertTrue($lines->every(fn ($l) => $l['gap'] === false));
    }

    // --- where it appears -------------------------------------------------

    public function test_the_listing_page_carries_the_checklist(): void
    {
        $listing = $this->listing('gpu', []);

        Livewire::test(ShowListing::class, ['listing' => $listing])
            ->assertSee('Какво да провериш')
            ->assertSee('артефакти');
    }

    /**
     * The page worth the most to a search engine. Somebody searching
     * "на какво да внимавам" is a buyer at the moment of deciding, and a
     * listing cannot answer them - it is one asking price and it disappears
     * when the card sells.
     */
    public function test_the_part_page_carries_the_generic_checklist(): void
    {
        $part = Part::where('category', 'gpu')->firstOrFail();

        Livewire::test(ShowPart::class, ['part' => $part])
            ->assertSee('Какво да провериш при втора употреба')
            ->assertSee('артефакти');
    }

    /** Same words to the seller, because that IS the brief for a good listing. */
    public function test_the_wizard_shows_the_seller_what_buyers_will_check(): void
    {
        Livewire::actingAs($this->seller)
            ->test(\App\Livewire\Listings\CreateListing::class)
            ->set('category', 'gpu')
            ->call('next')
            ->call('next')
            ->assertSet('step', 3)
            ->assertSee('Какво ще проверят купувачите')
            ->assertSee('артефакти');
    }
}
