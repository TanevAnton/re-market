<?php

namespace Tests\Feature;

use App\Livewire\ShowPart;
use App\Models\Part;
use App\Models\PartPricePoint;
use App\Support\PriceChart;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The price chart's geometry, and that it reaches the page.
 *
 * The geometry tests build their rows in memory - PriceChart is arithmetic on a
 * few fields and needs no database - and read like the drawing they describe:
 * where a point lands, where the line breaks, which numbers the axis shows.
 */
class PriceChartTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, int>  $medians  days ago => median cents
     * @return list<PartPricePoint> oldest first
     */
    private function series(array $medians, float $below = 0.9, float $above = 1.1, int $sample = 8): array
    {
        krsort($medians);

        $rows = [];

        foreach ($medians as $daysAgo => $cents) {
            $rows[] = (new PartPricePoint)->forceFill([
                'captured_on'  => now()->startOfDay()->subDays($daysAgo)->toDateString(),
                'p25_cents'    => (int) round($cents * $below),
                'median_cents' => $cents,
                'p75_cents'    => (int) round($cents * $above),
                'sample_size'  => $sample,
            ]);
        }

        return $rows;
    }

    public function test_fewer_than_two_points_draw_nothing(): void
    {
        $this->assertNull(PriceChart::plot($this->series([0 => 70000])));
        $this->assertNull(PriceChart::plot([]));
    }

    public function test_points_are_spaced_by_date_and_the_last_lands_on_the_right_edge(): void
    {
        $plot = PriceChart::plot($this->series([60 => 80000, 40 => 76000, 20 => 72000, 0 => 70000]));

        $this->assertSame([0.0, 33.33, 66.67, 100.0], array_column($plot['points'], 'x'));
        $this->assertSame(100.0, $plot['last']['x']);
    }

    /** SVG's y axis points down, so a higher price is a smaller y. */
    public function test_a_higher_price_is_drawn_higher_up_and_the_band_brackets_the_line(): void
    {
        $plot = PriceChart::plot($this->series([60 => 80000, 40 => 76000, 20 => 72000, 0 => 70000]));

        // The oldest point is the dearest (80 000), so it sits nearer the top:
        // its y is the SMALLER number.
        $this->assertLessThan($plot['points'][3]['y'], $plot['points'][0]['y']);

        foreach ($plot['points'] as $point) {
            $this->assertLessThan($point['y'], $point['y75']);
            $this->assertGreaterThan($point['y'], $point['y25']);
        }

        // Everything stays inside the plot, or a stroke is clipped by the edge.
        foreach ($plot['points'] as $point) {
            foreach (['y', 'y25', 'y75'] as $key) {
                $this->assertGreaterThanOrEqual(0, $point[$key]);
                $this->assertLessThanOrEqual(100, $point[$key]);
            }
        }
    }

    public function test_the_axis_reads_in_round_numbers_highest_first(): void
    {
        $plot = PriceChart::plot($this->series([60 => 80000, 40 => 76000, 20 => 72000, 0 => 70000]));

        $this->assertSame(['900 €', '800 €', '700 €', '600 €'], array_column($plot['y_ticks'], 'label'));
        $this->assertSame(0.0, $plot['y_ticks'][0]['y']);
        $this->assertSame(100.0, $plot['y_ticks'][3]['y']);
    }

    public function test_the_scale_holds_the_whole_band_not_just_the_median(): void
    {
        [$lo, $hi] = PriceChart::scale(63000, 88000);

        $this->assertSame([60000, 90000], [$lo, $hi]);
    }

    /** A flat series has no range to scale against; dividing by it is a fatal. */
    public function test_a_perfectly_flat_series_sits_in_the_middle_without_dividing_by_zero(): void
    {
        $plot = PriceChart::plot($this->series([60 => 70000, 40 => 70000, 20 => 70000, 0 => 70000], below: 1.0, above: 1.0));

        $this->assertSame([50.0, 50.0, 50.0, 50.0], array_column($plot['points'], 'y'));
    }

    /**
     * A day with too few listings records nothing, and a line joined across
     * three missing weeks draws a price nobody asked. Daily series, one hole.
     */
    public function test_a_hole_in_a_daily_series_breaks_the_line_and_the_band(): void
    {
        $plot = PriceChart::plot($this->series([
            30 => 70000, 29 => 70500, 28 => 71000,
            10 => 72000, 9 => 72500, 8 => 73000,
        ]));

        $this->assertCount(2, $plot['lines']);
        $this->assertCount(2, $plot['bands']);
        $this->assertCount(6, $plot['points'], 'Every measurement is still there to hover.');
    }

    public function test_a_lone_point_after_a_hole_is_a_dot_and_not_a_line(): void
    {
        $plot = PriceChart::plot($this->series([30 => 70000, 29 => 70500, 28 => 71000, 0 => 72000]));

        $this->assertCount(1, $plot['lines']);
        $this->assertCount(4, $plot['points']);
    }

    /**
     * Sparse is not the same as broken. A model measured every twentieth day has
     * no holes - breaking it at every step would draw nothing but dots.
     */
    public function test_a_series_that_is_always_sparse_is_joined(): void
    {
        $plot = PriceChart::plot($this->series([60 => 80000, 40 => 76000, 20 => 72000, 0 => 70000]));

        $this->assertCount(1, $plot['lines']);
    }

    public function test_the_tooltip_carries_the_day_the_median_the_range_and_the_sample(): void
    {
        $plot = PriceChart::plot($this->series([60 => 80000, 0 => 70000], sample: 11));

        $last = $plot['points'][1];

        $this->assertSame(now()->format('d.m.Y'), $last['date']);
        $this->assertSame('700 €', $last['median_label']);
        $this->assertSame('630 – 770 €', $last['range_label']);
        $this->assertSame(11, $last['sample']);
    }

    public function test_the_date_axis_has_the_two_ends_and_a_middle_when_there_is_room(): void
    {
        $long = PriceChart::plot($this->series([60 => 80000, 0 => 70000]));

        $this->assertSame(['start', 'center', 'end'], array_column($long['x_ticks'], 'align'));
        $this->assertSame(now()->subDays(60)->format('d.m'), $long['x_ticks'][0]['label']);
        $this->assertSame(now()->format('d.m'), $long['x_ticks'][2]['label']);

        $short = PriceChart::plot($this->series([9 => 80000, 0 => 70000]));

        $this->assertSame(['start', 'end'], array_column($short['x_ticks'], 'align'));
    }

    // --- on the page -------------------------------------------------------

    public function test_the_model_page_draws_the_chart_when_there_is_a_series(): void
    {
        $this->seed(CitySeeder::class);

        $part = Part::create([
            'category' => 'gpu', 'manufacturer' => 'ASUS', 'model' => 'Chart Test 1000',
            'slug' => 'asus-chart-test-1000', 'specs' => [], 'is_published' => true,
        ]);

        foreach ([60 => 80000, 40 => 76000, 20 => 72000, 0 => 70000] as $daysAgo => $cents) {
            PartPricePoint::create([
                'part_id'      => $part->id,
                'captured_on'  => now()->subDays($daysAgo)->toDateString(),
                'p25_cents'    => (int) round($cents * 0.9),
                'median_cents' => $cents,
                'p75_cents'    => (int) round($cents * 1.1),
                'sample_size'  => 8,
            ]);
        }

        Livewire::test(ShowPart::class, ['part' => $part])
            ->assertSee('Как се движи цената')
            ->assertSee('Графика на средната искана цена', false)
            ->assertSee('900 €', false);
    }
}
