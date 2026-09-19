<?php

namespace App\Support;

use App\Models\Part;

/**
 * What the model is going for, shown to a seller while they type a price.
 *
 * The band has existed on the catalogue pages for weeks and the seller who most
 * needs it never sees it: they are in the wizard, on step 4, guessing. A price
 * set too high sits until it expires and the seller concludes the site does not
 * work; set too low it sells in an hour and they conclude the same thing for
 * the opposite reason. Both outcomes cost supply, which is the bottleneck.
 *
 * TWO-SIDED, UNLIKE THE PUBLIC BADGE — and the difference is the audience, not
 * a change of mind. `Listing::priceAdvantage()` is deliberately one-sided
 * because it is a PUBLIC claim about somebody's listing, and a marketplace that
 * grades its sellers in public loses its sellers. This is private, before
 * anything is published, and a seller who is about to leave two hundred euros
 * on the table is owed that fact as much as one who is about to overprice.
 *
 * It states asking prices as asking prices. The site does not know what
 * anything sold for — offers are private and a deal's agreed price may not even
 * be stored — so „средно 620 €" would be a claim we cannot support. The same
 * care the model pages already take.
 */
class PriceGuidance
{
    /**
     * @return array{
     *     band: array{p25: int, median: int, p75: int, at: \Carbon\CarbonInterface},
     *     standing: array{percent: int, position: string}|null,
     *     trend: array<string, mixed>|null,
     *     suggestion: int,
     * }|null
     */
    public static function for(?Part $part, ?int $priceCents = null): ?array
    {
        $band = $part?->priceBand();

        if (! $band) {
            return null;
        }

        return [
            'band'     => $band,
            'standing' => $priceCents ? $part->priceStanding($priceCents) : null,

            /*
             * The direction, when the history is long enough to have one. A
             * seller pricing at last month's median on a market that has fallen
             * 8% is the single most common way to end up with a listing nobody
             * touches, and it is invisible from a single number.
             */
            'trend'    => $part->priceTrend(),

            // Where we would start. The median rather than the p75: this page
            // exists to get the listing sold, not to talk anybody up.
            'suggestion' => (int) $band['median'],
        ];
    }

    /**
     * One sentence about where a price sits, or null for „nothing worth saying".
     *
     * Silence inside the middle of the band is deliberate. A seller who has
     * priced sensibly does not need a message telling them so, and a screen
     * that comments on every keystroke stops being read by the time it has
     * something worth saying.
     */
    public static function note(array $guidance): ?string
    {
        $standing = $guidance['standing'] ?? null;

        if (! $standing) {
            return null;
        }

        $percent = abs($standing['percent']);

        return match ($standing['position']) {
            'high' => "Около {$percent}% над средното за модела. Възможно е да стои по-дълго.",
            'low'  => "Около {$percent}% под средното за модела. Ще се продаде бързо — провери дали не подценяваш.",
            default => null,
        };
    }
}
