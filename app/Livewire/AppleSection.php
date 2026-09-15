<?php

namespace App\Livewire;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\Part;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * „Apple втора употреба" — one door in front of three categories.
 *
 * Not a fourth category and not a filter: iPhone, iPad and MacBook are already
 * ordinary categories with their own schemas, and this page is the entrance
 * somebody uses when they know the brand before they know the device. It is
 * also the page that can rank for „apple втора употреба" and „iphone втора
 * ръка", which no individual listing ever will.
 *
 * WHAT IT IS REALLY FOR is the bottom half. Buying a used Apple device carries
 * risks a graphics card does not - a phone can be remotely bricked, a MacBook
 * can re-enrol itself into a company's management after a wipe - and this is
 * the one page that can explain, in one place, that the site asks sellers those
 * questions and lets a buyer filter on the answers. That is the entire argument
 * for buying here instead of on a general classifieds board, and it is worth
 * making somewhere other than inside an individual listing.
 *
 * Public and indexable, like every other catalogue surface.
 */
class AppleSection extends Component
{
    /** The three lines, in the order somebody thinks about them. */
    private const LINES = ['iphone', 'ipad', 'macbook'];

    /**
     * Live count and catalogue depth per line.
     *
     * Counted live rather than read from `parts.active_listings_count`: that
     * column is refreshed at 04:10 and this page states a number a visitor can
     * check against the browse page one click away.
     *
     * @return list<array{key: string, label: string, listings: int, models: int, blurb: string}>
     */
    public function lines(): array
    {
        $counts = Listing::query()
            ->whereIn('category', self::LINES)
            ->whereIn('status', [ListingStatus::Active, ListingStatus::Reserved])
            ->groupBy('category')
            // Aliased, because pluck() reads the column off the row BY NAME and
            // an unaliased count(*) has it hunting for a property called
            // "count(*)". Invisible until there is data.
            ->selectRaw('category, count(*) as total')
            ->pluck('total', 'category');

        $models = Part::query()
            ->whereIn('category', self::LINES)
            ->where('is_published', true)
            ->groupBy('category')
            ->selectRaw('category, count(*) as total')
            ->pluck('total', 'category');

        $blurbs = [
            'iphone'  => 'Батерия, iCloud, оригинални части — попитани в обявата, филтрируеми в търсенето.',
            'ipad'    => 'Wi-Fi и Cellular са отделни модели с отделни цени, а не един ред с „различно".',
            'macbook' => 'Чип, памет и диск са запоени. Тук са част от модела, затова и цените са сравними.',
        ];

        return collect(self::LINES)
            ->map(fn (string $key) => [
                'key'      => $key,
                'label'    => \App\Support\SpecFilter::categoryLabel($key),
                'listings' => (int) ($counts[$key] ?? 0),
                'models'   => (int) ($models[$key] ?? 0),
                'blurb'    => $blurbs[$key],
            ])
            ->all();
    }

    /** Everything Apple, for the „виж всички" link and the empty state. */
    public function total(): int
    {
        return Listing::query()
            ->whereIn('category', self::LINES)
            ->whereIn('status', [ListingStatus::Active, ListingStatus::Reserved])
            ->count();
    }

    /**
     * The models people are actually selling right now, across all three lines.
     *
     * A catalogue page with nothing behind it teaches a visitor the site is
     * empty, so this shows only models that have something live - and shows
     * nothing at all rather than a list of zeroes.
     *
     * @return \Illuminate\Support\Collection<int, Part>
     */
    public function busiest()
    {
        return Part::query()
            ->whereIn('category', self::LINES)
            ->where('is_published', true)
            ->where('active_listings_count', '>', 0)
            ->orderByDesc('active_listings_count')
            ->limit(8)
            ->get();
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $total = $this->total();

        return view('livewire.apple-section', [
            'lines'   => $this->lines(),
            'total'   => $total,
            'busiest' => $this->busiest(),
        ])->layoutData([
            'title'       => 'Apple втора употреба — iPhone, iPad и MacBook',
            'description' => 'iPhone, iPad и MacBook втора употреба в България, с проверими '
                .'спецификации: здраве на батерията, статус на iCloud, оригинални части и '
                .'цикли на батерията. '.($total > 0 ? $total.' активни обяви.' : ''),
            'canonical'   => route('apple'),
        ]);
    }
}
