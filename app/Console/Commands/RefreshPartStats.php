<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills in the numbers the part landing pages are built on.
 *
 * The columns have existed since the parts table was created and nothing has
 * ever written to them - so every part page would have said "0 listings" and
 * shown no price band at all, which is the one thing those pages exist to
 * provide. A landing page that ranks and then tells the visitor nothing is
 * worse than no landing page.
 *
 * One statement per figure, over the whole table, rather than a query per
 * part: there are thousands of parts and this runs nightly.
 */
class RefreshPartStats extends Command
{
    protected $signature = 'remarket:refresh-part-stats';

    protected $description = 'Recompute listing counts and price percentiles for every catalogue part';

    public function handle(): int
    {
        $started = microtime(true);

        /*
         * Counts first, and note the two are different questions:
         * `listings_count` is everything ever posted (how well covered this
         * model is), `active_listings_count` is what a buyer can act on now.
         * The landing page shows the second and the sitemap ranks on it.
         *
         * A LEFT JOIN-shaped subquery rather than a join, so a part with no
         * listings is set to 0 instead of being skipped - otherwise a model
         * that sold out keeps yesterday's count forever.
         */
        DB::statement("
            UPDATE parts p SET
                listings_count = COALESCE(c.total, 0),
                active_listings_count = COALESCE(c.active, 0)
            FROM (
                SELECT part_id,
                       count(*) AS total,
                       count(*) FILTER (WHERE status = 'active') AS active
                  FROM listings
                 WHERE part_id IS NOT NULL
                   AND deleted_at IS NULL
                 GROUP BY part_id
            ) c
            WHERE c.part_id = p.id
        ");

        DB::statement('
            UPDATE parts SET listings_count = 0, active_listings_count = 0
             WHERE id NOT IN (
                SELECT DISTINCT part_id FROM listings
                 WHERE part_id IS NOT NULL AND deleted_at IS NULL
             )
        ');

        /*
         * The price band.
         *
         * percentile_cont, not avg: asking prices on a used marketplace have a
         * long right tail (the person who wants retail for a three-year-old
         * card) and one such listing drags a mean far enough to be useless.
         * The median is what someone actually pays attention to, and p25/p75
         * is the honest way to say "this is the range" without claiming more
         * precision than a handful of listings supports.
         *
         * Built from ACTIVE listings only. Completed deals would be the better
         * source - what people paid rather than what they asked - but the
         * agreed price is deliberately not always stored (the DAC7 question in
         * plan §8.4), so asking prices are what there is. The page says so.
         */
        DB::statement("
            UPDATE parts p SET
                price_p25_cents    = s.p25,
                price_median_cents = s.median,
                price_p75_cents    = s.p75,
                price_stats_at     = now()
            FROM (
                SELECT part_id,
                       percentile_cont(0.25) WITHIN GROUP (ORDER BY price_cents)::int AS p25,
                       percentile_cont(0.50) WITHIN GROUP (ORDER BY price_cents)::int AS median,
                       percentile_cont(0.75) WITHIN GROUP (ORDER BY price_cents)::int AS p75
                  FROM listings
                 WHERE part_id IS NOT NULL
                   AND deleted_at IS NULL
                   AND status = 'active'
                 GROUP BY part_id
                HAVING count(*) >= ?
            ) s
            WHERE s.part_id = p.id
        ", [$this->minimumSample()]);

        /*
         * Clear the band on anything that no longer meets the threshold rather
         * than leaving it. A stale median is a specific kind of harmful: it
         * looks current, it is quoted back in negotiations, and nothing on the
         * page says how old it is.
         */
        DB::statement("
            UPDATE parts p SET
                price_p25_cents = NULL, price_median_cents = NULL, price_p75_cents = NULL
            WHERE p.price_median_cents IS NOT NULL
              AND (
                SELECT count(*) FROM listings l
                 WHERE l.part_id = p.id AND l.deleted_at IS NULL AND l.status = 'active'
              ) < ?
        ", [$this->minimumSample()]);

        /*
         * The history point for today.
         *
         * Everything above overwrites: `parts` carries one current band and
         * last night's is gone. This is the only statement in the codebase that
         * writes something which cannot be recomputed later — a day that passes
         * without it is a day of the Bulgarian used-hardware market that no
         * longer exists anywhere.
         *
         * LAST on purpose. It reads the columns the three statements above have
         * just settled, including the clearing pass, so a part that dropped
         * below the minimum sample today correctly records nothing rather than
         * yesterday's figures with today's date on them.
         *
         * ON CONFLICT because this command must be safe to run twice — after a
         * failed deploy, by hand while debugging, or because a timer fired
         * twice. The second run of a day corrects that day; it does not add a
         * second point to it.
         */
        $captured = DB::affectingStatement("
            INSERT INTO part_price_points
                (part_id, captured_on, p25_cents, median_cents, p75_cents, sample_size, created_at)
            SELECT id, CURRENT_DATE,
                   price_p25_cents, price_median_cents, price_p75_cents,
                   active_listings_count, now()
              FROM parts
             WHERE price_median_cents IS NOT NULL
            ON CONFLICT (part_id, captured_on) DO UPDATE SET
                p25_cents    = EXCLUDED.p25_cents,
                median_cents = EXCLUDED.median_cents,
                p75_cents    = EXCLUDED.p75_cents,
                sample_size  = EXCLUDED.sample_size
        ");

        $priced = DB::table('parts')->whereNotNull('price_median_cents')->count();
        $live   = DB::table('parts')->where('active_listings_count', '>', 0)->count();

        $this->info(sprintf(
            'Parts refreshed in %.1fs — %d with live listings, %d with a price band, %d history points written.',
            microtime(true) - $started, $live, $priced, $captured,
        ));

        return self::SUCCESS;
    }

    /**
     * Below this many listings a "market price" is one person's opinion.
     *
     * Three is low, deliberately: on a young marketplace a stricter threshold
     * means no part has a band at all, and the band is the reason to visit the
     * page. Raise it once there is depth.
     */
    private function minimumSample(): int
    {
        return max(1, (int) config('remarket.parts.price_band_min_listings', 3));
    }
}
