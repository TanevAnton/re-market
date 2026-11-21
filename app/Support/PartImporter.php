<?php

namespace App\Support;

use App\Models\Part;
use Illuminate\Support\Str;

/**
 * Brings catalogue models in from a file of facts.
 *
 * WHY THIS EXISTS. The catalogue grew only when somebody edited a seeder, and
 * the seller who types a card that is not in it - a 2060 SUPER, a 3050 6GB, a
 * 5060 Ti with the smaller memory - finds nothing in the search box, falls back
 * to „Моделът ми го няма" and the listing loses its filters and its price band.
 *
 * WHAT IT WILL NOT DO.
 *
 *   - Overwrite anything. A row that is already there - seeded, promoted from a
 *     seller's wording, or corrected by hand - is skipped, never updated. The
 *     importer adds; it does not have opinions about what is already right.
 *   - Publish by default. New rows are unpublished, as the promotion tool's are:
 *     they feed the listing form's search, the filters and the price bands, and
 *     have no public page until somebody has looked at them. A thin page is
 *     worse than a missing one.
 *   - Trust the file's vocabulary. Every spec value is checked against the
 *     category's schema in config/catalog.php; one that is not an allowed
 *     option, or not the right type, is DROPPED and the rest of the row is
 *     kept. A value that does not match a facet is a value that silently never
 *     appears in it.
 *
 * WHAT COUNTS AS „ALREADY THERE". The same model name once case, spacing and
 * punctuation are ignored („12 GB" and „12GB" are one), or the same chipset
 * with the same memory size and type: the seeded „RTX 3060 12GB" and a file's
 * „RTX 3060 12 GB" are one card, and so are two files' spellings of it.
 *
 * THE FILE. A JSON array of { manufacturer, model, launch_year, specs }. Facts
 * only - no descriptions, no images - and the importer makes the slug and the
 * search aliases itself.
 */
class PartImporter
{
    /** @var array<string, true> */
    private array $models = [];

    /** @var array<string, true> */
    private array $slugs = [];

    /** @var array<string, true> */
    private array $identities = [];

    public function __construct(
        private readonly string $category = 'gpu',
        private readonly bool $publish = false,
    ) {
        foreach (Part::where('category', $this->category)->get(['manufacturer', 'model', 'slug', 'specs']) as $part) {
            $this->remember($part->manufacturer, $part->model, $part->slug, (array) $part->specs);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array{created: list<string>, skipped: array<string, list<string>>, dropped: array<string, list<string>>}
     */
    public function import(array $records, bool $dryRun = false): array
    {
        $report = ['created' => [], 'skipped' => [], 'dropped' => []];

        foreach ($records as $record) {
            $manufacturer = trim((string) ($record['manufacturer'] ?? ''));
            $model        = trim((string) ($record['model'] ?? ''));

            if ($manufacturer === '' || $model === '') {
                $report['skipped']['invalid'][] = $model !== '' ? $model : '(no name)';

                continue;
            }

            [$specs, $dropped] = $this->cleanSpecs((array) ($record['specs'] ?? []));

            // The two a row cannot be a row without. Every filter and every
            // sentence about the part assumes them.
            if (! isset($specs['chipset'], $specs['vram_gb']) && $this->category === 'gpu') {
                $report['skipped']['invalid'][] = $model;

                continue;
            }

            $slug = Str::slug($manufacturer.' '.$model);

            if (isset($this->models[$this->modelKey($model)])) {
                $report['skipped']['exists'][] = $model;

                continue;
            }

            if (isset($this->slugs[$slug])) {
                $report['skipped']['slug'][] = $model;

                continue;
            }

            if (isset($this->identities[$this->identity($manufacturer, $specs)])) {
                $report['skipped']['same-card'][] = $model;

                continue;
            }

            if ($dropped !== []) {
                $report['dropped'][$model] = $dropped;
            }

            if (! $dryRun) {
                Part::create([
                    'category'     => $this->category,
                    'manufacturer' => $manufacturer,
                    'model'        => $model,
                    'variant'      => null,
                    'slug'         => $slug,
                    'launch_year'  => isset($record['launch_year']) ? (int) $record['launch_year'] : null,
                    'specs'        => $specs,
                    'aliases'      => PartAliases::for($model),
                    'is_published' => $this->publish,
                ]);
            }

            // Remembered either way, so a file that lists one card twice - or a
            // dry run - reports what a real run would do.
            $this->remember($manufacturer, $model, $slug, $specs);
            $report['created'][] = $model;
        }

        return $report;
    }

    /**
     * Only what the schema allows, typed as the schema says.
     *
     * @param  array<string, mixed>  $specs
     * @return array{0: array<string, string|int>, 1: list<string>}  [kept, names of dropped keys]
     */
    public function cleanSpecs(array $specs): array
    {
        $schema = (array) config("catalog.categories.{$this->category}.specs", []);
        $kept   = [];
        $drop   = [];

        foreach ($specs as $key => $value) {
            $rule = $schema[$key] ?? null;

            if ($rule === null || $value === null || $value === '') {
                $drop[] = (string) $key;

                continue;
            }

            $type = $rule['type'] ?? 'text';

            if ($type === 'int') {
                // A number the facet has no option for would silently never
                // appear in it, so an int with a list of options is held to it.
                $ok = is_numeric($value) && (float) $value == (int) $value
                    && (! isset($rule['options']) || in_array((int) $value, $rule['options'], true));

                if ($ok) {
                    $kept[$key] = (int) $value;
                } else {
                    $drop[] = (string) $key;
                }

                continue;
            }

            if ($type === 'select' && isset($rule['options'])) {
                // Compared as strings: slot widths are '2', not 2, in the schema.
                $match = collect($rule['options'])->first(fn ($o) => (string) $o === (string) $value);

                if ($match === null) {
                    $drop[] = (string) $key;

                    continue;
                }

                $kept[$key] = $match;

                continue;
            }

            if (is_scalar($value) && $type !== 'multiselect') {
                $kept[$key] = is_numeric($value) && $type !== 'select' && $type !== 'text'
                    ? $value + 0
                    : (string) $value;

                continue;
            }

            $drop[] = (string) $key;
        }

        return [$kept, $drop];
    }

    private function remember(string $manufacturer, string $model, string $slug, array $specs): void
    {
        $this->models[$this->modelKey($model)] = true;
        $this->slugs[$slug]                    = true;

        if (isset($specs['chipset'], $specs['vram_gb'])) {
            $this->identities[$this->identity($manufacturer, $specs)] = true;
        }
    }

    /** Case, spacing and punctuation do not make a different model. */
    private function modelKey(string $model): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($model));
    }

    /** Same maker, chipset, memory size and memory type. */
    private function identity(string $manufacturer, array $specs): string
    {
        return implode('|', [
            mb_strtolower($manufacturer),
            $this->modelKey((string) ($specs['chipset'] ?? '')),
            (string) ($specs['vram_gb'] ?? ''),
            mb_strtolower((string) ($specs['vram_type'] ?? '')),
        ]);
    }
}
