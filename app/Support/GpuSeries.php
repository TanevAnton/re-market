<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Graphics cards grouped the way people talk about them: „RTX 30", „RX 6000".
 *
 * THE CATALOGUE HAS NO SERIES FIELD, and this is why it does not need one. A
 * series is a rule about the chipset name a part already carries, so it is
 * derived here rather than stored - a new card added to the catalogue falls
 * into its series the moment it exists, with no second thing to remember to
 * fill in and no migration when a generation launches.
 *
 * THE PATTERNS ARE ANCHORED and written in the subset of regular expressions
 * that PHP and PostgreSQL read identically, because the same string is used
 * twice: here, to sort the catalogue into tiles, and in SQL, to filter the
 * listings. Two spellings of one rule is how a tile ends up saying „3" over a
 * page showing two.
 *
 * A chipset that matches nothing is simply in no series. Its listings still
 * appear under „Всички видеокарти" - the menu is a shortcut, never the only
 * door. GpuSeriesTest fails if a seeded card is left out, so the seeder cannot
 * drift without somebody being told.
 */
class GpuSeries
{
    /**
     * In the order the menu draws them: six across, two rows - NVIDIA's
     * generations newest first, then the older and smaller families.
     *
     * @var array<string, array{brand: string, label: string, pattern: string}>
     */
    private const SERIES = [
        'rtx-50'  => ['brand' => 'NVIDIA', 'label' => 'RTX 50',  'pattern' => '^RTX 50[0-9]{2}'],
        'rtx-40'  => ['brand' => 'NVIDIA', 'label' => 'RTX 40',  'pattern' => '^RTX 40[0-9]{2}'],
        'rtx-30'  => ['brand' => 'NVIDIA', 'label' => 'RTX 30',  'pattern' => '^RTX 30[0-9]{2}'],
        'rtx-20'  => ['brand' => 'NVIDIA', 'label' => 'RTX 20',  'pattern' => '^RTX 20[0-9]{2}'],
        'gtx'     => ['brand' => 'NVIDIA', 'label' => 'GTX',     'pattern' => '^GTX '],
        'arc'     => ['brand' => 'Intel',  'label' => 'Arc',     'pattern' => '^Arc '],
        'rx-9000' => ['brand' => 'AMD',    'label' => 'RX 9000', 'pattern' => '^RX 9[0-9]{3}'],
        'rx-7000' => ['brand' => 'AMD',    'label' => 'RX 7000', 'pattern' => '^RX 7[0-9]{3}'],
        'rx-6000' => ['brand' => 'AMD',    'label' => 'RX 6000', 'pattern' => '^RX 6[0-9]{3}'],
        'rx-5000' => ['brand' => 'AMD',    'label' => 'RX 5000', 'pattern' => '^RX 5[0-9]{3}'],
        // Three digits and then the end of the number, so „RX 580" lands here
        // and „RX 5700 XT" does not.
        'rx-500'  => ['brand' => 'AMD',    'label' => 'RX 500',  'pattern' => '^RX 5[0-9]{2}( |$)'],
    ];

    /**
     * The small dot beside the maker's name. A colour, not a logo: enough to
     * tell the two halves of the menu apart at a glance, and nothing that
     * needs anybody's permission to draw.
     */
    private const DOTS = ['NVIDIA' => '#76b900', 'AMD' => '#ed1c24', 'Intel' => '#0071c5'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::SERIES);
    }

    /**
     * One series, or null for a key that is not one.
     *
     * The key arrives from the query string, so „not found" is the ordinary
     * answer to a hand-edited URL rather than an error.
     *
     * @return array{key: string, brand: string, label: string, pattern: string}|null
     */
    public static function find(string $key): ?array
    {
        return isset(self::SERIES[$key])
            ? ['key' => $key, ...self::SERIES[$key]]
            : null;
    }

    /** Which series a chipset belongs to, or null if none claims it. */
    public static function forChipset(string $chipset): ?string
    {
        foreach (self::SERIES as $key => $series) {
            if (self::test($series['pattern'], $chipset)) {
                return $key;
            }
        }

        return null;
    }

    public static function contains(string $key, string $chipset): bool
    {
        return isset(self::SERIES[$key]) && self::test(self::SERIES[$key]['pattern'], $chipset);
    }

    private static function test(string $pattern, string $chipset): bool
    {
        return preg_match('/'.$pattern.'/', $chipset) === 1;
    }

    /**
     * Narrow a query on `parts` to one series.
     *
     * The pattern is bound like any other value even though it comes from the
     * constant above and never from a visitor - the habit is cheaper than the
     * day somebody makes the patterns configurable.
     */
    public static function constrain(Builder $parts, string $key): Builder
    {
        $series = self::find($key);

        // An unknown key must match nothing, not everything: a filter that
        // silently widens is how a wrong page looks like a right one.
        if (! $series) {
            return $parts->whereRaw('1 = 0');
        }

        return $parts->whereRaw("(parts.specs->>'chipset') ~ ?", [$series['pattern']]);
    }

    /**
     * Every graphics card in the catalogue, as part id => chipset.
     *
     * Fifty-odd rows, read once per page. Not cached: a card promoted into the
     * catalogue should be in the menu on the next page load, not ten minutes
     * later, and there is nothing here expensive enough to be worth explaining
     * that delay to anybody.
     *
     * @return array<int, string>
     */
    public static function chipsetsByPart(): array
    {
        return DB::table('parts')
            ->where('category', 'gpu')
            // jsonb_exists(), not the `?` operator - PDO reads a bare `?` as a
            // placeholder.
            ->whereRaw("jsonb_exists(specs, 'chipset')")
            ->selectRaw("id, specs->>'chipset' AS chipset")
            ->pluck('chipset', 'id')
            ->all();
    }

    /**
     * The menu: one tile per series that has cards in the catalogue.
     *
     * Counts are handed in rather than queried here, because what they should
     * count is the page's business - the browse page wants „listings matching
     * the other filters", and this class should not have to know what a filter
     * is to draw a tile.
     *
     * @param  array<int, string>  $chipsetsByPart  part id => chipset
     * @param  array<int, int>     $listingsByPart  part id => listings
     * @return list<array{
     *     key: string, brand: string, dot: string, label: string, range: string, count: int,
     *     models: list<array{chipset: string, count: int}>
     * }>
     */
    public static function menu(array $chipsetsByPart, array $listingsByPart = []): array
    {
        // Several parts can share a chipset - the 8 GB and 16 GB 4060 Ti are
        // two catalogue rows and one name - so the counts are summed per name.
        $perChipset = [];

        foreach ($chipsetsByPart as $partId => $chipset) {
            $perChipset[$chipset] = ($perChipset[$chipset] ?? 0) + ($listingsByPart[$partId] ?? 0);
        }

        $tiles = [];

        foreach (self::SERIES as $key => $series) {
            $names = array_values(array_filter(
                array_keys($perChipset),
                fn (string $chipset) => self::test($series['pattern'], $chipset),
            ));

            // A series with nothing in the catalogue has nothing to offer and
            // nothing to list - a tile for it would be a dead end with a name.
            if ($names === []) {
                continue;
            }

            // Natural order, so 3060 Ti sits between 3060 and 3070 and the
            // range under the name reads smallest to largest.
            usort($names, 'strnatcasecmp');

            $models = array_map(
                fn (string $chipset) => ['chipset' => $chipset, 'count' => $perChipset[$chipset]],
                $names,
            );

            $tiles[] = [
                'key'    => $key,
                'brand'  => $series['brand'],
                'dot'    => self::DOTS[$series['brand']] ?? 'currentColor',
                'label'  => $series['label'],
                'range'  => self::range($names),
                'count'  => array_sum(array_column($models, 'count')),
                'models' => $models,
            ];
        }

        return $tiles;
    }

    /**
     * „3050 – 3090 Ti": the first and last model, without the family prefix
     * the tile already says in larger type directly above.
     *
     * @param  non-empty-list<string>  $names  already sorted
     */
    private static function range(array $names): string
    {
        $short = fn (string $chipset) => trim((string) preg_replace('/^\S+\s+/', '', $chipset)) ?: $chipset;

        $first = $short($names[0]);
        $last  = $short($names[count($names) - 1]);

        return $first === $last ? $first : $first.' – '.$last;
    }
}
