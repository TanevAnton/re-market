<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Turns facet selections into SQL against the jsonb spec blobs.
 *
 * SAFETY: the only thing interpolated into SQL is the column name, which comes
 * from a hardcoded whitelist here - never from user input. Spec keys are
 * checked against config/catalog.php and then BOUND as parameters, as are all
 * values. An unknown key is dropped rather than queried.
 */
class SpecFilter
{
    private const COLUMNS = ['parts' => 'parts.specs', 'listings' => 'listings.specs'];

    public function __construct(
        private readonly string $category,
    ) {}

    public function schema(): array
    {
        return config("catalog.categories.{$this->category}.specs", []);
    }

    /** Facet-able fields, in the order the sidebar should show them. */
    public function facets(): array
    {
        $facets = array_filter($this->schema(), fn ($s) => isset($s['facet']));
        uasort($facets, fn ($a, $b) => ($a['priority'] ?? 99) <=> ($b['priority'] ?? 99));

        return $facets;
    }

    public function isKnownKey(string $key): bool
    {
        return array_key_exists($key, $this->schema());
    }

    /**
     * Apply one selection to a Listing query.
     *
     * $value shapes:
     *   terms -> array of scalars
     *   range -> ['min' => x, 'max' => y]  (either side optional)
     *   bool  -> true | false
     */
    public function apply(Builder $query, string $key, mixed $value): Builder
    {
        if (! $this->isKnownKey($key) || $this->isEmpty($value)) {
            return $query;
        }

        $spec = $this->schema()[$key];

        // A spec describing the model lives on parts; one describing this
        // particular unit lives on the listing row.
        if (($spec['scope'] ?? 'part') === 'part') {
            return $query->whereHas('part', fn (Builder $p) => $this->clause($p, $key, $spec, $value, 'parts'));
        }

        return $this->clause($query, $key, $spec, $value, 'listings');
    }

    private function clause(Builder $q, string $key, array $spec, mixed $value, string $table): Builder
    {
        $col = self::COLUMNS[$table];

        return match ($spec['facet'] ?? 'terms') {
            'range' => $this->range($q, $col, $key, $value),
            'bool'  => $q->whereRaw("({$col}->>?) = ?", [$key, $value ? 'true' : 'false']),
            default => $this->terms($q, $col, $key, $spec, $value),
        };
    }

    private function terms(Builder $q, string $col, string $key, array $spec, mixed $value): Builder
    {
        $values = array_values(array_map('strval', (array) $value));

        // A multiselect spec is stored as a jsonb array, so containment is the
        // correct test - "has HDMI 2.1", not "equals HDMI 2.1".
        if (($spec['type'] ?? null) === 'multiselect') {
            return $q->where(function (Builder $inner) use ($col, $key, $values) {
                foreach ($values as $v) {
                    $inner->orWhereRaw("({$col}->?) @> ?", [$key, json_encode([$v], JSON_UNESCAPED_UNICODE)]);
                }
            });
        }

        $placeholders = implode(',', array_fill(0, count($values), '?'));

        return $q->whereRaw("({$col}->>?) IN ({$placeholders})", [$key, ...$values]);
    }

    private function range(Builder $q, string $col, string $key, mixed $value): Builder
    {
        $min = $value['min'] ?? null;
        $max = $value['max'] ?? null;

        if ($min !== null && $min !== '') {
            $q->whereRaw("({$col}->>?)::numeric >= ?", [$key, $min]);
        }
        if ($max !== null && $max !== '') {
            $q->whereRaw("({$col}->>?)::numeric <= ?", [$key, $max]);
        }

        return $q;
    }

    private function isEmpty(mixed $value): bool
    {
        if (is_bool($value)) {
            return false;
        }

        if (is_array($value)) {
            return array_filter($value, fn ($v) => $v !== null && $v !== '' && $v !== []) === [];
        }

        return $value === null || $value === '';
    }

    // --- presentation ----------------------------------------------------

    public function label(string $key): string
    {
        $spec = $this->schema()[$key] ?? [];

        return $spec['label'][app()->getLocale()] ?? $spec['label']['en'] ?? $key;
    }

    public function help(string $key): ?string
    {
        $spec = $this->schema()[$key] ?? [];

        return $spec['help'][app()->getLocale()] ?? $spec['help']['en'] ?? null;
    }

    public function unit(string $key): ?string
    {
        return $this->schema()[$key]['unit'] ?? null;
    }

    public static function categories(): array
    {
        return collect(config('catalog.categories'))
            ->map(fn ($c, $k) => [
                'key'   => $k,
                'label' => $c['label'][app()->getLocale()] ?? $c['label']['en'],
                'slug'  => $c['slug'][app()->getLocale()] ?? $c['slug']['en'],
            ])
            ->values()
            ->all();
    }
}
