<?php

namespace App\Livewire\Moderation;

use App\Models\Part;
use App\Services\Catalogue\PartPromotionService;
use App\Support\PartPromotion;
use App\Support\SpecFilter;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The screen that makes the catalogue grow by itself.
 *
 * Until this existed the catalogue grew only when somebody sat down and edited
 * a seeder, which caps it at however many evenings there are. Every seller who
 * typed a model the catalogue did not have was producing the exact input needed
 * to fix that, and it went nowhere.
 *
 * Built around ONE mental model: tick the spellings that are the same thing,
 * then say what they are. Merging several rows is the normal case rather than
 * an advanced feature, because the queue's raw material is the same model typed
 * four ways, and a screen that makes the admin handle those one at a time is a
 * screen that produces four catalogue rows for one piece of hardware.
 *
 * The most common action is ATTACH, not create: most of what lands here is
 * „4070" for a row already called „GeForce RTX 4070". Those cost one click and
 * each one teaches the search box a spelling nobody had to think of in advance.
 */
class CatalogueQueue extends Component
{
    /** Cluster keys the admin has ticked. Not locked - the client legitimately
     *  sets these, and an invented key simply matches no cluster. */
    public array $selected = [];

    public bool $showDismissed = false;

    /** '' | 'attach' | 'create' */
    public string $mode = '';

    // --- attach ----------------------------------------------------------
    public string $partSearch = '';

    // --- create ----------------------------------------------------------
    public string $category     = '';
    public string $manufacturer = '';
    public string $model        = '';
    public string $variant      = '';
    public string $launchYear   = '';
    public array  $specs        = [];
    public bool   $publish      = false;

    /** The row whose specs a new variant inherits. Locked: it names a database row. */
    #[Locked]
    public ?int $baseId = null;

    public string $baseSearch = '';

    public string $problem = '';

    /**
     * Per-request memo. The render path asks five times - the list, the
     * selection, the dominant category, the listing ids and the spellings -
     * and each call is a query plus a full regrouping.
     */
    private ?Collection $clusters = null;

    /** @return Collection<int, array<string, mixed>> */
    public function clusters(): Collection
    {
        return $this->clusters ??= PartPromotion::clusters($this->showDismissed);
    }

    /** The ticked clusters, in queue order. */
    public function working(): Collection
    {
        return $this->clusters()->filter(fn (array $c) => in_array($c['key'], $this->selected, true));
    }

    public function toggle(string $key): void
    {
        $this->selected = in_array($key, $this->selected, true)
            ? array_values(array_diff($this->selected, [$key]))
            : [...$this->selected, $key];

        // A form open over a selection that has since changed is a form about
        // something other than what it says it is about.
        $this->closeForm();
    }

    /** Every listing id behind the current selection. */
    private function listingIds(): array
    {
        return $this->working()->flatMap(fn (array $c) => $c['listing_ids'])->unique()->values()->all();
    }

    /** Every raw spelling behind it, which is what becomes search aliases. */
    private function spellings(): array
    {
        return $this->working()->flatMap(fn (array $c) => array_keys($c['spellings']))->unique()->values()->all();
    }

    /** The category most of the selected listings were posted in. */
    public function dominantCategory(): string
    {
        $tally = [];

        foreach ($this->working() as $cluster) {
            foreach ($cluster['categories'] as $category => $n) {
                $tally[$category] = ($tally[$category] ?? 0) + $n;
            }
        }

        arsort($tally);

        return (string) (array_key_first($tally) ?? '');
    }

    // --- opening the forms ------------------------------------------------

    public function startAttach(): void
    {
        if ($this->selected === []) {
            return;
        }

        $this->mode = 'attach';
        $this->reset(['partSearch', 'problem']);
    }

    public function startCreate(): void
    {
        // Not just "nothing ticked": a selection can go stale between renders
        // if another admin promoted the same cluster, and first() on an empty
        // collection is a fatal rather than an empty form.
        $first = $this->working()->first();

        if ($first === null) {
            return;
        }

        $this->mode = 'create';
        $this->reset(['manufacturer', 'model', 'variant', 'launchYear', 'specs',
            'publish', 'baseId', 'baseSearch', 'problem']);

        $this->category = $this->dominantCategory();

        // A blank form for something we can already guess at is a form that
        // gets filled in wrong. The split is only a guess and stays editable.
        $guess = PartPromotion::split((string) $first['sample']);

        $this->manufacturer = $guess['manufacturer'];
        $this->model        = $guess['model'];
    }

    public function closeForm(): void
    {
        $this->reset(['mode', 'partSearch', 'baseId', 'baseSearch', 'problem']);
    }

    // --- attaching --------------------------------------------------------

    /** @return Collection<int, Part> */
    public function partResults(): Collection
    {
        if (mb_strlen(trim($this->partSearch)) < 2) {
            return collect();
        }

        return Part::query()
            ->when($this->dominantCategory(), fn ($q, $c) => $q->where('category', $c))
            ->search(trim($this->partSearch))
            ->limit(10)
            ->get();
    }

    public function attach(int $partId): void
    {
        $part = Part::find($partId);

        if (! $part) {
            return;
        }

        $this->run(fn (PartPromotionService $s) => $s->attach(
            $this->listingIds(), $part, $this->spellings(), auth()->user(),
        ));
    }

    // --- creating ---------------------------------------------------------

    /** @return Collection<int, Part> */
    public function baseResults(): Collection
    {
        if (mb_strlen(trim($this->baseSearch)) < 2) {
            return collect();
        }

        return Part::query()
            ->when($this->category, fn ($q, $c) => $q->where('category', $c))
            ->search(trim($this->baseSearch))
            ->limit(8)
            ->get();
    }

    public function base(): ?Part
    {
        return $this->baseId ? Part::find($this->baseId) : null;
    }

    /**
     * Inherit a base row's specs into the form.
     *
     * The variant-level path PartSeeder's comment describes: „ASUS TUF RTX 4070
     * OC" is a 4070 in every respect except the dimensions, and retyping the
     * other eight fields is how a spec sheet ends up disagreeing with itself.
     */
    public function chooseBase(int $id): void
    {
        $part = Part::find($id);

        if (! $part || $part->category !== $this->category) {
            return;
        }

        $this->baseId     = $part->id;
        $this->baseSearch = '';
        $this->specs      = array_map(
            fn ($v) => is_bool($v) ? $v : (string) (is_array($v) ? implode(', ', $v) : $v),
            $part->specs ?? [],
        );

        if ($this->manufacturer === '') {
            $this->manufacturer = $part->manufacturer;
        }

        $this->launchYear = (string) ($part->launch_year ?? '');
    }

    public function clearBase(): void
    {
        $this->reset(['baseId', 'baseSearch']);
    }

    /** Part-scoped specs only: a listing-scoped one belongs to the unit, not the model. */
    public function specFields(): array
    {
        return array_filter(
            (new SpecFilter($this->category))->schema(),
            fn ($s) => ($s['scope'] ?? 'part') === 'part',
        );
    }

    public function create(): void
    {
        $this->validate([
            'category' => ['required', 'string'],
            'model'    => ['required', 'string', 'max:160'],
            'variant'  => ['nullable', 'string', 'max:160'],
            'manufacturer' => ['nullable', 'string', 'max:80'],
            'launchYear'   => ['nullable', 'integer', 'min:1990', 'max:2100'],
        ], [], [
            'model'        => 'модел',
            'manufacturer' => 'производител',
            'launchYear'   => 'година',
        ]);

        $this->run(fn (PartPromotionService $s) => $s->promote(
            [
                'category'     => $this->category,
                'manufacturer' => $this->manufacturer,
                'model'        => $this->model,
                'variant'      => $this->variant,
                'launch_year'  => $this->launchYear === '' ? null : (int) $this->launchYear,
                'specs'        => $this->castSpecs(),
                'is_published' => $this->publish,
            ],
            $this->listingIds(),
            $this->spellings(),
            auth()->user(),
            $this->base(),
        ));
    }

    /**
     * Form strings back into the types the schema declares.
     *
     * Everything arrives from an HTML control as a string, and a wattage stored
     * as "750" does not match a facet that filters on numbers - the listing
     * would attach, the page would look right, and the filter would silently
     * never return it. That is the same failure the catalogue seed validator
     * was written to catch.
     */
    private function castSpecs(): array
    {
        $schema = $this->specFields();
        $out    = [];

        foreach ($this->specs as $key => $value) {
            if (! isset($schema[$key]) || $value === '' || $value === null) {
                continue;
            }

            $out[$key] = match ($schema[$key]['type'] ?? 'string') {
                'int'         => (int) $value,
                'decimal'     => (float) $value,
                'bool'        => (bool) $value,
                'multiselect' => array_values(array_filter(array_map(
                    'trim',
                    is_array($value) ? $value : explode(',', (string) $value),
                ))),
                default       => (string) $value,
            };
        }

        return $out;
    }

    // --- dismissing -------------------------------------------------------

    public function dismiss(string $key): void
    {
        $cluster = $this->clusters()->firstWhere('key', $key);

        if (! $cluster) {
            return;
        }

        app(PartPromotionService::class)->dismiss($key, $cluster['sample'], auth()->user());

        $this->selected = array_values(array_diff($this->selected, [$key]));
        $this->closeForm();
        $this->clusters = null;
    }

    public function restore(string $key): void
    {
        app(PartPromotionService::class)->restore($key);
        $this->clusters = null;
    }

    /**
     * Run a service call and put whatever it refuses on the screen.
     *
     * The refusals here are the useful part - „вече съществува, закачи вместо
     * да създаваш" is the answer the admin wanted - so they are shown rather
     * than turned into a 500 by an uncaught RuntimeException.
     */
    private function run(callable $operation): void
    {
        try {
            $operation(app(PartPromotionService::class));
        } catch (Throwable $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->reset(['selected', 'mode', 'partSearch', 'manufacturer', 'model',
            'variant', 'launchYear', 'specs', 'publish', 'baseId', 'baseSearch', 'problem']);

        /*
         * The memo was filled BEFORE the promotion. render() runs later in this
         * same request, so leaving it would redraw the queue still containing
         * the rows that were just cleared - and the admin would promote them
         * twice.
         */
        $this->clusters = null;

        $this->dispatch('catalogue-updated');
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.moderation.catalogue-queue', [
            'clusters'   => $this->clusters(),
            'categories' => SpecFilter::categories(),
            'fields'     => $this->mode === 'create' && $this->category ? $this->specFields() : [],
        ])->layoutData(['title' => 'Каталог — предложения от обявите']);
    }
}
