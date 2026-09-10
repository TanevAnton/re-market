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
    }

    public function clearFilters(): void
    {
        $this->reset(['q', 'priceMin', 'priceMax', 'condition', 'city', 'specs']);
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
            'cities'     => City::orderByDesc('population')->limit(40)->get(),
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
