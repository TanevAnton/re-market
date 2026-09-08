<?php

namespace App\Livewire;

use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\Part;
use App\Support\SpecFilter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The front page.
 *
 * Deliberately NOT cached. Every query below is either bounded by a small
 * limit or is a single grouped aggregate, so the whole page is a handful of
 * indexed reads - and a cache would mean a seller publishes an ad and does not
 * see it on the home page for five minutes, which is exactly the moment they
 * decide whether this site works.
 */
class Home extends Component
{
    private const RAIL = 8;

    /**
     * Short codes instead of icons.
     *
     * Sixteen hand-drawn category icons would be sixteen chances to draw a
     * power supply that looks like a hard drive. GPU / CPU / RAM are what the
     * audience already calls these things, set in the same monospace as the
     * spec sheets - which says "this site knows what a GPU is" more clearly
     * than any icon set would.
     */
    private const CODES = [
        'gpu' => 'GPU', 'cpu' => 'CPU', 'motherboard' => 'MB', 'ram' => 'RAM',
        'psu' => 'PSU', 'storage' => 'SSD', 'monitor' => 'LCD', 'cooler' => 'FAN',
        'case' => 'ATX', 'laptop' => 'NB', 'keyboard' => 'KBD', 'mouse' => 'MSE',
        'headset' => 'AUD', 'console' => 'CON', 'prebuilt' => 'PC', 'other' => '...',
    ];

    /**
     * Categories with a live count, in one grouped query rather than sixteen.
     *
     * Empty categories stay visible: a marketplace that hides what it has none
     * of tells a visitor nothing about what it is for, and the count being
     * honestly zero is better than the category being missing.
     *
     * @return list<array{key: string, label: string, code: string, count: int}>
     */
    public function categories(): array
    {
        // The aggregate needs an alias: pluck() reads each column off the row
        // BY NAME, so an unaliased count(*) has it looking for a property
        // literally called "count(*)". With an empty table the group-by returns
        // no rows and the loop never runs, so this only breaks once there is
        // data - which is not the state anyone checks a new page in.
        $counts = Listing::query()
            ->where('status', ListingStatus::Active)
            ->groupBy('category')
            ->selectRaw('category, count(*) as total')
            ->pluck('total', 'category');

        return collect(SpecFilter::categories())
            ->map(fn (array $c) => [
                'key'   => $c['key'],
                'label' => $c['label'],
                'code'  => self::CODES[$c['key']] ?? '—',
                'count' => (int) ($counts[$c['key']] ?? 0),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /** What just landed. The reason a returning visitor opens the site. */
    public function newest()
    {
        return Listing::visible()
            ->with(['images', 'city', 'part'])
            ->latest('published_at')
            ->limit(self::RAIL)
            ->get();
    }

    /**
     * Most viewed, not "featured".
     *
     * There is no promoted-listing column yet, and dressing "whatever has the
     * most views" up as an editorial pick would be a small lie on the most
     * visible part of the site. When promoted listings ship (the first
     * monetisation step), they take this slot and the heading changes with it.
     */
    public function mostViewed()
    {
        return Listing::visible()
            ->with(['images', 'city', 'part'])
            ->where('view_count', '>', 0)
            ->orderByDesc('view_count')
            ->limit(self::RAIL)
            ->get();
    }

    /**
     * The models people are actually selling, as search chips.
     *
     * This is the seed of the SEO play: every chip is a query that will one day
     * be a part landing page.
     *
     * @return list<array{name: string, count: int}>
     */
    public function popularParts(): array
    {
        return Part::query()
            ->select('parts.id', 'parts.manufacturer', 'parts.model', 'parts.variant')
            ->selectRaw('count(listings.id) as listings_count')
            ->join('listings', 'listings.part_id', '=', 'parts.id')
            ->where('listings.status', ListingStatus::Active)
            ->groupBy('parts.id', 'parts.manufacturer', 'parts.model', 'parts.variant')
            ->orderByDesc('listings_count')
            ->limit(12)
            ->get()
            ->map(fn (Part $p) => ['name' => $p->fullName(), 'count' => (int) $p->listings_count])
            ->all();
    }

    /**
     * Three numbers, and the third is the one that matters.
     *
     * "Completed deals" is the only figure here a spammer cannot inflate - it
     * costs real transactions confirmed by both sides. Listing counts are
     * vanity by comparison.
     *
     * @return array{listings: int, deals: int, sellers: int}
     */
    public function stats(): array
    {
        return [
            'listings' => Listing::query()->where('status', ListingStatus::Active)->count(),
            'deals'    => Deal::query()->where('status', DealStatus::Completed)->count(),
            'sellers'  => Listing::query()
                ->where('status', ListingStatus::Active)
                ->distinct()
                ->count('user_id'),
        ];
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.home', [
            'categories'    => $this->categories(),
            'newest'        => $this->newest(),
            'mostViewed'    => $this->mostViewed(),
            'popularParts'  => $this->popularParts(),
            'stats'         => $this->stats(),
        ]);
    }
}
