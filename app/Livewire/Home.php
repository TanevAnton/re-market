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
     * Categories with a live count, in one grouped query rather than nineteen.
     *
     * Empty categories stay visible: a marketplace that hides what it has none
     * of tells a visitor nothing about what it is for, and the count being
     * honestly zero is better than the category being missing.
     *
     * WHERE THE THREE-LETTER CODES WENT. This grid used to carry a mono badge
     * per category - GPU, CPU, RAM - on the argument that those are what the
     * audience already calls these things. That held at sixteen categories of
     * components. It stopped holding twice over: the tail was never as obvious
     * as the head (ATX for a case, MSE for a mouse, AUD for a headset are
     * guesses, not names), and the Apple lines brought in a buyer who does not
     * read spec sheets and for whom PHN / TAB / MAC say nothing at all. Shape
     * is also simply faster to scan than three letters when the real label sits
     * beside it either way. The drawings live in one Blade partial keyed by
     * category - see resources/views/partials/category-icon.blade.php, which
     * explains why they are inline SVG rather than the generated PNG sheet.
     *
     * @return list<array{key: string, label: string, count: int}>
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
            ->with(['images', 'city', 'part', ...\App\Support\Boosted::eagerLoad()])
            ->latest('published_at')
            ->limit(self::RAIL)
            ->get();
    }

    /**
     * The homepage's paid slots — the listings that bought BoostTier::Front.
     *
     * RANDOMISED, AND CAPPED. Randomised because there is no honest ordering
     * available: everyone in here paid the same price for the same slot, so any
     * fixed order would sell the top of the block to whoever bought earliest
     * without saying so. `inRandomOrder()` means the sellers who paid share the
     * attention rather than queue for it.
     *
     * Capped because the homepage has ONE audience that every category shares.
     * Eight slots is what the rail holds; raise the number and each one is
     * worth less to everybody who bought it, which is a decision that should
     * cost somebody an edit to config rather than happen by drift.
     *
     * `visible()` rather than a flag: a listing that sells or is taken down
     * leaves this block the moment its status changes, with nothing to clear.
     */
    public function promoted()
    {
        $max = min(self::RAIL, (int) config('remarket.boosts.max_front_page', 8));

        if ($max < 1) {
            return Listing::query()->whereRaw('1 = 0')->get();
        }

        return Listing::visible()
            ->with(['images', 'city', 'part', ...\App\Support\Boosted::eagerLoad()])
            ->whereHas('boosts', fn ($b) => $b->running()->front())
            ->inRandomOrder()
            ->limit($max)
            ->get();
    }

    /**
     * Most viewed — the FALLBACK for the paid slot, not a rail of its own.
     *
     * The homepage shows the paid block when anybody has bought one and this
     * when nobody has. That is on purpose: a „Платени позиции" heading over an
     * empty grid is a dead section on the most visible page of the site, and
     * the day this shipped nobody had bought anything at all. Each heading is
     * true for what is underneath it, which is the only rule that matters here
     * — "most viewed" is a fact about the listings, not an editorial pick, and
     * calling it "featured" would be the small lie this comment used to warn
     * about.
     */
    public function mostViewed()
    {
        return Listing::visible()
            ->with(['images', 'city', 'part', ...\App\Support\Boosted::eagerLoad()])
            ->where('view_count', '>', 0)
            ->orderByDesc('view_count')
            ->limit(self::RAIL)
            ->get();
    }

    /**
     * The models people are actually selling, as search chips.
     *
     * These ARE the landing pages now, not chips pointing at a search. That is
     * the whole difference: a search for "RTX 4070" is empty the week nobody is
     * selling one, while the model page still carries the specs, the price band
     * and somewhere to leave an alert. It is also how the catalogue gets
     * crawled at all - the home page is what links to it.
     *
     * @return \Illuminate\Support\Collection<int, Part>
     */
    public function popularParts()
    {
        return Part::query()
            ->select('parts.id', 'parts.slug', 'parts.manufacturer', 'parts.model', 'parts.variant')
            ->selectRaw('count(listings.id) as live_count')
            ->join('listings', 'listings.part_id', '=', 'parts.id')
            ->where('listings.status', ListingStatus::Active)
            ->whereNull('listings.deleted_at')
            ->where('parts.is_published', true)
            ->groupBy('parts.id', 'parts.slug', 'parts.manufacturer', 'parts.model', 'parts.variant')
            ->orderByDesc('live_count')
            ->limit(12)
            ->get();
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
        $stats = $this->stats();

        return view('livewire.home', [
            'categories'    => $this->categories(),
            'newest'        => $this->newest(),
            'promoted'      => $promoted = $this->promoted(),
            // Only asked for when the paid block has nothing in it — the two
            // share one slot and the query for the loser is not worth running.
            'mostViewed'    => $promoted->isEmpty() ? $this->mostViewed() : collect(),
            'popularParts'  => $this->popularParts(),
            'stats'         => $stats,
        ])->layoutData([
            /*
             * No site name in the title: the layout appends it, and "RIGO
             * · RIGO" is what happens otherwise. What goes here is the
             * phrase someone would search for, since this is the page that
             * ranks for the brand plus the category.
             */
            'title'       => 'Втора употреба компютърни части и гейминг техника',
            'description' => sprintf(
                'Купувай и продавай втора употреба хардуер в България — %d активни обяви. '
                .'Каталог със спецификации, оферти без пазарлък в коментарите и '
                .'преглед на пратката преди плащане.',
                $stats['listings'],
            ),
            'canonical'   => route('home'),
        ]);
    }
}
