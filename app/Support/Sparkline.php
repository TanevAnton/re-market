<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * A price series as SVG coordinates.
 *
 * Pure arithmetic, deliberately outside the view: a Blade file that computes
 * its own geometry needs raw PHP in it, and raw PHP in a Blade file in this
 * codebase is how a page silently stops compiling. It is also the only part of
 * a chart worth testing.
 *
 * NO CHARTING LIBRARY. A sparkline is a polyline; pulling in a dependency to
 * draw one would ship a hundred kilobytes of JavaScript to every part page so
 * that forty pixels of line can animate.
 */
class Sparkline
{
    /**
     * @param  Collection<int, \App\Models\PartPricePoint>  $history  oldest first
     * @return array{points: string, last: array{x: float, y: float}, flat: bool}|null
     */
    public static function plot(Collection $history, float $width = 240, float $height = 40): ?array
    {
        if ($history->count() < 2) {
            return null;
        }

        $values = $history->pluck('median_cents')->map(fn ($c) => (int) $c);
        $min    = $values->min();
        $max    = $values->max();

        /*
         * SPACED BY DATE, not by index.
         *
         * The series has gaps by design - a day with too few live listings
         * records nothing - and plotting position by array index would draw a
         * three-week hole as one ordinary step, which flattens exactly the part
         * of the line a reader most needs to distrust.
         */
        $start = $history->first()->captured_on;
        $span  = max(1, (int) $start->diffInDays($history->last()->captured_on));

        // A perfectly flat series has no range to scale against; dividing by it
        // is a division by zero, and drawing it anywhere but the middle implies
        // a movement that did not happen.
        $range = $max - $min;

        $coords = $history->map(function ($point) use ($start, $span, $min, $range, $width, $height) {
            $x = (int) $start->diffInDays($point->captured_on) / $span * $width;

            // SVG's y axis points down, so a higher price is a SMALLER y. The
            // 0.5 keeps a flat line off the very edge of the viewBox, where a
            // 2px stroke would be clipped in half.
            $y = $range > 0
                ? $height - (($point->median_cents - $min) / $range * $height)
                : $height / 2;

            return ['x' => round($x, 1), 'y' => round($y, 1)];
        });

        return [
            'points' => $coords->map(fn ($c) => "{$c['x']},{$c['y']}")->implode(' '),
            'last'   => $coords->last(),
            'flat'   => $range === 0,
        ];
    }
}
