<?php

namespace App\Support;

use DateTimeInterface;

/**
 * A model's recorded price series as the geometry of a proper chart: the median
 * line, the low-to-high band around it, the axis labels, and everything a hover
 * tooltip needs.
 *
 * Sparkline answers „which way is it going" in forty pixels. This answers
 * „what was it on the 14th, and how wide was the spread", which is what a buyer
 * deciding whether a price is normal, and a seller deciding when to list,
 * actually want to see.
 *
 * ALL COORDINATES ARE PERCENTAGES of the plot area, 0-100 on both axes, y
 * pointing down. That one choice lets the same numbers drive the SVG (a viewBox
 * of 0 0 100 100, stretched to fit) and the HTML placed over it - the markers,
 * the axis labels and the tooltip - with no second coordinate system to keep in
 * step. Markers are HTML rather than SVG circles because a circle in a
 * stretched SVG is an ellipse.
 *
 * PURE PHP, NO FRAMEWORK TYPES, for the same reason Sparkline is outside the
 * view: geometry in a Blade file needs raw PHP in it, and that is how a page
 * stops compiling. It reads only what a PartPricePoint carries, and dates only
 * through DateTimeInterface, so it can be tested with a handful of objects.
 *
 * GAPS ARE DRAWN AS GAPS. A day with too few live listings records nothing, and
 * a line joined straight across three missing weeks draws a price nobody asked.
 * The line and the band are broken wherever the series skips more than
 * GAP_DAYS, or three times its own usual spacing if that is longer; the points
 * either side are still there to hover.
 */
class PriceChart
{
    /**
     * The least a jump can be and still count as a gap. The real threshold is
     * three times the series' own usual spacing if that is larger - see plot().
     * Daily capture, so 4 is a long weekend plus one.
     */
    public const GAP_DAYS = 4;

    /**
     * Gridline spacings in cents: 10 €, 20 €, 50 €, ... 10 000 €. The smallest one
     * that fits the data in four intervals or fewer is used, so the axis always
     * reads in round numbers and never in 7-euro steps.
     */
    private const STEPS = [1000, 2000, 5000, 10000, 20000, 50000, 100000, 200000, 500000, 1000000];

    /**
     * @param  iterable<object>  $history  oldest first; each with captured_on, p25_cents,
     *                                      median_cents, p75_cents, sample_size
     * @return array{
     *     points: list<array<string, mixed>>,
     *     lines: list<string>,
     *     bands: list<string>,
     *     y_ticks: list<array{y: float, label: string}>,
     *     x_ticks: list<array{x: float, label: string, align: string}>,
     *     last: array{x: float, y: float}
     * }|null
     */
    public static function plot(iterable $history): ?array
    {
        $rows = [];

        foreach ($history as $row) {
            $rows[] = $row;
        }

        if (count($rows) < 2) {
            return null;
        }

        // The scale has to hold the whole band, not just the median, or the
        // shaded area runs off the top of the chart.
        $min = PHP_INT_MAX;
        $max = 0;

        foreach ($rows as $row) {
            $min = min($min, (int) $row->p25_cents, (int) $row->median_cents);
            $max = max($max, (int) $row->p75_cents, (int) $row->median_cents);
        }

        [$lo, $hi, $step] = self::scale($min, $max);

        $y = static fn (int $cents): float => round(100 - (($cents - $lo) / ($hi - $lo) * 100), 2);

        $first = self::day($rows[0]->captured_on);
        $span  = max(1, self::day($rows[count($rows) - 1]->captured_on) - $first);
        $x     = static fn (DateTimeInterface $date): float => round((self::day($date) - $first) / $span * 100, 2);

        $points = [];

        foreach ($rows as $row) {
            $median = (int) $row->median_cents;
            $p25    = (int) $row->p25_cents;
            $p75    = (int) $row->p75_cents;

            $points[] = [
                'x'           => $x($row->captured_on),
                'y'           => $y($median),
                'y25'         => $y($p25),
                'y75'         => $y($p75),
                'day'         => self::day($row->captured_on),
                'date'        => $row->captured_on->format('d.m.Y'),
                'median'      => $median,
                'median_label' => self::euros($median),
                'range_label' => self::euros($p25, withSign: false).' – '.self::euros($p75),
                'sample'      => (int) $row->sample_size,
            ];
        }

        // Break the series wherever the dates jump - but jump relative to how
        // this series normally moves. A model measured every day that goes
        // missing for three weeks has a hole; one that has only ever been
        // measured every twentieth day has no holes, just a sparse record, and
        // breaking it at every step would draw nothing but dots.
        $intervals = [];

        for ($i = 1; $i < count($points); $i++) {
            $intervals[] = $points[$i]['day'] - $points[$i - 1]['day'];
        }

        sort($intervals);

        $usual     = $intervals[intdiv(count($intervals), 2)];
        $threshold = max(self::GAP_DAYS, 3 * $usual);

        $segments = [];
        $current  = [0];

        for ($i = 1; $i < count($points); $i++) {
            if ($points[$i]['day'] - $points[$i - 1]['day'] > $threshold) {
                $segments[] = $current;
                $current    = [];
            }

            $current[] = $i;
        }

        $segments[] = $current;

        $lines = [];
        $bands = [];

        foreach ($segments as $indexes) {
            // One point on its own is a dot, which the page draws, not a line.
            if (count($indexes) < 2) {
                continue;
            }

            $lines[] = implode(' ', array_map(
                fn (int $i) => "{$points[$i]['x']},{$points[$i]['y']}",
                $indexes,
            ));

            // Along the top of the band, then back along the bottom, and shut.
            $top    = array_map(fn (int $i) => "{$points[$i]['x']},{$points[$i]['y75']}", $indexes);
            $bottom = array_map(fn (int $i) => "{$points[$i]['x']},{$points[$i]['y25']}", array_reverse($indexes));

            $bands[] = 'M'.implode(' L', array_merge($top, $bottom)).' Z';
        }

        // Highest price first, because that is the order they are drawn down the page.
        $yTicks = [];

        for ($value = $hi; $value >= $lo; $value -= $step) {
            $yTicks[] = ['y' => $y($value), 'label' => self::euros($value)];
        }

        $lastRow = $rows[count($rows) - 1];

        $xTicks = [
            ['x' => 0.0, 'label' => $rows[0]->captured_on->format('d.m'), 'align' => 'start'],
        ];

        // A middle label only when there is room for three without them touching.
        if ($span >= 14) {
            $mid      = $first + intdiv($span, 2);
            $xTicks[] = [
                'x'     => 50.0,
                // gmdate: $mid is already a local calendar day counted as if it
                // were UTC, so reading it back in any other zone shifts it.
                'label' => gmdate('d.m', $mid * 86400),
                'align' => 'center',
            ];
        }

        $xTicks[] = ['x' => 100.0, 'label' => $lastRow->captured_on->format('d.m'), 'align' => 'end'];

        return [
            'points'  => $points,
            'lines'   => $lines,
            'bands'   => $bands,
            'y_ticks' => $yTicks,
            'x_ticks' => $xTicks,
            'last'    => ['x' => $points[count($points) - 1]['x'], 'y' => $points[count($points) - 1]['y']],
        ];
    }

    /**
     * Round-number bounds for the axis.
     *
     * @return array{0: int, 1: int, 2: int} lowest gridline, highest gridline, spacing - all in cents
     */
    public static function scale(int $min, int $max): array
    {
        $step = self::STEPS[count(self::STEPS) - 1];

        foreach (self::STEPS as $candidate) {
            if ((int) ceil($max / $candidate) - (int) floor($min / $candidate) <= 4) {
                $step = $candidate;
                break;
            }
        }

        $lo = (int) floor($min / $step) * $step;
        $hi = (int) ceil($max / $step) * $step;

        // A flat series sits exactly on one gridline and would have no height
        // to draw in; give it a line above and below.
        if ($hi === $lo) {
            $hi += $step;
        }

        // And keep the data off the very edge: a line or band that touches the
        // top or bottom of the plot gets its stroke clipped in half.
        $room = ($hi - $lo) * 0.06;

        if ($min - $lo < $room && $lo - $step >= 0) {
            $lo -= $step;
        }

        if ($hi - $max < $room) {
            $hi += $step;
        }

        return [$lo, $hi, $step];
    }

    /** Whole days since 1970, so two dates subtract cleanly across a clock change. */
    private static function day(DateTimeInterface $date): int
    {
        // Midnight in a zone with daylight saving is 23 or 25 hours from the
        // last one; rounding, not truncating, is what keeps a day a day.
        return (int) round(($date->getTimestamp() + $date->getOffset()) / 86400);
    }

    private static function euros(int $cents, bool $withSign = true): string
    {
        $text = number_format($cents / 100, 0, ',', ' ');

        return $withSign ? $text.' €' : $text;
    }
}
