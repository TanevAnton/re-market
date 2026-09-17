<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\BuildGuide;
use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Support\BuildPlanner;
use App\Support\Compatibility;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „Сглоби компютър от втора употреба" — the site's own argument, as a page.
 *
 * The thing worth defending is not that the page renders. It is that the
 * machines on it are REAL: every part live on the site right now, and every
 * pairing allowed by the same rules the listing pages already quote. A showcase
 * that proposes a motherboard the visitor's processor cannot sit in is worse
 * than no showcase, because the site has spent every other page claiming it
 * knows the difference.
 *
 * So the tests below are mostly about refusal — the planner leaving a slot
 * empty rather than filling it with something that does not fit, and never
 * reaching for a listing a visitor cannot open.
 */
class BuildGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        // The page caches its builds for ten minutes, which is right in
        // production and would make every test after the first one assert
        // against the first one's fixtures.
        Cache::flush();
    }

    private function part(string $category, string $model, array $specs): Part
    {
        return Part::create([
            'category'     => $category,
            'manufacturer' => 'Тест',
            'model'        => $model,
            'slug'         => \Illuminate\Support\Str::slug($category.'-'.$model),
            'specs'        => $specs,
            'is_published' => true,
        ]);
    }

    private function listing(Part $part, int $euros, array $attributes = []): Listing
    {
        return Listing::factory()->create([
            'user_id'     => User::factory()->create()->id,
            'part_id'     => $part->id,
            'category'    => $part->category,
            'city_id'     => City::first()->id,
            'status'      => ListingStatus::Active,
            'price_cents' => $euros * 100,
            ...$attributes,
        ]);
    }

    /**
     * A card, and the parts a machine around it needs. Sockets and wattages are
     * chosen so exactly one of each pair fits, which is what makes the
     * „it refused the wrong one" assertions meaningful.
     */
    private function buildableWorld(): array
    {
        $gpu = $this->part('gpu', 'Карта 200W', ['tdp_w' => 200, 'length_mm' => 240]);
        $cpu = $this->part('cpu', 'Процесор AM4', ['socket' => 'AM4']);

        return [
            'gpu'  => $gpu,
            'cpu'  => $cpu,
            'good' => [
                'motherboard' => $this->part('motherboard', 'Платка AM4', ['socket' => 'AM4', 'ram_type' => 'DDR4', 'form_factor' => 'ATX']),
                'ram'         => $this->part('ram', 'Памет DDR4', ['type' => 'DDR4']),
                'psu'         => $this->part('psu', 'Захранване 750W', ['wattage' => 750]),
            ],
            'bad'  => [
                'motherboard' => $this->part('motherboard', 'Платка AM5', ['socket' => 'AM5', 'ram_type' => 'DDR5', 'form_factor' => 'ATX']),
                'ram'         => $this->part('ram', 'Памет DDR5', ['type' => 'DDR5']),
                'psu'         => $this->part('psu', 'Захранване 400W', ['wattage' => 400]),
            ],
        ];
    }

    // --- the page ---------------------------------------------------------

    public function test_the_page_is_public(): void
    {
        $this->get(route('build'))->assertOk();
    }

    /**
     * Nothing to show is the normal state on a young marketplace, and it has to
     * say so rather than render three empty frames.
     */
    public function test_an_empty_site_explains_itself_rather_than_showing_blanks(): void
    {
        Livewire::test(BuildGuide::class)
            ->assertOk()
            ->assertSee('Още няма достатъчно обяви');
    }

    /** The rules are printed from config, so the page cannot claim a rule that is not applied. */
    public function test_it_explains_the_rules_it_actually_uses(): void
    {
        $rules = Livewire::test(BuildGuide::class)->viewData('rules');

        $this->assertSame(
            collect(config('compatibility'))->flatten(1)->count(),
            count($rules),
            'the page is showing a different number of rules than the config defines',
        );
    }

    // --- the planner, which is where the risk is --------------------------

    public function test_it_builds_a_machine_from_live_listings(): void
    {
        $w = $this->buildableWorld();

        $this->listing($w['gpu'], 300);
        $this->listing($w['cpu'], 150);
        foreach ($w['good'] as $part) {
            $this->listing($part, 100);
        }

        $builds = BuildPlanner::showcase();

        $this->assertCount(1, $builds);

        $slots = collect($builds[0]['slots'])->keyBy('category');

        foreach (['gpu', 'cpu', 'motherboard', 'ram', 'psu'] as $category) {
            $this->assertNotNull($slots[$category]['listing'],
                "[{$category}] was left empty although a compatible listing exists");
        }

        // 300 + 150 + three at 100.
        $this->assertSame(750_00, $builds[0]['total_cents']);
        $this->assertTrue($builds[0]['complete']);
    }

    /**
     * The whole point. Given only incompatible parts, every constrained slot
     * stays empty — the planner never completes a build by ignoring a rule.
     */
    public function test_it_leaves_a_slot_empty_rather_than_proposing_something_that_does_not_fit(): void
    {
        $w = $this->buildableWorld();

        $this->listing($w['gpu'], 300);
        $this->listing($w['cpu'], 150);

        // Only the wrong socket, the wrong memory and a power supply that
        // cannot carry the card.
        foreach ($w['bad'] as $part) {
            $this->listing($part, 10);
        }

        $slots = collect(BuildPlanner::showcase()[0]['slots'])->keyBy('category');

        foreach (['motherboard', 'ram', 'psu'] as $category) {
            $this->assertNull($slots[$category]['listing'],
                "[{$category}] was filled with a part that violates a compatibility rule");
        }

        $this->assertFalse(BuildPlanner::showcase()[0]['complete']);

        /*
         * And the two kinds of empty are distinguished, because they are not the
         * same news. The board and the power supply have a parent and simply had
         * nothing compatible behind them — a supply problem. Memory is only ever
         * constrained by the board, so with no board there is no question to
         * answer yet.
         *
         * This is the bug the test above found the first time it ran: with the
         * board slot empty, nothing constrained the memory at all and the
         * planner took the cheapest stick of any type, presenting a DDR5 module
         * as part of a machine that had no motherboard in it.
         */
        $this->assertTrue($slots['ram']['blocked'],
            'memory was reported as a shortage when the real answer is that no board was chosen');

        $this->assertFalse($slots['psu']['blocked'],
            'the power supply has its parent (the card) — its empty slot IS a shortage');
        $this->assertFalse($slots['motherboard']['blocked']);
    }

    /** And it picks the right one when both are on the site. */
    public function test_it_chooses_the_compatible_part_over_a_cheaper_incompatible_one(): void
    {
        $w = $this->buildableWorld();

        $this->listing($w['gpu'], 300);
        $this->listing($w['cpu'], 150);

        // The wrong ones are cheaper, and the planner picks cheapest-first —
        // so if the constraint were not applied it would take these.
        foreach ($w['bad'] as $part) {
            $this->listing($part, 10);
        }
        foreach ($w['good'] as $part) {
            $this->listing($part, 200);
        }

        $slots = collect(BuildPlanner::showcase()[0]['slots'])->keyBy('category');

        $this->assertSame($w['good']['motherboard']->id, $slots['motherboard']['listing']->part_id);
        $this->assertSame($w['good']['ram']->id, $slots['ram']['listing']->part_id);
        $this->assertSame($w['good']['psu']->id, $slots['psu']['listing']->part_id);
    }

    /**
     * A build containing a listing the visitor cannot open is worse than a
     * build with a gap in it.
     */
    public function test_sold_and_hidden_listings_are_never_used(): void
    {
        $w = $this->buildableWorld();

        $this->listing($w['gpu'], 300);
        $this->listing($w['cpu'], 150);

        $this->listing($w['good']['psu'], 5, ['status' => ListingStatus::Sold]);
        $this->listing($w['good']['psu'], 400);

        $slots = collect(BuildPlanner::showcase()[0]['slots'])->keyBy('category');

        $this->assertSame(400_00, $slots['psu']['listing']->price_cents,
            'the planner used a sold listing because it was cheaper');
    }

    /**
     * An uncatalogued listing has no specs, so it can be neither constrained
     * nor trusted to fit — including in the slot that anchors the build.
     */
    public function test_a_listing_with_no_catalogue_row_is_not_used(): void
    {
        Listing::factory()->create([
            'user_id'     => User::factory()->create()->id,
            'part_id'     => null,
            'custom_part' => 'Някаква карта',
            'category'    => 'gpu',
            'city_id'     => City::first()->id,
            'status'      => ListingStatus::Active,
            'price_cents' => 100_00,
        ]);

        $this->assertSame([], BuildPlanner::showcase());
    }

    /** Three cards means three different machines, not the same one three times. */
    public function test_it_spreads_the_showcase_across_the_price_range(): void
    {
        $cpu = $this->part('cpu', 'Процесор AM4', ['socket' => 'AM4']);
        $this->listing($cpu, 150);

        foreach ([150, 300, 600, 900] as $i => $euros) {
            $this->listing(
                $this->part('gpu', "Карта {$euros}", ['tdp_w' => 200, 'length_mm' => 240]),
                $euros,
            );
        }

        $anchors = collect(BuildPlanner::showcase(3))->map(fn ($b) => $b['anchor']->price_cents);

        $this->assertCount(3, $anchors);
        $this->assertSame($anchors->unique()->count(), $anchors->count(),
            'the same card anchored more than one machine');

        // Cheapest and dearest are always both represented, or „three builds"
        // is three variations of the same price.
        $this->assertSame(150_00, $anchors->min());
        $this->assertSame(900_00, $anchors->max());
    }

    // --- the cache, which is where this broke in production ---------------

    /**
     * The cached value must survive a serialising cache driver.
     *
     * THE BUG THIS EXISTS FOR ONLY APPEARS ON THE SECOND PAGE LOAD. The page
     * cached the assembled builds, Eloquent models and all. The first request
     * was a cache MISS, computed them, rendered perfectly and wrote the
     * serialised payload; every request after that unserialised the models into
     * `__PHP_Incomplete_Class` and 500'd on „tried to access a property on an
     * incomplete object".
     *
     * No test in this suite could have caught that, because a test never reads
     * back what an earlier request wrote — which is exactly the shape of bug
     * worth writing a test for once you have seen it. So: round-trip the plan
     * through serialize() the way a file or database cache would, and build
     * from what comes back.
     */
    public function test_the_cached_plan_survives_being_serialised(): void
    {
        $w = $this->buildableWorld();

        $this->listing($w['gpu'], 300);
        $this->listing($w['cpu'], 150);
        foreach ($w['good'] as $part) {
            $this->listing($part, 100);
        }

        $plan = BuildPlanner::plan();

        $builds = BuildPlanner::hydrate(unserialize(serialize($plan)));

        $this->assertCount(1, $builds);
        $this->assertSame(750_00, $builds[0]['total_cents']);
        $this->assertNotNull($builds[0]['anchor']->part,
            'the rebuilt machine lost its catalogue data on the way through the cache');
    }

    /** And the reason it survives: there is nothing in it but scalars. */
    public function test_the_plan_holds_no_objects_at_all(): void
    {
        $w = $this->buildableWorld();

        $this->listing($w['gpu'], 300);
        $this->listing($w['cpu'], 150);

        $walk = function (array $node) use (&$walk): void {
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $walk($value);

                    continue;
                }

                $this->assertFalse(
                    is_object($value),
                    "[{$key}] is a ".(is_object($value) ? $value::class : 'object')
                    .'; anything cached has to be a scalar or it works exactly once',
                );
            }
        };

        $walk(BuildPlanner::plan());
    }

    /**
     * A plan outlives the listings in it, so hydration has to re-check.
     *
     * Ten minutes is a long time on a marketplace, and quoting a total that
     * includes a card somebody already bought is the one number on this page
     * that must not be wrong.
     */
    public function test_a_part_that_sells_drops_out_of_a_cached_build(): void
    {
        $w = $this->buildableWorld();

        $this->listing($w['gpu'], 300);
        $this->listing($w['cpu'], 150);
        $psu = $this->listing($w['good']['psu'], 100);

        $plan = BuildPlanner::plan();

        $psu->forceFill(['status' => ListingStatus::Sold, 'sold_at' => now()])->save();

        $builds = BuildPlanner::hydrate($plan);
        $slots  = collect($builds[0]['slots'])->keyBy('category');

        $this->assertNull($slots['psu']['listing'], 'a sold listing stayed in the build');
        $this->assertSame(450_00, $builds[0]['total_cents'],
            'the total still included a part that is no longer for sale');
    }

    /** The whole machine goes when its anchor does — everything fitted THAT card. */
    public function test_a_build_disappears_when_its_card_sells(): void
    {
        $w = $this->buildableWorld();

        $card = $this->listing($w['gpu'], 300);
        $this->listing($w['cpu'], 150);

        $plan = BuildPlanner::plan();

        $card->forceFill(['status' => ListingStatus::Sold, 'sold_at' => now()])->save();

        $this->assertSame([], BuildPlanner::hydrate($plan));
    }

    // --- one engine, not two ----------------------------------------------

    /**
     * The planner and the link text must agree, because they appear on the same
     * site: a page that assembles a machine the listing page says will not work
     * is worse than either feature alone.
     */
    public function test_the_planner_reads_the_same_rules_as_the_listing_page_links(): void
    {
        $gpu = $this->part('gpu', 'Карта 200W', ['tdp_w' => 200, 'length_mm' => 240]);

        $filters = collect(Compatibility::filtersBetween($gpu, 'psu'));

        $this->assertCount(1, $filters, 'the gpu -> psu edge is no longer in config/compatibility.php');

        // Headroom is applied, so the floor is above the card's own draw.
        $this->assertArrayHasKey('min', $filters[0]['filter']);
        $this->assertGreaterThan(200, $filters[0]['filter']['min']);
    }
}
