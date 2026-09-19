<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Listings\CreateListing;
use App\Livewire\Listings\EditListing;
use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Support\PriceGuidance;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The band, shown to the person who most needs it.
 *
 * It has existed on the catalogue pages for weeks and the seller never sees it:
 * they are in the wizard on step 4, guessing. Priced too high the listing sits
 * until it expires and the seller concludes the site does not work; priced too
 * low it sells in an hour and they conclude the same thing for the opposite
 * reason. Both cost supply, which is the bottleneck.
 *
 * TWO-SIDED, UNLIKE THE PUBLIC BADGE, and the difference is the audience rather
 * than a change of mind. `Listing::priceAdvantage()` is one-sided because it is
 * a public claim about somebody's listing in front of buyers. This is private,
 * before anything is published, and a seller about to leave two hundred euros
 * on the table is owed that as much as one about to overprice.
 */
class PriceGuidanceTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(CitySeeder::class);

        $this->seller = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);
    }

    /** A part with a band already computed, as the nightly command would leave it. */
    private function bandedPart(int $p25 = 560_00, int $median = 620_00, int $p75 = 700_00): Part
    {
        $part = Part::create([
            'category'     => 'gpu',
            'manufacturer' => 'NVIDIA',
            'model'        => 'GeForce RTX 4070 Тест',
            'slug'         => 'test-banded-part',
            'specs'        => ['tdp_w' => 200],
            'is_published' => true,
        ]);

        $part->forceFill([
            'price_p25_cents'    => $p25,
            'price_median_cents' => $median,
            'price_p75_cents'    => $p75,
            'price_stats_at'     => now(),
        ])->save();

        return $part;
    }

    // --- when there is nothing to say -------------------------------------

    public function test_no_part_means_no_guidance(): void
    {
        $this->assertNull(PriceGuidance::for(null, 500_00));
    }

    /**
     * An uncatalogued listing has nothing to compare against — one more reason
     * the promotion queue matters.
     */
    public function test_a_part_without_a_band_says_nothing(): void
    {
        $bare = Part::create([
            'category'     => 'gpu',
            'manufacturer' => 'NVIDIA',
            'model'        => 'Без диапазон',
            'slug'         => 'no-band',
            'is_published' => true,
        ]);

        $this->assertNull(PriceGuidance::for($bare, 500_00));
    }

    /** A month-old band quoted as today's is the bug the max-age guard exists for. */
    public function test_a_stale_band_is_not_offered_as_advice(): void
    {
        $part = $this->bandedPart();

        $part->forceFill(['price_stats_at' => now()->subDays(30)])->save();

        $this->assertNull(PriceGuidance::for($part->fresh(), 620_00));
    }

    // --- the numbers ------------------------------------------------------

    public function test_it_reports_the_band_and_suggests_the_median(): void
    {
        $guidance = PriceGuidance::for($this->bandedPart(), null);

        $this->assertSame(560_00, $guidance['band']['p25']);
        $this->assertSame(620_00, $guidance['band']['median']);
        $this->assertSame(700_00, $guidance['band']['p75']);

        // The median, not the p75: this exists to get the listing sold, not to
        // talk anybody up.
        $this->assertSame(620_00, $guidance['suggestion']);

        // No price typed yet, so nothing to say about one.
        $this->assertNull($guidance['standing']);
        $this->assertNull(PriceGuidance::note($guidance));
    }

    // --- the note, which is the two-sided part ----------------------------

    public function test_it_warns_about_overpricing(): void
    {
        $guidance = PriceGuidance::for($this->bandedPart(), 900_00);

        $this->assertSame('high', $guidance['standing']['position']);
        $this->assertStringContainsString('над средното', PriceGuidance::note($guidance));
    }

    /**
     * And about UNDERpricing, which the public badge deliberately never
     * mentions. Different audience: that one is a buyer aid, this is the
     * seller's own screen before publishing.
     */
    public function test_it_also_warns_about_underpricing(): void
    {
        $guidance = PriceGuidance::for($this->bandedPart(), 400_00);

        $this->assertSame('low', $guidance['standing']['position']);

        $note = PriceGuidance::note($guidance);

        $this->assertStringContainsString('под средното', $note);
        $this->assertStringContainsString('подценяваш', $note);
    }

    /**
     * Silence in the middle of the band is deliberate. A seller who has priced
     * sensibly does not need to be told so, and a screen that comments on every
     * keystroke stops being read by the time it has something worth saying.
     */
    public function test_a_sensible_price_gets_no_lecture(): void
    {
        $guidance = PriceGuidance::for($this->bandedPart(), 620_00);

        $this->assertSame('mid', $guidance['standing']['position']);
        $this->assertNull(PriceGuidance::note($guidance));
    }

    // --- on the screens ---------------------------------------------------

    /**
     * Reaching step 4 means getting past step 3, and step 3 will not release a
     * listing without a photograph — `'stored' => ['array', 'min:1']`.
     *
     * The first version of this test did not upload one and failed on
     * `assertSet('step', 4)`, which looked like the guidance was broken and was
     * actually the wizard doing its job. No other test in the suite walks all
     * the way to the price step, so there was no established helper to copy and
     * the requirement was invisible until it fired.
     */
    public function test_the_wizard_shows_it_on_the_price_step(): void
    {
        $part = $this->bandedPart();

        Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->set('category', 'gpu')
            ->call('next')
            ->set('partId', $part->id)
            ->call('next')
            ->set('title', 'ASUS RTX 4070, пълен комплект с кутия')
            ->set('description', 'Картата е от домашна машина, работи без проблем и е тествана.')
            ->set('photos', [UploadedFile::fake()->image('card.jpg', 900, 675)])
            ->call('next')
            ->assertSet('step', 4)
            ->assertSee('Колко върви този модел')
            ->assertSee('620');
    }

    /** „Asking", never „sold" — the site does not know what anything sold for. */
    public function test_it_says_these_are_asking_prices(): void
    {
        $part = $this->bandedPart();

        $listing = Listing::factory()->create([
            'user_id'     => $this->seller->id,
            'part_id'     => $part->id,
            'category'    => 'gpu',
            'city_id'     => City::first()->id,
            'status'      => ListingStatus::Active,
            'price_cents' => 620_00,
        ]);

        Livewire::actingAs($this->seller)
            ->test(EditListing::class, ['listing' => $listing])
            ->assertSee('не на които е продадено');
    }

    public function test_the_edit_screen_reacts_to_a_changed_price(): void
    {
        $part = $this->bandedPart();

        $listing = Listing::factory()->create([
            'user_id'     => $this->seller->id,
            'part_id'     => $part->id,
            'category'    => 'gpu',
            'city_id'     => City::first()->id,
            'status'      => ListingStatus::Active,
            'price_cents' => 620_00,
        ]);

        Livewire::actingAs($this->seller)
            ->test(EditListing::class, ['listing' => $listing])
            ->assertDontSee('над средното')
            ->set('price', '950')
            ->assertSee('над средното');
    }

    /** One number, one currency. This label used to say „лв". */
    public function test_the_edit_screen_prices_in_euros_like_everything_else(): void
    {
        $listing = Listing::factory()->create([
            'user_id'  => $this->seller->id,
            'category' => 'gpu',
            'city_id'  => City::first()->id,
            'status'   => ListingStatus::Active,
        ]);

        Livewire::actingAs($this->seller)
            ->test(EditListing::class, ['listing' => $listing])
            ->assertSee('Цена (€)')
            ->assertDontSee('Цена (лв)');
    }
}
