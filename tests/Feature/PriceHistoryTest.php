<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\ShowPart;
use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\PartPricePoint;
use App\Models\User;
use App\Support\Sparkline;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The one thing on the backlog that cannot be caught up later.
 *
 * `parts` carries a single current band and overwrites it every night at 04:10,
 * so before this existed, yesterday's price was gone — not archived, not
 * derivable, gone. Every other feature can be built in a month and work
 * immediately; this one only ever knows what it was running for.
 *
 * Which makes the capture tests the important ones, and idempotency the most
 * important of those: a command that is not safe to re-run is a command that
 * gets run once by a timer, once by hand after a failed deploy, and quietly
 * doubles a day.
 */
class PriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Part $part;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        $this->part = Part::create([
            'category'     => 'gpu',
            'manufacturer' => 'ASUS',
            'model'        => 'Strix Oracle 9999',
            'slug'         => 'asus-strix-oracle-9999',
            'specs'        => [],
            'is_published' => true,
        ]);
    }

    private function listings(int $count, int $cents): void
    {
        for ($i = 0; $i < $count; $i++) {
            Listing::factory()->create([
                'user_id'         => User::factory()->create()->id,
                'part_id'         => $this->part->id,
                'category'        => 'gpu',
                'status'          => ListingStatus::Active,
                'city_id'         => City::first()->id,
                'price_cents'     => $cents,
                'min_offer_cents' => null,
            ]);
        }
    }

    /** @param  array<int, int>  $medians  days ago => median cents */
    private function history(array $medians, int $sample = 8): void
    {
        foreach ($medians as $daysAgo => $cents) {
            PartPricePoint::create([
                'part_id'      => $this->part->id,
                'captured_on'  => now()->subDays($daysAgo)->toDateString(),
                'p25_cents'    => (int) round($cents * 0.9),
                'median_cents' => $cents,
                'p75_cents'    => (int) round($cents * 1.1),
                'sample_size'  => $sample,
            ]);
        }
    }

    // --- capture ----------------------------------------------------------

    public function test_the_nightly_run_records_todays_band(): void
    {
        $this->listings(5, 70000);

        Artisan::call('remarket:refresh-part-stats');

        $point = PartPricePoint::where('part_id', $this->part->id)->firstOrFail();

        $this->assertSame(70000, $point->median_cents);
        $this->assertSame(5, $point->sample_size);
        $this->assertTrue($point->captured_on->isToday());
    }

    /**
     * The one that matters. A timer fires twice, a deploy fails halfway, or
     * somebody runs it by hand while debugging — and a second row for the same
     * day would put a step in every chart this table feeds.
     */
    public function test_running_it_twice_in_a_day_corrects_rather_than_doubles(): void
    {
        $this->listings(5, 70000);
        Artisan::call('remarket:refresh-part-stats');

        // The market moves; the command runs again the same day.
        Listing::query()->update(['price_cents' => 60000]);
        Artisan::call('remarket:refresh-part-stats');

        $points = PartPricePoint::where('part_id', $this->part->id)->get();

        $this->assertCount(1, $points);
        $this->assertSame(60000, $points->first()->median_cents);
    }

    /**
     * No band, no point. The command refuses to compute a median below the
     * minimum sample, and inventing a row for those days would be inventing a
     * price — a gap is the honest record of a model nobody was selling.
     */
    public function test_a_model_with_too_few_listings_records_nothing(): void
    {
        $this->listings(2, 70000);          // under the minimum of three

        Artisan::call('remarket:refresh-part-stats');

        $this->assertSame(0, PartPricePoint::count());
    }

    /**
     * The capture runs LAST, after the clearing pass. A part that dropped below
     * the threshold today must record nothing rather than yesterday's figures
     * with today's date on them.
     */
    public function test_a_model_that_went_quiet_records_nothing_new(): void
    {
        $this->listings(5, 70000);
        Artisan::call('remarket:refresh-part-stats');

        Listing::query()->update(['status' => ListingStatus::Sold]);
        Artisan::call('remarket:refresh-part-stats');

        $point = PartPricePoint::where('part_id', $this->part->id)->get();

        $this->assertCount(1, $point, 'a model with no live listings recorded a price');
        $this->assertSame(70000, $point->first()->median_cents,
            'the row from when the model WAS priced was overwritten by a day it was not');
    }

    /*
     * A note for whoever adds to the tests above: the capture uses Postgres's
     * CURRENT_DATE, which is the DATABASE's clock. $this->travel() moves PHP's
     * and not the database's, so a second Artisan::call() after travelling a
     * day still writes to the same date and takes the ON CONFLICT path. Every
     * multi-day series below is therefore inserted directly rather than by
     * running the command repeatedly - which is also faster and says what it
     * means.
     */

    // --- the trend, and refusing to claim one -----------------------------

    public function test_a_long_enough_series_reports_the_move(): void
    {
        $this->history([60 => 80000, 40 => 76000, 20 => 72000, 0 => 70000]);

        $trend = $this->part->priceTrend();

        $this->assertSame(-13, $trend['percent']);
        $this->assertSame('down', $trend['direction']);
        $this->assertSame(60, $trend['days']);
        $this->assertSame(4, $trend['points']);
    }

    /** Two numbers with a line between them is not a trend. */
    public function test_too_few_points_produces_no_claim(): void
    {
        $this->history([60 => 80000, 0 => 70000]);

        $this->assertNull($this->part->priceTrend());
    }

    /**
     * Tuesday against Thursday. On a thin market one seller relisting moves the
     * median several percent, and publishing that as a trend is noise reported
     * as news — a seller who cuts their price because of it has been misled by
     * us, which is worse than telling them nothing.
     */
    public function test_a_short_span_produces_no_claim_however_many_points(): void
    {
        $this->history([3 => 80000, 2 => 78000, 1 => 74000, 0 => 70000]);

        $this->assertNull($this->part->priceTrend());
    }

    /** Inside the same 3% the price-drop notification uses. */
    public function test_a_small_move_reads_as_flat(): void
    {
        $this->history([60 => 70000, 40 => 70500, 20 => 70200, 0 => 71000]);

        $this->assertSame('flat', $this->part->priceTrend()['direction']);
    }

    public function test_a_rise_is_reported_as_plainly_as_a_fall(): void
    {
        $this->history([60 => 70000, 40 => 74000, 20 => 78000, 0 => 84000]);

        $trend = $this->part->priceTrend();

        $this->assertSame(20, $trend['percent']);
        $this->assertSame('up', $trend['direction']);
    }

    /** The window is a window: last spring is not this quarter's market. */
    public function test_points_outside_the_window_are_left_out(): void
    {
        config(['remarket.parts.history_days' => 90]);

        $this->history([200 => 120000, 60 => 80000, 40 => 76000, 20 => 72000, 0 => 70000]);

        $this->assertSame(4, $this->part->priceTrend()['points']);
        $this->assertSame(80000, $this->part->priceTrend()['from']);
    }

    /** The thinnest day in the window, which is what the caption hedges on. */
    public function test_the_trend_carries_how_thin_the_series_was(): void
    {
        $this->history([60 => 80000, 40 => 76000, 20 => 72000], sample: 9);
        $this->history([0 => 70000], sample: 3);

        $this->assertSame(3, $this->part->priceTrend()['sample']);
    }

    // --- drawing it -------------------------------------------------------

    public function test_the_sparkline_spaces_points_by_date_not_by_index(): void
    {
        $this->history([60 => 80000, 40 => 76000, 20 => 72000, 0 => 70000]);

        $plot = Sparkline::plot($this->part->priceHistory(), width: 300, height: 40);

        $xs = collect(explode(' ', $plot['points']))
            ->map(fn ($pair) => (float) explode(',', $pair)[0]);

        // Evenly spaced dates give evenly spaced x, and the last point lands on
        // the right edge. Indexed positioning would draw a three-week gap as
        // one ordinary step.
        $this->assertSame([0.0, 100.0, 200.0, 300.0], $xs->all());
    }

    /** A flat series has no range to scale against; dividing by it is a fatal. */
    public function test_a_perfectly_flat_series_does_not_divide_by_zero(): void
    {
        $this->history([60 => 70000, 40 => 70000, 20 => 70000, 0 => 70000]);

        $plot = Sparkline::plot($this->part->priceHistory(), width: 300, height: 40);

        $this->assertTrue($plot['flat']);
        $this->assertStringContainsString('0,20', $plot['points']);
    }

    public function test_one_point_draws_nothing(): void
    {
        $this->history([0 => 70000]);

        $this->assertNull(Sparkline::plot($this->part->priceHistory()));
    }

    // --- where it appears -------------------------------------------------

    public function test_the_model_page_shows_the_trend(): void
    {
        $this->history([60 => 80000, 40 => 76000, 20 => 72000, 0 => 70000]);

        Livewire::test(ShowPart::class, ['part' => $this->part])
            ->assertSee('Как се движи цената')
            ->assertSee('13%');
    }

    public function test_the_model_page_says_nothing_without_a_series(): void
    {
        Livewire::test(ShowPart::class, ['part' => $this->part])
            ->assertDontSee('Как се движи цената');
    }

    /**
     * The seller's framing. „Струва 750 €" answers what to ask; „пада с 8% на
     * месец" answers whether to ask it this week or next, which is the decision
     * somebody deciding whether to sell actually came to make.
     */
    public function test_the_valuation_page_shows_it_to_the_seller(): void
    {
        $this->history([60 => 80000, 40 => 76000, 20 => 72000, 0 => 70000]);

        Livewire::test(\App\Livewire\Valuation::class)
            ->set('slug', $this->part->slug)
            ->assertSee('Как се движи цената');
    }

    /**
     * NOT colour-coded, and the test is here because it is the sort of thing a
     * later "improvement" adds without thinking. The same fall is good news to
     * a buyer and bad news to a seller, and both pages render this partial:
     * green for a falling price tells a seller their loss is a win.
     */
    public function test_the_direction_is_not_dressed_as_good_or_bad_news(): void
    {
        $this->history([60 => 80000, 40 => 76000, 20 => 72000, 0 => 70000]);

        $html = Livewire::test(ShowPart::class, ['part' => $this->part])->html();

        $section = substr($html, strpos($html, 'Как се движи цената'), 1200);

        $this->assertStringNotContainsString('text-good', $section);
        $this->assertStringNotContainsString('text-bad', $section);
    }
}
