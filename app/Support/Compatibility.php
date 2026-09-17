<?php

namespace App\Support;

use App\Models\Listing;
use App\Models\Part;

/**
 * „Тази карта иска захранване от 750 W нагоре" — as a link to the ones we have.
 *
 * The rules live in config/compatibility.php; this turns one of them into a
 * browse URL, a count, and a sentence. Nothing here knows what a socket or a
 * watt is, which is the point: adding an edge between two categories should be
 * a config entry, never a new method.
 *
 * Every link is counted before it is shown, and an edge with nothing behind it
 * is dropped. On a marketplace this young that matters more than the extra
 * queries: a visitor who follows „Кутии, в които влиза" and lands on an empty
 * result learns the site is empty, and that lesson is expensive to unteach.
 */
class Compatibility
{
    /**
     * @return list<array{label: string, why: ?string, url: string, category: string, count: int}>
     */
    public static function forPart(?Part $part): array
    {
        if (! $part) {
            return [];
        }

        $links = [];

        foreach (config("compatibility.{$part->category}", []) as $rule) {
            $link = self::link($part, $rule);

            if ($link) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * The listing's own specs are deliberately NOT consulted.
     *
     * Compatibility is a property of the model, not of the unit in the
     * photograph: two RTX 4070s draw the same power whatever the seller filled
     * in. Reading listing specs here would also make the same model produce
     * different advice on different pages.
     *
     * @return list<array{label: string, why: ?string, url: string, category: string, count: int}>
     */
    public static function forListing(Listing $listing): array
    {
        return self::forPart($listing->part);
    }

    /**
     * The same rules, as raw filters rather than as sentences.
     *
     * `forPart()` answers „what should I link to from this page"; this answers
     * „what would actually fit next to this part", which is what the build
     * guide needs in order to pick a motherboard for a CPU it has already
     * chosen. Same config, same arithmetic, same `resolve()` — because the day
     * these two disagree is the day the page recommends hardware the link text
     * on the very same site says will not work.
     *
     * Unlike `link()` this does NOT drop a rule whose result has no listings
     * behind it: an empty slot is a real answer here, and the caller needs to
     * be able to say „nothing on the site fits this yet" rather than silently
     * dropping the constraint and proposing something that does not fit.
     *
     * @return list<array{facet: string, filter: array<string, mixed>|list<string>}>
     */
    public static function filtersBetween(Part $from, string $toCategory): array
    {
        $out = [];

        foreach (config("compatibility.{$from->category}", []) as $rule) {
            if (($rule['to'] ?? null) !== $toCategory) {
                continue;
            }

            $source = $from->spec($rule['from'] ?? '');

            if ($source === null || $source === '' || $source === []) {
                continue;
            }

            $value = self::resolve($source, $rule);

            if ($value === null) {
                continue;
            }

            $filter = match ($rule['op']) {
                'min'   => ['min' => $value],
                'max'   => ['max' => $value],
                'is'    => array_values((array) $value),
                default => null,
            };

            if ($filter !== null) {
                $out[] = ['facet' => $rule['facet'], 'filter' => $filter];
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $rule */
    private static function link(Part $part, array $rule): ?array
    {
        $source = $part->spec($rule['from'] ?? '');

        if ($source === null || $source === '' || $source === []) {
            return null;
        }

        $value = self::resolve($source, $rule);

        if ($value === null) {
            return null;
        }

        $filter = match ($rule['op']) {
            'min'   => ['min' => $value],
            'max'   => ['max' => $value],
            'is'    => array_values((array) $value),
            default => null,
        };

        if ($filter === null) {
            return null;
        }

        $count = self::count($rule['to'], $rule['facet'], $filter);

        if ($count === 0) {
            return null;
        }

        $replace = [
            ':from'  => self::readable($source),
            ':value' => self::readable($value),
        ];

        return [
            'label'    => strtr($rule['bg'], $replace),
            'why'      => isset($rule['why']) ? strtr($rule['why'], $replace) : null,
            'url'      => route('browse', ['kat' => $rule['to'], 'f' => [$rule['facet'] => $filter]]),
            'category' => $rule['to'],
            'count'    => $count,
        ];
    }

    /**
     * Apply the arithmetic, if any. Returns null when the rule declines to
     * fire - a source number under `from_min`, or a transform that lands
     * somewhere meaningless.
     *
     * @param  array<string, mixed>  $rule
     */
    private static function resolve(mixed $source, array $rule): mixed
    {
        $transform = $rule['transform'] ?? null;
        $fromMin   = $rule['from_min'] ?? null;

        // Non-numeric sources (a socket, a memory type, a list of sockets)
        // cannot be transformed and are passed through untouched.
        if (! is_numeric($source)) {
            return $transform === null && $fromMin === null ? $source : null;
        }

        $number = (int) $source;

        if ($fromMin !== null && $number < (int) $fromMin) {
            return null;
        }

        if ($transform === null) {
            return $number;
        }

        $number += (int) ($transform['add'] ?? 0);

        if ($step = (int) ($transform['ceil'] ?? 0)) {
            $number = (int) (ceil($number / $step) * $step);
        }

        $number = max($number, (int) ($transform['floor'] ?? 0));

        // A ceiling of zero matches nothing and a floor of zero advertises
        // everything; either way there is no sentence worth printing.
        return $number > 0 ? $number : null;
    }

    /** @param  array<string, mixed>|list<string>  $filter */
    private static function count(string $category, string $facet, array $filter): int
    {
        /*
         * visible(), not active(): the number next to the link has to be the
         * number of cards the link actually shows, and the browse page counts
         * reserved listings too. A count that disagrees with the page it leads
         * to is worse than no count.
         */
        $query = Listing::query()->visible()->where('category', $category);

        (new SpecFilter($category))->apply($query, $facet, $filter);

        return $query->count();
    }

    private static function readable(mixed $value): string
    {
        return is_array($value) ? implode(', ', $value) : (string) $value;
    }
}
