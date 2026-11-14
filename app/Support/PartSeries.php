<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Parts grouped the way people talk about them: „RTX 30", „Ryzen 5000".
 *
 * THE CATALOGUE HAS NO SERIES FIELD, and this is why it does not need one. A
 * series is a rule about a name the part already carries, so it is derived
 * here rather than stored - a part added to the catalogue falls into its
 * series the moment it exists, with nothing extra to fill in and no migration
 * when a generation launches.
 *
 * ONE CLASS FOR EVERY CATEGORY THAT HAS A MENU. This began as GpuSeries, and
 * processors were the second category to ask for the same thing. Everything
 * that differed between the two turned out to be data - which name to read,
 * which patterns to sort it by - so the differences are the DEFINITIONS table
 * below and the code under it does not know what a graphics card is. A third
 * category is a new entry in that table and nothing else.
 *
 * WHICH NAME. For a graphics card it is the chipset in the spec sheet, because
 * forty board-partner models are all „RTX 3070" to the person shopping, and
 * the chipset is already a filter in the sidebar. For a processor it is the
 * model itself - there is one Ryzen 7 5800X3D, not forty.
 *
 * THE PATTERNS ARE ANCHORED and written in the subset of regular expressions
 * that PHP and PostgreSQL read identically, because the same string is used
 * twice: here, to sort the catalogue into tiles, and in SQL, to filter the
 * listings. Two spellings of one rule is how a tile ends up saying „3" over a
 * page showing two.
 *
 * A name that matches nothing is simply in no series. Its listings still
 * appear under „Всички" - the menu is a shortcut, never the only door. The
 * tests fail if a seeded part is left out, so the seeder cannot drift without
 * somebody being told.
 */
final class PartSeries
{
    /**
     * Per category:
     *
     *   column - the SQL expression for the name, on the `parts` table. Written
     *            here and nowhere else, and never built from input.
     *   facet  - the sidebar facet that holds the same name, if there is one.
     *            Picking a model then sets that facet instead of a filter of
     *            its own, so the sidebar and the menu cannot disagree.
     *   strip  - what to take off a name for the short form under a tile, where
     *            the family is already said in larger type directly above.
     *   series - in the order the menu draws them, six across.
     *
     * @var array<string, array{
     *     column: string, facet: string|null, strip: string,
     *     series: array<string, array{brand: string, label: string, pattern: string}>
     * }>
     */
    private const DEFINITIONS = [
        'gpu' => [
            'column' => "(parts.specs->>'chipset')",
            'facet'  => 'chipset',
            'strip'  => '/^\S+\s+/',
            'series' => [
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
                // Three digits and then the end of the number, so „RX 580"
                // lands here and „RX 5700 XT" does not.
                'rx-500'  => ['brand' => 'AMD',    'label' => 'RX 500',  'pattern' => '^RX 5[0-9]{2}( |$)'],
            ],
        ],

        /*
         * Processors, by generation.
         *
         * Generation rather than socket, although the two nearly coincide:
         * the socket is already a filter in the sidebar, and „Ryzen 5000" or
         * „13-то поколение" is what somebody actually types and says.
         *
         * Intel's four older generations share one tile. Each has a model or
         * three in the catalogue, and five tiles holding eight processors
         * between them would push the menu to a third row to say very little.
         * Split it the day one of them has enough stock to deserve its own.
         */
        'cpu' => [
            'column' => 'parts.model',
            'facet'  => null,
            'strip'  => '/^(Ryzen [3579] |Core Ultra [3579] |Core i[3579]-)/',
            'series' => [
                'ryzen-9000' => ['brand' => 'AMD',   'label' => 'Ryzen 9000', 'pattern' => '^Ryzen [3579] 9[0-9]{3}'],
                'ryzen-8000' => ['brand' => 'AMD',   'label' => 'Ryzen 8000', 'pattern' => '^Ryzen [3579] 8[0-9]{3}'],
                'ryzen-7000' => ['brand' => 'AMD',   'label' => 'Ryzen 7000', 'pattern' => '^Ryzen [3579] 7[0-9]{3}'],
                'ryzen-5000' => ['brand' => 'AMD',   'label' => 'Ryzen 5000', 'pattern' => '^Ryzen [3579] 5[0-9]{3}'],
                'ryzen-3000' => ['brand' => 'AMD',   'label' => 'Ryzen 3000', 'pattern' => '^Ryzen [3579] 3[0-9]{3}'],
                'ryzen-2000' => ['brand' => 'AMD',   'label' => 'Ryzen 2000', 'pattern' => '^Ryzen [3579] 2[0-9]{3}'],
                'core-ultra' => ['brand' => 'Intel', 'label' => 'Core Ultra', 'pattern' => '^Core Ultra '],
                'core-14'    => ['brand' => 'Intel', 'label' => 'Core 14',    'pattern' => '^Core i[3579]-14[0-9]{3}'],
                'core-13'    => ['brand' => 'Intel', 'label' => 'Core 13',    'pattern' => '^Core i[3579]-13[0-9]{3}'],
                'core-12'    => ['brand' => 'Intel', 'label' => 'Core 12',    'pattern' => '^Core i[3579]-12[0-9]{3}'],
                // The generation, then exactly three more digits, then
                // something that is not a digit - so a longer model number
                // can never be read as one of these by its first four.
                'core-6-11'  => ['brand' => 'Intel', 'label' => 'Core 6–11',  'pattern' => '^Core i[3579]-(6|7|8|9|10|11)[0-9]{3}([^0-9]|$)'],
            ],
        ],
    ];

    /**
     * The small dot beside the maker's name. A colour, not a logo: enough to
     * tell the two halves of a menu apart at a glance, and nothing that needs
     * anybody's permission to draw.
     */
    private const DOTS = ['NVIDIA' => '#76b900', 'AMD' => '#ed1c24', 'Intel' => '#0071c5'];

    private function __construct(
        private readonly string $category,
    ) {}

    /**
     * The series of one category, or null for a category that has no menu.
     *
     * Null is the ordinary answer - most categories have none - so every
     * caller is written as „if there is a menu here", never as an exception.
     */
    public static function for(string $category): ?self
    {
        return isset(self::DEFINITIONS[$category]) ? new self($category) : null;
    }

    /** @return list<string> */
    public static function categories(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function category(): string
    {
        return $this->category;
    }

    /** The sidebar facet that carries the same name, or null. */
    public function facet(): ?string
    {
        return self::DEFINITIONS[$this->category]['facet'];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::DEFINITIONS[$this->category]['series']);
    }

    /**
     * One series, or null for a key that is not one.
     *
     * The key arrives from the query string, so „not found" is the ordinary
     * answer to a hand-edited URL rather than an error.
     *
     * @return array{key: string, brand: string, label: string, pattern: string}|null
     */
    public function find(string $key): ?array
    {
        $series = self::DEFINITIONS[$this->category]['series'];

        return isset($series[$key]) ? ['key' => $key, ...$series[$key]] : null;
    }

    /** Which series a name belongs to, or null if none claims it. */
    public function forName(string $name): ?string
    {
        foreach (self::DEFINITIONS[$this->category]['series'] as $key => $series) {
            if (self::test($series['pattern'], $name)) {
                return $key;
            }
        }

        return null;
    }

    public function contains(string $key, string $name): bool
    {
        $series = self::DEFINITIONS[$this->category]['series'];

        return isset($series[$key]) && self::test($series[$key]['pattern'], $name);
    }

    private static function test(string $pattern, string $name): bool
    {
        return preg_match('/'.$pattern.'/', $name) === 1;
    }

    /**
     * Narrow a query on `parts` to one series.
     *
     * The pattern is bound like any other value even though it comes from the
     * table above and never from a visitor - the habit is cheaper than the day
     * somebody makes the patterns configurable. The column is interpolated,
     * and is safe to: it is one of the two literals in that same table.
     */
    public function constrain(Builder $parts, string $key): Builder
    {
        $series = $this->find($key);

        // An unknown key must match nothing, not everything: a filter that
        // silently widens is how a wrong page looks like a right one.
        if (! $series) {
            return $parts->whereRaw('1 = 0');
        }

        $column = self::DEFINITIONS[$this->category]['column'];

        return $parts->whereRaw("{$column} ~ ?", [$series['pattern']]);
    }

    /** Narrow a query on `parts` to one exact name. */
    public function constrainName(Builder $parts, string $name): Builder
    {
        $column = self::DEFINITIONS[$this->category]['column'];

        return $parts->whereRaw("{$column} = ?", [$name]);
    }

    /**
     * Every part of this category in the catalogue, as part id => name.
     *
     * A few dozen rows, read once per page. Not cached: a part promoted into
     * the catalogue should be in the menu on the next page load, not ten
     * minutes later, and there is nothing here expensive enough to be worth
     * explaining that delay to anybody.
     *
     * @return array<int, string>
     */
    public function namesByPart(): array
    {
        $column = self::DEFINITIONS[$this->category]['column'];

        return DB::table('parts')
            ->where('category', $this->category)
            ->whereRaw("{$column} IS NOT NULL")
            ->selectRaw("id, {$column} AS name")
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The menu: one tile per series that has parts in the catalogue.
     *
     * Counts are handed in rather than queried here, because what they should
     * count is the page's business - the browse page wants „listings matching
     * the other filters", and this class should not have to know what a filter
     * is to draw a tile.
     *
     * @param  array<int, string>  $namesByPart     part id => name
     * @param  array<int, int>     $listingsByPart  part id => listings
     * @return list<array{
     *     key: string, brand: string, dot: string, label: string, range: string, count: int,
     *     models: list<array{name: string, count: int}>
     * }>
     */
    public function menu(array $namesByPart, array $listingsByPart = []): array
    {
        // Several parts can share a name - the 8 GB and 16 GB 4060 Ti are two
        // catalogue rows and one chipset - so the counts are summed per name.
        $perName = [];

        foreach ($namesByPart as $partId => $name) {
            $perName[$name] = ($perName[$name] ?? 0) + ($listingsByPart[$partId] ?? 0);
        }

        $tiles = [];

        foreach (self::DEFINITIONS[$this->category]['series'] as $key => $series) {
            $names = array_values(array_filter(
                array_map('strval', array_keys($perName)),
                fn (string $name) => self::test($series['pattern'], $name),
            ));

            // A series with nothing in the catalogue has nothing to offer and
            // nothing to list - a tile for it would be a dead end with a name.
            if ($names === []) {
                continue;
            }

            // By the short form, in natural order: 3060 Ti sits between 3060
            // and 3070, and a 7700K comes before a 10400F although „i5" sorts
            // before „i7". The range under the tile then reads smallest to
            // largest, which is the only order anybody expects it in.
            usort($names, fn (string $a, string $b) => strnatcasecmp($this->short($a), $this->short($b))
                ?: strnatcasecmp($a, $b));

            $models = array_map(
                fn (string $name) => ['name' => $name, 'count' => $perName[$name]],
                $names,
            );

            $first = $this->short($names[0]);
            $last  = $this->short($names[count($names) - 1]);

            $tiles[] = [
                'key'    => $key,
                'brand'  => $series['brand'],
                'dot'    => self::DOTS[$series['brand']] ?? 'currentColor',
                'label'  => $series['label'],
                'range'  => $first === $last ? $first : $first.' – '.$last,
                'count'  => array_sum(array_column($models, 'count')),
                'models' => $models,
            ];
        }

        return $tiles;
    }

    /** „3090 Ti" from „RTX 3090 Ti", „5800X3D" from „Ryzen 7 5800X3D". */
    private function short(string $name): string
    {
        $short = trim((string) preg_replace(self::DEFINITIONS[$this->category]['strip'], '', $name));

        return $short !== '' ? $short : $name;
    }
}
