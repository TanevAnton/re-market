<?php

namespace App\Support;

use App\Models\Listing;
use App\Models\Part;
use App\Models\PartPromotionDismissal;
use Illuminate\Support\Collection;

/**
 * What sellers keep typing that the catalogue does not have.
 *
 * `PartSeeder`'s own comment has always said the catalogue is meant to grow
 * variant-level rows „as they appear in listings". The tool that does that did
 * not exist, so in practice the catalogue grew only when somebody edited a
 * seeder - which caps it at however many evenings there are, while the number
 * of models on the Bulgarian used market does not.
 *
 * Meanwhile every seller who typed „ASUS TUF RTX 4070 OC" into „Име на модела"
 * was telling us, unprompted and for free, exactly which row was missing. That
 * is the highest-signal input the site produces and it was being discarded on
 * the way to the database.
 *
 * THE GROUPING IS THE WHOLE PROBLEM. „RTX 4070", „rtx4070", „РТХ 4070" and
 * „RTX 4o7o" are one model and four facts about how Bulgarians type it. Shown
 * as four rows they look like noise and each one sits below the threshold
 * anybody would act on; folded into one they are a model with four ready-made
 * search aliases, which is the part the catalogue cannot get any other way.
 */
class PartPromotion
{
    /**
     * The grouping key: one spelling to rule the several.
     *
     * Cyrillic folded to Latin, `o` corrected to `0` inside anything with a
     * digit in it (the existing search normalisation, reused rather than
     * reinvented), then everything that is not a letter or a number removed -
     * so spacing, hyphens and case stop mattering.
     *
     * Aggressive on purpose. Over-merging is visible and reversible: the admin
     * sees every raw spelling in the cluster and can promote a subset. Under-
     * merging is invisible - it just looks like the queue is empty.
     */
    public static function key(string $raw): string
    {
        $folded = Part::normalizeQuery(Cyrillic::toLatin($raw));

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $folded);
    }

    /**
     * The queue, most-typed first.
     *
     * Grouped in PHP rather than in SQL because the folding above is not
     * expressible as an index-friendly expression - and it does not need to be:
     * the set is uncatalogued listings only, which the partial index keeps
     * narrow and which shrinks every time this screen is used. `$scan` caps it
     * anyway, so a runaway import cannot turn an admin page into a table scan.
     *
     * @return Collection<int, array{key: string, sample: string, count: int,
     *     spellings: array<string, int>, categories: array<string, int>,
     *     listing_ids: list<int>, titles: list<string>, dismissed: bool}>
     */
    public static function clusters(bool $dismissed = false, int $scan = 5000): Collection
    {
        // The scope, not a copy of its conditions: the nav badge counts the same
        // rows, and two definitions of "waiting" would eventually disagree in
        // public - a badge saying 12 over a screen showing 5.
        $rows = Listing::query()
            ->awaitingCatalogue()
            ->latest('id')
            ->limit($scan)
            ->get(['id', 'custom_part', 'category', 'title']);

        $hidden = PartPromotionDismissal::pluck('key')->flip();

        return $rows
            ->groupBy(fn (Listing $l) => self::key((string) $l->custom_part))
            ->reject(fn (Collection $group, string $key) => $key === '')
            ->map(fn (Collection $group, string $key) => self::describe($key, $group))
            ->filter(fn (array $c) => $hidden->has($c['key']) === $dismissed)
            // Set here rather than in describe(), which has no way to know: a
            // field the shape promises and never fills is a field somebody
            // will trust once and be wrong about.
            ->map(fn (array $c) => [...$c, 'dismissed' => $dismissed])
            ->sortByDesc('count')
            ->values();
    }

    /** One cluster, plus everything the admin needs to judge it without opening a listing. */
    private static function describe(string $key, Collection $group): array
    {
        $spellings = $group
            ->countBy(fn (Listing $l) => trim((string) $l->custom_part))
            ->sortDesc();

        return [
            'key'   => $key,
            // The most common spelling, not the first seen: it is what goes in
            // the promotion form and on the dismissal record, and the majority
            // spelling is the one most likely to be the correct one.
            'sample'      => (string) $spellings->keys()->first(),
            'count'       => $group->count(),
            'spellings'   => $spellings->all(),
            'categories'  => $group->countBy('category')->sortDesc()->all(),
            'listing_ids' => $group->pluck('id')->all(),
            'titles'      => $group->take(3)->pluck('title')->all(),
        ];
    }

    /**
     * Every search alias a cluster earns: the raw spellings, plus the folded
     * key, plus each spelling's Cyrillic twin.
     *
     * This is the compounding part. A hand-written seeder guesses at how people
     * type; a cluster is a record of how they actually did, and promoting it
     * teaches the search box something nobody had to imagine.
     *
     * @param  array<string, int>|list<string>  $spellings
     * @return list<string>
     */
    public static function aliasesFrom(array $spellings, string $key): array
    {
        $raw = array_is_list($spellings) ? $spellings : array_keys($spellings);

        $forms = [$key];

        foreach ($raw as $spelling) {
            $lower   = mb_strtolower(trim($spelling));
            $forms[] = $lower;
            $forms[] = Cyrillic::toCyrillic($lower);
            $forms[] = Part::normalizeQuery($lower);
        }

        return array_values(array_unique(array_filter(
            $forms,
            // Two characters matches half the catalogue; an alias that broad is
            // worse than none, because it makes the search box look broken.
            fn (string $f) => mb_strlen($f) >= 3,
        )));
    }

    /**
     * A first guess at manufacturer and model, so the form is not blank.
     *
     * Only ever a guess, and never applied silently: the admin sees it in an
     * editable field. „ASUS TUF RTX 4070 OC" splits correctly; „4070" does not
     * split at all, and leaving the manufacturer empty is the honest output for
     * a string that does not contain one.
     *
     * @return array{manufacturer: string, model: string}
     */
    public static function split(string $sample): array
    {
        $known = [
            'nvidia', 'amd', 'intel', 'asus', 'msi', 'gigabyte', 'asrock', 'zotac',
            'palit', 'gainward', 'sapphire', 'powercolor', 'xfx', 'inno3d', 'evga',
            'corsair', 'kingston', 'gskill', 'g.skill', 'crucial', 'samsung', 'wd',
            'seagate', 'toshiba', 'seasonic', 'bequiet', 'cooler', 'noctua', 'arctic',
            'logitech', 'razer', 'steelseries', 'hyperx', 'keychron', 'ducky',
            'sony', 'microsoft', 'nintendo', 'lian', 'fractal', 'nzxt', 'phanteks',
            'lg', 'dell', 'aoc', 'benq', 'acer', 'philips', 'iiyama', 'adata',
        ];

        $words = preg_split('/\s+/', trim($sample)) ?: [];
        $first = mb_strtolower($words[0] ?? '');

        if (count($words) > 1 && in_array($first, $known, true)) {
            return [
                'manufacturer' => array_shift($words),
                'model'        => implode(' ', $words),
            ];
        }

        return ['manufacturer' => '', 'model' => trim($sample)];
    }
}
