<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Moderation\CatalogueQueue;
use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\PartPromotionDismissal;
use App\Models\User;
use App\Services\Catalogue\PartPromotionService;
use App\Support\Cyrillic;
use App\Support\PartPromotion;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Making the catalogue grow with usage instead of with evenings.
 *
 * `PartSeeder`'s comment has always said the catalogue is meant to grow
 * variant-level rows „as they appear in listings". The tool that does that did
 * not exist, and — worse — the input it needed was being thrown away: the
 * wizard asked „Име на модела", validated the answer, and then wrote
 * `part_id => null` and nothing else. Every seller telling us exactly which row
 * was missing was talking to a field that went nowhere.
 *
 * The tests below are in three groups, and the middle one carries the weight.
 * Capture is easy. Grouping is the whole problem: „RTX 4070", „rtx4070" and
 * „РТХ 4070" are one model and three facts about how Bulgarians type it, and a
 * queue that shows them as three rows shows noise where a model should be.
 */
class CatalogueGrowthTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        $this->admin  = User::factory()->create(['is_admin' => true]);
        $this->seller = User::factory()->create();
    }

    private function listing(?string $customPart, array $attributes = []): Listing
    {
        return Listing::factory()->create([
            'user_id'     => $this->seller->id,
            'part_id'     => null,
            'custom_part' => $customPart,
            'category'    => 'gpu',
            'status'      => ListingStatus::Active,
            'city_id'     => City::first()->id,
            ...$attributes,
        ]);
    }

    private function part(string $model = 'GeForce RTX 4070', array $attributes = []): Part
    {
        return Part::create([
            'category'     => 'gpu',
            'manufacturer' => 'NVIDIA',
            'model'        => $model,
            'slug'         => \Illuminate\Support\Str::slug('nvidia '.$model),
            'specs'        => ['vram_gb' => 12, 'tdp_w' => 200],
            'aliases'      => ['4070'],
            'is_published' => true,
            ...$attributes,
        ]);
    }

    // --- capture ----------------------------------------------------------

    /**
     * The bug underneath the whole feature. Before this column existed the
     * wizard collected the single highest-signal input on the site and dropped
     * it on the floor, so there was nothing for any tool to work on.
     */
    public function test_what_the_seller_typed_reaches_the_database(): void
    {
        $listing = $this->listing('ASUS TUF RTX 4070 OC');

        $this->assertSame('ASUS TUF RTX 4070 OC', $listing->fresh()->custom_part);
    }

    public function test_the_listing_page_shows_it_as_the_seller_s_words(): void
    {
        $listing = $this->listing('ASUS TUF RTX 4070 OC');

        Livewire::test(\App\Livewire\ShowListing::class, ['listing' => $listing])
            ->assertSee('Модел по думите на продавача')
            ->assertSee('ASUS TUF RTX 4070 OC');
    }

    /** A catalogued listing has a real model page and must not show both. */
    public function test_a_catalogued_listing_shows_the_catalogue_instead(): void
    {
        $listing = $this->listing(null, ['part_id' => $this->part()->id]);

        Livewire::test(\App\Livewire\ShowListing::class, ['listing' => $listing])
            ->assertSee('Този модел')
            ->assertDontSee('Модел по думите на продавача');
    }

    // --- grouping, which is the whole problem -----------------------------

    /**
     * Four spellings, one model, one row.
     *
     * Shown separately these are four counts of one, every one of them below
     * any threshold worth acting on, and the queue looks like noise. Folded
     * they are a model with four ready-made search aliases - the part a
     * hand-written seeder can never produce, because it is a record of what
     * people did rather than a guess at what they might.
     */
    public function test_the_same_model_typed_four_ways_is_one_row(): void
    {
        foreach (['RTX 4070', 'rtx4070', 'РТХ 4070', 'RTX 4o7o'] as $spelling) {
            $this->listing($spelling);
        }

        $clusters = PartPromotion::clusters();

        $this->assertCount(1, $clusters);
        $this->assertSame(4, $clusters->first()['count']);
        $this->assertCount(4, $clusters->first()['spellings']);
    }

    /** Different models stay different. Over-merging would be worse than none. */
    public function test_a_different_model_is_a_different_row(): void
    {
        $this->listing('RTX 4070');
        $this->listing('RTX 4070 Ti');

        $this->assertCount(2, PartPromotion::clusters());
    }

    public function test_the_queue_is_sorted_by_how_many_people_typed_it(): void
    {
        $this->listing('RX 7800 XT');
        $this->listing('RTX 4070');
        $this->listing('rtx 4070');

        $this->assertSame('rtx4070', PartPromotion::clusters()->first()['key']);
    }

    /** The majority spelling is the one most likely to be right, so it is the
     *  one that pre-fills the form and lands on the dismissal record. */
    public function test_the_sample_is_the_most_common_spelling(): void
    {
        $this->listing('rtx 4070');
        $this->listing('RTX 4070');
        $this->listing('RTX 4070');

        $this->assertSame('RTX 4070', PartPromotion::clusters()->first()['sample']);
    }

    /** Already catalogued means already answered. */
    public function test_a_listing_with_a_part_is_not_in_the_queue(): void
    {
        $this->listing('RTX 4070', ['part_id' => $this->part()->id]);

        $this->assertCount(0, PartPromotion::clusters());
    }

    /**
     * A moderator decided that listing should not exist. Its text must not
     * steer the catalogue - otherwise a takedown still gets the last word about
     * what the site sells.
     */
    public function test_a_removed_listing_does_not_steer_the_catalogue(): void
    {
        $this->listing('Спешно продавам', ['status' => ListingStatus::Removed]);

        $this->assertCount(0, PartPromotion::clusters());
    }

    /** A model that sold is still a model. */
    public function test_a_sold_listing_still_counts(): void
    {
        $this->listing('RTX 4070', ['status' => ListingStatus::Sold]);

        $this->assertCount(1, PartPromotion::clusters());
    }

    /** The nav badge and the screen must never disagree about what is waiting. */
    public function test_the_badge_counts_the_same_rows_the_screen_shows(): void
    {
        $this->listing('RTX 4070');
        $this->listing('rtx 4070');
        $this->listing('RX 7800 XT');
        $this->listing('RTX 4070', ['part_id' => $this->part()->id]);

        $this->assertSame(3, Listing::awaitingCatalogue()->count());
        $this->assertSame(3, PartPromotion::clusters()->sum('count'));
    }

    // --- attaching, which is the common case ------------------------------

    /**
     * Most of this queue is not a missing model at all - it is somebody typing
     * „4070" while the row is called „GeForce RTX 4070".
     */
    public function test_attaching_gives_the_listings_their_model_back(): void
    {
        $part = $this->part();
        $a    = $this->listing('4070');
        $b    = $this->listing('4070-ка');

        app(PartPromotionService::class)
            ->attach([$a->id, $b->id], $part, ['4070', '4070-ка'], $this->admin);

        $this->assertSame($part->id, $a->fresh()->part_id);
        $this->assertSame($part->id, $b->fresh()->part_id);
    }

    /**
     * The compounding bit. A seeder guesses at how people type; a cluster is a
     * record of how they did, and attaching teaches the search box a spelling
     * nobody had to imagine.
     */
    public function test_attaching_teaches_the_search_box_the_spellings(): void
    {
        $part    = $this->part();
        $listing = $this->listing('4070-ка');

        app(PartPromotionService::class)
            ->attach([$listing->id], $part, ['4070-ка'], $this->admin);

        $this->assertNotNull(
            Part::where('category', 'gpu')->search('4070-ка')->first(),
            'the spelling that was just promoted still finds nothing',
        );
    }

    /** Aliases somebody thought about are not lost to one somebody typed. */
    public function test_attaching_keeps_the_aliases_the_part_already_had(): void
    {
        $part    = $this->part();
        $listing = $this->listing('4070-ка');

        app(PartPromotionService::class)
            ->attach([$listing->id], $part, ['4070-ка'], $this->admin);

        $this->assertContains('4070', $part->fresh()->aliases);
    }

    /**
     * Two admins on the queue at once, or one with two tabs. The second write
     * must not move listings the first already attached somewhere else.
     */
    public function test_a_listing_already_attached_is_not_moved_again(): void
    {
        $first  = $this->part('GeForce RTX 4070');
        $second = $this->part('GeForce RTX 4070 Ti');

        $listing = $this->listing('4070', ['part_id' => $first->id]);

        $this->expectException(\RuntimeException::class);

        app(PartPromotionService::class)->attach([$listing->id], $second, ['4070'], $this->admin);
    }

    /** The counts feed the model page, and „0 обяви" on a page that was just
     *  populated reads as a failed attach. */
    public function test_attaching_updates_the_counts_now_rather_than_at_0410(): void
    {
        $part    = $this->part();
        $listing = $this->listing('4070');

        /*
         * fresh(), not the in-memory model. The column defaults to 0 in the
         * DATABASE, and a database default is not a model default: the instance
         * Part::create() hands back never had the attribute set, so it reads
         * null. The same distinction bit this project once already, when
         * notify_email was null on a fresh User and via() returned no channels.
         */
        $this->assertSame(0, $part->fresh()->active_listings_count);

        app(PartPromotionService::class)->attach([$listing->id], $part, ['4070'], $this->admin);

        $this->assertSame(1, $part->fresh()->active_listings_count);
    }

    // --- creating ---------------------------------------------------------

    public function test_promoting_creates_the_row_and_attaches_the_listings(): void
    {
        $a = $this->listing('ASUS TUF RTX 4070 OC');
        $b = $this->listing('asus tuf 4070 oc');

        $part = app(PartPromotionService::class)->promote(
            [
                'category'     => 'gpu',
                'manufacturer' => 'ASUS',
                'model'        => 'TUF RTX 4070 OC',
                'variant'      => null,
                'launch_year'  => 2023,
                'specs'        => ['vram_gb' => 12, 'length_mm' => 301],
                'is_published' => true,
            ],
            [$a->id, $b->id],
            ['ASUS TUF RTX 4070 OC', 'asus tuf 4070 oc'],
            $this->admin,
        );

        $this->assertSame($part->id, $a->fresh()->part_id);
        $this->assertSame($part->id, $b->fresh()->part_id);
        $this->assertSame(301, $part->spec('length_mm'));
    }

    /**
     * The variant-level path PartSeeder's comment describes. A TUF 4070 is a
     * 4070 in every respect except its dimensions, and retyping the other
     * fields is how a spec sheet ends up disagreeing with itself.
     */
    public function test_a_variant_inherits_the_base_row_s_specs_and_overrides_what_differs(): void
    {
        $base    = $this->part();                 // vram 12, tdp 200
        $listing = $this->listing('ASUS TUF RTX 4070 OC');

        $part = app(PartPromotionService::class)->promote(
            [
                'category' => 'gpu', 'manufacturer' => 'ASUS', 'model' => 'RTX 4070',
                'variant' => 'TUF Gaming OC', 'launch_year' => null,
                'specs' => ['length_mm' => 301], 'is_published' => true,
            ],
            [$listing->id],
            ['ASUS TUF RTX 4070 OC'],
            $this->admin,
            $base,
        );

        $this->assertSame(12, $part->spec('vram_gb'), 'the base row\'s specs were not inherited');
        $this->assertSame(200, $part->spec('tdp_w'));
        $this->assertSame(301, $part->spec('length_mm'), 'the override did not win');
    }

    /**
     * The promoted listing gets its facets back, which is the payoff a buyer
     * actually sees. An uncatalogued listing carries NO part-scoped specs at
     * all - the wizard only collects listing-scoped ones - so before promotion
     * it matched no facet filter in its own category.
     */
    public function test_a_promoted_listing_becomes_filterable(): void
    {
        $listing = $this->listing('ASUS TUF RTX 4070 OC');

        $filtered = fn () => Listing::query()
            ->whereHas('part', fn ($p) => $p->whereRaw("(specs->>'vram_gb')::numeric = 12"))
            ->pluck('id');

        $this->assertNotContains($listing->id, $filtered());

        app(PartPromotionService::class)->promote(
            ['category' => 'gpu', 'manufacturer' => 'ASUS', 'model' => 'TUF RTX 4070 OC',
             'variant' => null, 'launch_year' => null,
             'specs' => ['vram_gb' => 12], 'is_published' => true],
            [$listing->id],
            ['ASUS TUF RTX 4070 OC'],
            $this->admin,
        );

        $this->assertContains($listing->id, $filtered());
    }

    /**
     * The table has a unique index on (manufacturer, model, variant), so a
     * duplicate reaches the database as a QueryException - a 500 on a screen
     * whose whole job is judgement calls, instead of the useful answer.
     */
    public function test_a_duplicate_is_refused_with_the_useful_answer(): void
    {
        $this->part();

        $this->expectExceptionMessage('вече съществува');

        app(PartPromotionService::class)->promote(
            ['category' => 'gpu', 'manufacturer' => 'NVIDIA', 'model' => 'GeForce RTX 4070',
             'variant' => null, 'launch_year' => null, 'specs' => [], 'is_published' => true],
            [], [], $this->admin,
        );
    }

    public function test_a_model_name_is_required(): void
    {
        $this->expectExceptionMessage('Моделът е задължителен');

        app(PartPromotionService::class)->promote(
            ['category' => 'gpu', 'manufacturer' => 'ASUS', 'model' => '  ',
             'variant' => null, 'launch_year' => null, 'specs' => [], 'is_published' => true],
            [], [], $this->admin,
        );
    }

    /**
     * A thin catalogue page is worse than a missing one - it teaches a crawler
     * the site is not worth returning to. An unpublished row still groups the
     * listings and still feeds the filters.
     */
    public function test_an_unpublished_row_still_groups_but_has_no_public_page(): void
    {
        $listing = $this->listing('ASUS TUF RTX 4070 OC');

        $part = app(PartPromotionService::class)->promote(
            ['category' => 'gpu', 'manufacturer' => 'ASUS', 'model' => 'TUF RTX 4070 OC',
             'variant' => null, 'launch_year' => null, 'specs' => [], 'is_published' => false],
            [$listing->id], ['ASUS TUF RTX 4070 OC'], $this->admin,
        );

        $this->assertSame($part->id, $listing->fresh()->part_id);

        Livewire::test(\App\Livewire\ShowPart::class, ['part' => $part])->assertNotFound();
    }

    /**
     * The slug is in the URL of a page we want indexed, and two different
     * identities can slugify to the same string - „RTX 4070 Founders" as a
     * model, and „RTX 4070" with variant „Founders". The identity index does
     * not catch that pair, so without the suffix loop the second promotion dies
     * on a unique-constraint violation halfway through.
     */
    public function test_two_identities_that_slugify_the_same_do_not_collide(): void
    {
        $existing = $this->part('RTX 4070 Founders');

        $this->assertSame('nvidia-rtx-4070-founders', $existing->slug);

        $part = app(PartPromotionService::class)->promote(
            ['category' => 'gpu', 'manufacturer' => 'NVIDIA', 'model' => 'RTX 4070',
             'variant' => 'Founders', 'launch_year' => null, 'specs' => [], 'is_published' => true],
            [], [], $this->admin,
        );

        $this->assertSame('nvidia-rtx-4070-founders-2', $part->slug);
    }

    // --- dismissing -------------------------------------------------------

    /**
     * „не знам" and „компютър" are what many people type into a field asking
     * for a model, so they sit at the top of a queue sorted by frequency
     * forever. A queue that cannot be cleared stops being worked.
     */
    public function test_junk_can_be_hidden_and_brought_back(): void
    {
        $this->listing('не знам');
        $this->listing('Не Знам');

        $key = PartPromotion::clusters()->first()['key'];

        app(PartPromotionService::class)->dismiss($key, 'не знам', $this->admin);

        $this->assertCount(0, PartPromotion::clusters());
        $this->assertCount(1, PartPromotion::clusters(dismissed: true));

        app(PartPromotionService::class)->restore($key);

        $this->assertCount(1, PartPromotion::clusters());
    }

    /** The record has to be readable: the key is squashed and the listings it
     *  came from may be long gone by the time anybody looks. */
    public function test_a_dismissal_keeps_a_readable_sample(): void
    {
        app(PartPromotionService::class)->dismiss('nezniam', 'не знам', $this->admin);

        $this->assertSame('не знам', PartPromotionDismissal::first()->sample);
    }

    // --- the screen -------------------------------------------------------

    public function test_the_screen_is_admin_only(): void
    {
        $this->actingAs($this->seller)->get(route('catalogue'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('catalogue'))->assertOk();
    }

    public function test_ticking_several_spellings_promotes_them_together(): void
    {
        $part = $this->part();
        $this->listing('4070');
        $this->listing('РТХ 4070');

        $keys = PartPromotion::clusters()->pluck('key')->all();

        Livewire::actingAs($this->admin)
            ->test(CatalogueQueue::class)
            ->set('selected', $keys)
            ->call('startAttach')
            ->call('attach', $part->id)
            ->assertSet('selected', []);

        $this->assertSame(2, $part->fresh()->listings()->count());
    }

    /** The form should not be blank for something we can already guess at. */
    public function test_the_create_form_guesses_the_manufacturer_and_category(): void
    {
        $this->listing('ASUS TUF RTX 4070 OC');

        Livewire::actingAs($this->admin)
            ->test(CatalogueQueue::class)
            ->set('selected', PartPromotion::clusters()->pluck('key')->all())
            ->call('startCreate')
            ->assertSet('category', 'gpu')
            ->assertSet('manufacturer', 'ASUS')
            ->assertSet('model', 'TUF RTX 4070 OC');
    }

    /** „4070" contains no manufacturer, and inventing one is worse than a blank. */
    public function test_a_string_with_no_manufacturer_gets_an_empty_field(): void
    {
        $this->assertSame(
            ['manufacturer' => '', 'model' => '4070'],
            PartPromotion::split('4070'),
        );
    }

    /**
     * The refusals are the useful part of this screen - „вече съществува,
     * закачи вместо да създаваш" is the answer the admin wanted - so they are
     * shown rather than becoming a 500.
     */
    public function test_a_refusal_is_shown_rather_than_thrown(): void
    {
        $this->part();
        $this->listing('RTX 4070');

        $component = Livewire::actingAs($this->admin)
            ->test(CatalogueQueue::class)
            ->set('selected', PartPromotion::clusters()->pluck('key')->all())
            ->call('startCreate')
            ->set('manufacturer', 'NVIDIA')
            ->set('model', 'GeForce RTX 4070')
            ->call('create');

        $this->assertStringContainsString('вече съществува', $component->get('problem'));
    }

    /**
     * The queue is redrawn in the same request as the promotion, so a memo
     * filled beforehand would show rows that no longer exist - and the admin
     * would promote them a second time.
     */
    public function test_the_queue_redraws_without_what_was_just_promoted(): void
    {
        $part = $this->part();
        $this->listing('4070');

        Livewire::actingAs($this->admin)
            ->test(CatalogueQueue::class)
            ->set('selected', PartPromotion::clusters()->pluck('key')->all())
            ->call('attach', $part->id)
            ->assertSee('Опашката е празна');
    }

    // --- the shared transliteration map -----------------------------------

    /**
     * toLatin() is array_flip(toCyrillic's map), so two Latin words mapping to
     * one Cyrillic word would silently lose one of them - and the loss would
     * show up as a search that stopped working, nowhere near this file.
     */
    public function test_the_cyrillic_map_is_reversible(): void
    {
        foreach (['rtx 4070', 'geforce rtx 4070', 'msi tomahawk', 'playstation 5'] as $phrase) {
            $this->assertSame(
                $phrase,
                Cyrillic::toLatin(Cyrillic::toCyrillic($phrase)),
                "[{$phrase}] does not survive a round trip through the map",
            );
        }
    }

    /** Words the map does not know pass through untouched, in both directions. */
    public function test_unknown_words_are_left_alone(): void
    {
        $this->assertSame('strix oracle 9999', Cyrillic::toCyrillic('Strix Oracle 9999'));
        $this->assertSame('видеокарта', Cyrillic::toLatin('видеокарта'));
    }
}
