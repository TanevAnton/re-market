<?php

namespace App\Livewire;

use App\Enums\ListingCondition;
use App\Models\City;
use App\Models\Listing;
use App\Models\SavedSearch;
use App\Services\Search\SavedSearchService;
use App\Support\SpecFilter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class BrowseListings extends Component
{
    use WithPagination;

    #[Url(as: 'kat', except: '')]
    public string $category = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    #[Url(as: 'ot', except: '')]
    public string $priceMin = '';

    #[Url(as: 'do', except: '')]
    public string $priceMax = '';

    #[Url(as: 'sast', except: [])]
    public array $condition = [];

    #[Url(as: 'grad', except: '')]
    public string $city = '';

    #[Url(as: 'sort', except: 'new')]
    public string $sort = 'new';

    /** Dynamic facet selections, keyed by spec name. */
    #[Url(as: 'f', except: [])]
    public array $specs = [];

    /**
     * Set on the one page load where the city filter was applied for the user
     * rather than by the user. Drives the notice that says so - a filter the
     * visitor did not choose has to announce itself, or the site simply looks
     * like it has a third of the listings it really has.
     */
    public bool $cityDefaulted = false;

    /** Marks the session as having been offered the home-city default once. */
    private const HOME_CITY_APPLIED = 'browse.home_city_applied';

    public function mount(): void
    {
        $this->cityDefaulted = (bool) session()->pull('browse.city_defaulted', false);

        $this->applyHomeCity();
    }

    /**
     * Start a signed-in visitor in their own city.
     *
     * Half the exchanges on this site are hand-to-hand, so „мога ли да я взема
     * лично" is one of the first two questions a buyer has - and answering it
     * by default is most of the difference between a national wall of listings
     * and a local market.
     *
     * Done as a REDIRECT rather than by assigning the property, for three
     * reasons that all point the same way. The URL then honestly says what is
     * being shown, so it can be shared and bookmarked; Livewire's own
     * query-string handling stays the only thing that writes to a #[Url]
     * property, so there is no ordering question about which runs first; and
     * the back button works.
     *
     * Three guards, because a default that traps is worse than none:
     *  - a URL that mentions `grad` has already decided, including `grad=`
     *  - once per session, so clearing the filter is not undone on the next
     *    page load
     *  - and never when it would empty the page: a city with two listings in
     *    it teaches the visitor the site is dead.
     */
    private function applyHomeCity(): void
    {
        /*
         * Only when this component IS the page being served at /obiavi.
         *
         * Redirecting rewrites the address bar, which is only ever the right
         * thing to do for the component that owns the address. It also keeps
         * the behaviour out of every other context the grid is mounted in -
         * an embed, or a test that mounts the component directly to exercise
         * something else entirely.
         */
        if (! request()->routeIs('browse')) {
            return;
        }

        if (request()->has('grad') || session()->get(self::HOME_CITY_APPLIED)) {
            return;
        }

        $city = auth()->user()?->city;

        if (! $city) {
            return;
        }

        // Marked before the count, not after: a user in a quiet town should be
        // asked once and then left alone, not re-counted on every page load.
        session()->put(self::HOME_CITY_APPLIED, true);

        $live = Listing::query()->visible()->where('city_id', $city->id)->count();

        if ($live < (int) config('remarket.listings.home_city_min', 3)) {
            return;
        }

        session()->flash('browse.city_defaulted', true);

        $this->redirect(request()->fullUrlWithQuery(['grad' => $city->slug]), navigate: true);
    }

    public function updated($name): void
    {
        // Any filter change invalidates the current page number.
        if ($name !== 'page') {
            $this->resetPage();
        }

        // Spec facets are category-specific and meaningless once you switch.
        if ($name === 'category') {
            $this->specs = [];
        }

        // Once the visitor has touched the city filter themselves, the notice
        // is no longer telling them anything they did not do.
        if ($name === 'city') {
            $this->cityDefaulted = false;
            $this->currentCity   = false;
        }
    }

    /**
     * The city currently filtered on, or null for the whole country.
     *
     * Memoised per request - the render path asks three times (the dropdown,
     * the notice, and the union that keeps small towns in the dropdown) and
     * `false` distinguishes "not looked up" from "looked up, no such city".
     */
    private City|null|false $currentCity = false;

    public function currentCity(): ?City
    {
        if ($this->currentCity !== false) {
            return $this->currentCity;
        }

        return $this->currentCity = $this->city
            ? City::where('slug', $this->city)->first()
            : null;
    }

    public function showWholeCountry(): void
    {
        $this->city          = '';
        $this->cityDefaulted = false;
        $this->currentCity   = false;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['q', 'priceMin', 'priceMax', 'condition', 'city', 'specs']);
        $this->cityDefaulted = false;
        $this->currentCity   = false;
        $this->resetPage();
    }

    // --- saving this search ----------------------------------------------

    public bool $savingSearch = false;
    public string $searchName = '';

    /**
     * The current filters in the same shape the URL uses.
     *
     * Deliberately the query-string keys rather than the property names: a
     * saved search is then just a browse URL, so it can be re-opened as a
     * normal page and a shared link can be saved without translation.
     */
    public function criteria(): array
    {
        return [
            'kat'  => $this->category,
            'q'    => $this->q,
            'ot'   => $this->priceMin,
            'do'   => $this->priceMax,
            'sast' => $this->condition,
            'grad' => $this->city,
            'f'    => $this->specs,
        ];
    }

    /** Nothing selected is not a search worth saving. */
    public function hasFilters(): bool
    {
        return collect($this->criteria())->filter(fn ($v) => $v !== '' && $v !== [] && $v !== null)
            ->isNotEmpty();
    }

    public function startSaveSearch(SavedSearchService $searches): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $this->savingSearch = true;
        $this->resetErrorBag();

        // Pre-filled from the filters themselves. Asking someone to name a
        // thing before they can save it is where most people abandon; a name
        // they can accept as-is is the difference.
        $this->searchName = Str::limit(
            $searches->describe(new SavedSearch(['criteria' => $this->criteria()])),
            60,
            '',
        );
    }

    public function cancelSaveSearch(): void
    {
        $this->reset(['savingSearch', 'searchName']);
    }

    public function saveSearch(SavedSearchService $searches): void
    {
        $this->validate(
            ['searchName' => ['required', 'string', 'min:2', 'max:80']],
            ['searchName.required' => 'Дай име на търсенето.'],
        );

        try {
            $searches->save(auth()->user(), trim($this->searchName), $this->criteria());
        } catch (RuntimeException $e) {
            $this->addError('searchName', $e->getMessage());

            return;
        }

        $this->reset(['savingSearch', 'searchName']);
        $this->dispatch('search-saved');
    }

    public function removeSpec(string $key): void
    {
        unset($this->specs[$key]);
        $this->resetPage();
    }

    /**
     * Filters shown as chips, above the results, each one removable.
     *
     * Without these the sidebar is the only record of what is applied, and
     * after five facets nobody can tell why they are looking at four listings
     * - they scroll a column of a dozen boxes hunting for the one they set.
     * The chips are also where a filter gets removed, which is the action
     * someone actually wants at that moment.
     *
     * @return list<array{label: string, key: string, spec: string|null}>
     */
    public function activeFilters(): array
    {
        $chips = [];

        if ($this->q !== '') {
            $chips[] = ['label' => '„'.$this->q.'“', 'key' => 'q', 'spec' => null];
        }

        if ($this->category !== '') {
            $chips[] = [
                'label' => SpecFilter::categoryLabel($this->category),
                'key'   => 'category',
                'spec'  => null,
            ];
        }

        if ($this->priceMin !== '' || $this->priceMax !== '') {
            $chips[] = [
                'label' => match (true) {
                    $this->priceMin !== '' && $this->priceMax !== '' => "{$this->priceMin}–{$this->priceMax} €",
                    $this->priceMin !== ''                           => "над {$this->priceMin} €",
                    default                                          => "до {$this->priceMax} €",
                },
                'key'  => 'price',
                'spec' => null,
            ];
        }

        foreach ($this->condition as $value) {
            if ($case = ListingCondition::tryFrom($value)) {
                $chips[] = ['label' => $case->label(), 'key' => 'condition:'.$value, 'spec' => null];
            }
        }

        if ($this->city !== '') {
            $chips[] = [
                'label' => City::where('slug', $this->city)->first()?->name() ?? $this->city,
                'key'   => 'city',
                'spec'  => null,
            ];
        }

        $filter = $this->filter();

        foreach ($this->specs as $key => $value) {
            $chips[] = [
                'label' => $filter->label($key).': '.$this->describeSpec($value, $filter->unit($key)),
                'key'   => 'spec',
                'spec'  => $key,
            ];
        }

        return $chips;
    }

    /** A facet value as a person would say it, not as it is stored. */
    private function describeSpec(mixed $value, ?string $unit): string
    {
        $suffix = $unit ? ' '.$unit : '';

        if (is_array($value) && (isset($value['min']) || isset($value['max']))) {
            $min = $value['min'] ?? '';
            $max = $value['max'] ?? '';

            return match (true) {
                $min !== '' && $max !== '' => "{$min}–{$max}{$suffix}",
                $min !== ''                => "над {$min}{$suffix}",
                default                    => "до {$max}{$suffix}",
            };
        }

        if (is_array($value)) {
            return implode(', ', $value).$suffix;
        }

        return is_bool($value) ? 'да' : $value.$suffix;
    }

    /** Remove one filter from a chip. Nothing else about the search moves. */
    public function clearFilter(string $key, ?string $spec = null): void
    {
        match (true) {
            $key === 'q'        => $this->q = '',
            $key === 'category' => $this->reset(['category', 'specs']),
            $key === 'city'     => $this->city = '',
            $key === 'price'    => $this->reset(['priceMin', 'priceMax']),
            $key === 'spec'     => $this->removeSpec((string) $spec),
            str_starts_with($key, 'condition:') => $this->condition = array_values(
                array_diff($this->condition, [substr($key, 10)])
            ),
            default => null,
        };

        $this->resetPage();
    }

    public function filter(): SpecFilter
    {
        return new SpecFilter($this->category);
    }

    /**
     * Only offer facet values that actually exist in the current results.
     * A filter that returns zero listings is worse than no filter at all.
     */
    public function facetOptions(string $key, array $spec): array
    {
        if (! $this->category) {
            return [];
        }

        return Cache::remember(
            "facet:{$this->category}:{$key}",
            now()->addMinutes(10),
            function () use ($key, $spec) {
                if (($spec['scope'] ?? 'part') !== 'part') {
                    return $spec['options'] ?? [];
                }

                $rows = DB::table('parts')
                    ->selectRaw('DISTINCT specs->>? AS value', [$key])
                    ->where('category', $this->category)
                    // jsonb_exists(), not the `?` operator: PDO would read
                    // that `?` as a bind placeholder and the query would break.
                    ->whereRaw('jsonb_exists(specs, ?)', [$key])
                    ->pluck('value')
                    ->filter()
                    ->all();

                // Numeric specs sort numerically; text specs alphabetically.
                usort($rows, fn ($a, $b) => is_numeric($a) && is_numeric($b)
                    ? $a <=> $b
                    : strcmp((string) $a, (string) $b));

                return $rows;
            }
        );
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $filter = $this->filter();

        $query = Listing::query()
            ->visible()
            ->with(['part', 'city', 'user', 'images'])
            ->when($this->category, fn ($q) => $q->where('listings.category', $this->category))
            ->when($this->city, fn ($q) => $q->whereHas('city', fn ($c) => $c->where('slug', $this->city)))
            ->when($this->condition, fn ($q) => $q->whereIn('condition', $this->condition))
            ->when($this->priceMin !== '', fn ($q) => $q->where('price_cents', '>=', (int) ($this->priceMin * 100)))
            ->when($this->priceMax !== '', fn ($q) => $q->where('price_cents', '<=', (int) ($this->priceMax * 100)));

        // Free text hits the listing title and the catalogue aliases alike, so
        // "ртх 4090" and "rtx 4090" both work.
        if ($this->q !== '') {
            $term = mb_strtolower(trim($this->q));
            $query->where(function ($outer) use ($term) {
                $outer->whereRaw('LOWER(listings.title) LIKE ?', ['%'.$term.'%'])
                      ->orWhereHas('part', fn ($p) => $p->matches($term));
            });
        }

        foreach ($this->specs as $key => $value) {
            $query = $filter->apply($query, $key, $value);
        }

        $query = match ($this->sort) {
            'price_asc'  => $query->orderBy('price_cents'),
            'price_desc' => $query->orderByDesc('price_cents'),
            'views'      => $query->orderByDesc('view_count'),
            default      => $query->orderByDesc('bumped_at'),
        };

        $listings = $query->paginate(24);

        return view('livewire.browse-listings', [
            'listings'   => $listings,
            'categories' => SpecFilter::categories(),
            // The forty biggest towns, PLUS whichever one is being filtered on.
            // Without the union, a visitor from a smaller town - exactly the
            // person the home-city default is aimed at - finds their own city
            // missing from the dropdown and the select showing "Цялата страна"
            // while the results are filtered.
            'cities'     => City::query()
                ->orderByDesc('population')
                ->limit(40)
                ->get()
                ->when(
                    $this->currentCity() !== null,
                    fn ($rows) => $rows->contains('slug', $this->city)
                        ? $rows
                        : $rows->push($this->currentCity())->sortByDesc('population')->values(),
                ),
            'conditions' => ListingCondition::cases(),
            'facets'     => $filter->facets(),
            'filter'     => $filter,
        ])->layoutData($this->meta($listings->total(), $listings->currentPage()));
    }

    /**
     * Title, description and how this page should be indexed.
     *
     * Filters multiply into an unbounded number of URLs showing the same
     * listings. Left alone they compete with each other, none of them ranks,
     * and a crawler spends its budget on `?kat=gpu&ot=200&do=400&grad=sofia`
     * instead of on the catalogue pages that are worth ranking.
     *
     * So: the category view is a real page and says so, and anything narrower
     * is noindex with a canonical pointing up at the category. It still works,
     * is still shareable, and still passes links onward - it just does not
     * compete.
     *
     * @return array<string, mixed>
     */
    private function meta(int $total, int $page): array
    {
        $categoryLabel = $this->category ? SpecFilter::categoryLabel($this->category) : null;

        // Anything beyond the category is a slice, not a page.
        $isSlice = $this->q !== ''
            || $this->priceMin !== ''
            || $this->priceMax !== ''
            || $this->condition !== []
            || $this->city !== ''
            || $this->specs !== []
            // Page 2 onward is the same content under a different address.
            // Read off the paginator rather than the component - it is the
            // thing that actually decided which page this is.
            || $page > 1;

        $title = match (true) {
            $this->q !== ''      => 'Търсене: '.$this->q,
            $categoryLabel !== null => $categoryLabel.' втора употреба',
            default              => 'Обяви за компютърен хардуер',
        };

        $description = $categoryLabel
            ? sprintf(
                '%s втора употреба в България — %d обяви с проверени спецификации, '
                .'гаранция и преглед при получаване.',
                $categoryLabel, $total,
            )
            : sprintf(
                'Втора употреба компютърни части и гейминг техника в България — '
                .'%d активни обяви. Филтрирай по модел, спецификации и състояние.',
                $total,
            );

        return [
            'title'       => $title,
            'description' => $description,
            'canonical'   => $this->category
                ? route('browse', ['kat' => $this->category])
                : route('browse'),
            'noindex'     => $isSlice,
        ];
    }
}
