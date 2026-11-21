<?php

namespace App\Livewire;

use App\Enums\ListingStatus;
use App\Models\Part;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * „Сравни модели" - two or three catalogue models side by side.
 *
 * Nobody chooses between a 4070 and a 4070 Super by reading two pages in two
 * tabs, and the people who search „rtx 4070 срещу 4070 super" are a buyer at the
 * moment of deciding. The data is the catalogue the site already has: the spec
 * schema, the market price, what is for sale right now.
 *
 * THE ADDRESS IS THE COMPARISON. `?m=slug,slug` and nothing else, so a
 * comparison can be pasted into a group chat - which is where these arguments
 * actually happen - and means the same thing to whoever opens it. Nothing is
 * stored; there is no „my comparison".
 *
 * ONE CATEGORY AT A TIME. A graphics card has no cores and a processor has no
 * VRAM, and a table of two models with disjoint rows compares nothing. The
 * first model decides the category; anything else is quietly left out of the
 * table rather than rendered as a wall of dashes.
 */
class CompareParts extends Component
{
    public const MAX = 3;

    /**
     * Where „better" is not a matter of taste, by `category.spec`.
     *
     * Deliberately short, and kept here rather than in the catalogue schema
     * because it is an editorial claim about hardware, not a property of the
     * data. TDP and card length are absent on purpose: a lower TDP means a
     * cooler card and also a slower one, and a shorter card fits more cases and
     * usually cools worse. A wrong highlight is the one thing on the page a
     * buyer will quote back at a seller, so ambiguity gets none.
     */
    private const BETTER = [
        'gpu.vram_gb'          => 'higher',
        'cpu.cores'            => 'higher',
        'cpu.threads'          => 'higher',
        'cpu.base_clock_ghz'   => 'higher',
        'cpu.boost_clock_ghz'  => 'higher',
        'ram.total_gb'         => 'higher',
        'ram.speed_mts'        => 'higher',
        'ram.cas_latency'      => 'lower',
        'storage.capacity_gb'  => 'higher',
        'psu.wattage'          => 'higher',
        'monitor.refresh_hz'   => 'higher',
        'monitor.response_ms'  => 'lower',
    ];

    /** Comma-separated slugs, in the order the columns appear. */
    #[Url(as: 'm')]
    public string $models = '';

    /** The search box that adds a model. */
    public string $q = '';

    /** Hide the rows where every model says the same thing. */
    #[Url(as: 'razliki')]
    public bool $onlyDifferences = false;

    /**
     * The models being compared, in column order.
     *
     * @return Collection<int, Part>
     */
    protected function parts(): Collection
    {
        $slugs = $this->slugs();

        if ($slugs === []) {
            return collect();
        }

        $found = Part::query()
            ->where('is_published', true)
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        $ordered = collect($slugs)->map(fn ($s) => $found[$s] ?? null)->filter()->values();

        if ($ordered->isEmpty()) {
            return $ordered;
        }

        $category = $ordered->first()->category;

        return $ordered->where('category', $category)->take(self::MAX)->values();
    }

    /** @return list<string> */
    private function slugs(): array
    {
        return collect(explode(',', $this->models))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->unique()
            ->take(self::MAX + 3) // a few extra: some may be unknown or off-category
            ->values()
            ->all();
    }

    public function add(string $slug): void
    {
        $parts = $this->parts();

        if ($parts->count() >= self::MAX) {
            return;
        }

        $part = Part::query()->where('is_published', true)->where('slug', $slug)->first();

        if (! $part || $parts->contains('id', $part->id)) {
            return;
        }

        if ($parts->isNotEmpty() && $parts->first()->category !== $part->category) {
            return;
        }

        $this->models = $parts->pluck('slug')->push($part->slug)->implode(',');
        $this->q      = '';
    }

    public function remove(string $slug): void
    {
        $this->models = $this->parts()->pluck('slug')->reject(fn ($s) => $s === $slug)->implode(',');
    }

    /**
     * Models matching the search box, in the same category as what is already
     * on the table. Capped and never the ones already shown.
     *
     * @return Collection<int, Part>
     */
    protected function suggestions(Collection $parts): Collection
    {
        $term = trim($this->q);

        if (mb_strlen($term) < 2 || $parts->count() >= self::MAX) {
            return collect();
        }

        // `%` and `_` are wildcards to ILIKE; somebody typing "4070_ti" means
        // an underscore.
        $like = '%'.addcslashes($term, '%_\\').'%';

        return Part::query()
            ->where('is_published', true)
            ->when($parts->isNotEmpty(), fn ($q) => $q->where('category', $parts->first()->category))
            ->whereNotIn('id', $parts->pluck('id'))
            ->whereRaw("(coalesce(manufacturer,'') || ' ' || coalesce(model,'') || ' ' || coalesce(variant,'')) ilike ?", [$like])
            ->orderBy('manufacturer')
            ->orderBy('model')
            ->limit(8)
            ->get();
    }

    /**
     * The table: one row per spec in the catalogue schema's own order, with one
     * cell per model.
     *
     * A row appears only if at least one model has a value for it - a spec
     * nobody filled in is noise, not information. `differs` is what the
     * „само разликите" switch hides rows by, and `best` marks the cell a buyer
     * would call better, where that is not a matter of taste.
     *
     * @param  Collection<int, Part>  $parts
     * @return list<array{label: string, differs: bool, cells: list<array{text: string, best: bool}>}>
     */
    protected function rows(Collection $parts): array
    {
        if ($parts->isEmpty()) {
            return [];
        }

        $schema = $parts->first()->specSchema();
        $locale = app()->getLocale();
        $out    = [];

        foreach ($schema as $key => $spec) {
            $raw = $parts->map(fn (Part $p) => $p->specs[$key] ?? null);

            if ($raw->every(fn ($v) => $v === null || $v === '' || $v === [])) {
                continue;
            }

            $text = $raw->map(fn ($v) => $this->format($v, $spec))->all();

            $out[] = [
                'order'   => $spec['priority'] ?? 99,
                'label'   => $spec['label'][$locale] ?? $spec['label']['en'] ?? $key,
                'differs' => count(array_unique($text)) > 1,
                'cells'   => $this->mark($raw->all(), $text, self::BETTER[$parts->first()->category.'.'.$key] ?? null),
            ];
        }

        usort($out, fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_map(fn ($r) => array_diff_key($r, ['order' => 1]), $out);
    }

    /**
     * Highlight the better number, only where „better" is not a matter of taste.
     *
     * `$dir` comes from BETTER; null means the spec is never highlighted.
     * Nothing is marked when the values tie, or when fewer than two models
     * have a number to compare.
     *
     * @param  list<mixed>  $raw
     * @param  list<string>  $text
     * @return list<array{text: string, best: bool}>
     */
    private function mark(array $raw, array $text, ?string $dir): array
    {
        $cells = array_map(fn ($t) => ['text' => $t, 'best' => false], $text);

        if ($dir === null) {
            return $cells;
        }

        $numbers = [];

        foreach ($raw as $i => $v) {
            if (is_numeric($v)) {
                $numbers[$i] = (float) $v;
            }
        }

        if (count($numbers) < 2 || count(array_unique($numbers)) < 2) {
            return $cells;
        }

        $target = $dir === 'higher' ? max($numbers) : min($numbers);

        foreach ($numbers as $i => $n) {
            if ($n === $target) {
                $cells[$i]['best'] = true;
            }
        }

        return $cells;
    }

    private function format(mixed $value, array $spec): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        $text = match (true) {
            is_bool($value)  => $value ? 'да' : 'не',
            is_array($value) => implode(', ', $value),
            default          => (string) $value,
        };

        $unit = $spec['unit'] ?? null;

        return $unit && ! is_bool($value) ? $text.' '.$unit : $text;
    }

    /**
     * What each model costs today and what is for sale - the two facts the
     * spec sheet cannot give, and the reason to compare here and not on a
     * manufacturer's page.
     *
     * @param  Collection<int, Part>  $parts
     * @return array<int, array{median: ?int, live: int}>
     */
    protected function market(Collection $parts): array
    {
        if ($parts->isEmpty()) {
            return [];
        }

        $live = \App\Models\Listing::query()
            ->whereIn('part_id', $parts->pluck('id'))
            ->where('status', ListingStatus::Active)
            ->groupBy('part_id')
            ->selectRaw('part_id, count(*) as n')
            ->pluck('n', 'part_id');

        return $parts->mapWithKeys(fn (Part $p) => [$p->id => [
            'median' => ($band = $p->priceBand()) ? (int) $band['median'] : null,
            'live'   => (int) ($live[$p->id] ?? 0),
        ]])->all();
    }

    private function title(Collection $parts): string
    {
        return match ($parts->count()) {
            0       => 'Сравни модели хардуер',
            1       => 'Сравни '.$parts[0]->fullName().' с друг модел',
            default => 'Сравнение: '.$parts->map->fullName()->implode(' срещу '),
        };
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $parts = $this->parts();

        $sorted = $parts->pluck('slug')->sort()->implode(',');

        return view('livewire.compare-parts', [
            'parts'       => $parts,
            'rows'        => $this->rows($parts),
            'market'      => $this->market($parts),
            'suggestions' => $this->suggestions($parts),
        ])->layoutData([
            'title'       => $this->title($parts),
            'description' => $parts->count() >= 2
                ? 'Спецификации, пазарна цена втора употреба и активни обяви на едно място: '
                    .$parts->map->fullName()->implode(' срещу ').'.'
                : 'Сравни два или три модела по спецификации и пазарна цена втора употреба.',
            // A comparison of one model, or none, is a thin page that duplicates
            // the model's own - keep it out of the index. Two or more is the
            // content people search for.
            'noindex'     => $parts->count() < 2,
            // The same set in any order is the same page.
            'canonical'   => $parts->count() >= 2
                ? route('compare', ['m' => $sorted])
                : route('compare'),
        ]);
    }
}
