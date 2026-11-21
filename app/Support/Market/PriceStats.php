<?php

namespace App\Support\Market;

/**
 * A handful of asking prices in, a band out - or nothing.
 *
 * Classified ads are noisy in a predictable way: the odd card priced at a tenth
 * of the market because it is broken or a placeholder, the odd one at three
 * times because it is a bundle or a dream. Those would drag a mean anywhere, so
 * the figures are PERCENTILES, and the extremes are trimmed first with the usual
 * interquartile fence. Below the minimum sample the answer is null: a „market
 * price" from three ads is three people's opinions.
 */
final class PriceStats
{
    /**
     * @param  list<int>  $cents
     * @return array{p25: int, median: int, p75: int, sample: int}|null
     */
    public static function summarise(array $cents, int $minSample = 8): ?array
    {
        $cents = array_values(array_filter($cents, fn ($c) => is_int($c) && $c > 0));
        sort($cents);

        if (count($cents) >= 8) {
            [$q1, $q3] = [self::percentile($cents, 25), self::percentile($cents, 75)];
            $iqr       = $q3 - $q1;
            $low       = $q1 - 1.5 * $iqr;
            $high      = $q3 + 1.5 * $iqr;

            $cents = array_values(array_filter($cents, fn ($c) => $c >= $low && $c <= $high));
        }

        if (count($cents) < $minSample) {
            return null;
        }

        return [
            'p25'    => (int) round(self::percentile($cents, 25)),
            'median' => (int) round(self::percentile($cents, 50)),
            'p75'    => (int) round(self::percentile($cents, 75)),
            'sample' => count($cents),
        ];
    }

    /** Linear interpolation between closest ranks, over an already sorted list. */
    private static function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);

        if ($n === 1) {
            return (float) $sorted[0];
        }

        $rank = ($p / 100) * ($n - 1);
        $lo   = (int) floor($rank);
        $hi   = (int) ceil($rank);

        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($rank - $lo);
    }
}
