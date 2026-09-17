<?php

namespace App\Support;

use App\Models\Listing;
use Illuminate\Support\Collection;

/**
 * Whole machines, assembled from listings that are actually on the site today.
 *
 * This is the argument for the whole catalogue, made concrete. „Обяви с
 * характеристики" is abstract; „ето този компютър, 1 180 €, осем обяви, всички
 * налични в момента" is not. And it is the one page a general classifieds board
 * cannot produce at all — not because the idea is clever, but because walking
 * from a processor to a motherboard requires knowing that the processor has a
 * socket, and OLX only knows the listing is called „Ryzen 5 5600".
 *
 * HOW A BUILD IS WALKED. Starting from a graphics card, because that is how
 * people actually shop for a machine and because it is the part that decides
 * the two most expensive constraints — the power supply and the case. The rest
 * follows the edges already in config/compatibility.php:
 *
 *   gpu  ─ tdp_w ────────────────▶ psu     (wattage, with headroom)
 *        └ length_mm ───────────▶ case    (max_gpu_mm)
 *   cpu  ─ socket ──────────────▶ motherboard, cooler
 *   motherboard ─ ram_type ────▶ ram
 *               └ form_factor ─▶ case
 *
 * The CPU is picked independently rather than derived, because there is no edge
 * from a graphics card to a processor and inventing one would be a lie: any
 * modern CPU runs any modern card. Everything after it is constrained.
 *
 * WHAT THIS REFUSES TO DO. It never proposes a part that violates a rule in
 * order to complete a build. A slot with nothing compatible on the site stays
 * empty and says so. A half-built machine that is honest about its gaps is
 * worth more than a complete one that will not POST — and on a marketplace this
 * young, empty slots are the normal case rather than the failure case.
 */
class BuildPlanner
{
    /**
     * Slot order, which is also the order constraints propagate.
     *
     * The card first, then the processor, then everything either of them
     * decides. Storage is last and unconstrained — every machine takes an M.2
     * or a SATA disk and there is no edge worth writing for it.
     *
     * @var list<string>
     */
    private const SLOTS = ['gpu', 'cpu', 'motherboard', 'ram', 'cooler', 'case', 'psu', 'storage'];

    /** Slots a machine cannot boot without. A missing cooler is survivable. */
    private const ESSENTIAL = ['gpu', 'cpu', 'motherboard', 'ram', 'psu'];

    /**
     * The slot each constrained slot gets its meaning from.
     *
     * WITHOUT THIS, A CONSTRAINT SILENTLY VANISHES WHEN ITS PARENT IS MISSING.
     * Memory is only ever constrained by the motherboard's `ram_type`, so on a
     * site with no compatible board the RAM slot had nothing filtering it and
     * took the cheapest stick of any type going — producing a build with an
     * empty board slot and a DDR5 module presented as part of the machine.
     * Nothing threw; the page just quietly stopped meaning what it says.
     *
     * So a slot whose parent is empty stays empty too. „Compatible memory" is a
     * claim about a motherboard, and with no motherboard there is no claim to
     * make — only a stick of RAM that happens to be for sale, which the browse
     * page already lists.
     *
     * Only the defining parent is listed. A case is also narrowed by the card's
     * length and the cooler's height, but those are measurements: if they are
     * absent the case is still a case the board fits in. The form factor is
     * different in kind — a board that does not fit does not fit.
     *
     * @var array<string, string>
     */
    private const REQUIRES = [
        'motherboard' => 'cpu',
        'ram'         => 'motherboard',
        'cooler'      => 'cpu',
        'case'        => 'motherboard',
        'psu'         => 'gpu',
    ];

    /**
     * Up to three machines, spread across the price range of live cards.
     *
     * Spread rather than „cheapest, second cheapest, third cheapest": three
     * builds within forty euros of each other demonstrate nothing. Taking the
     * cheapest, the median and the dearest available card produces three
     * genuinely different machines, which is the point of showing three.
     *
     * @return list<array{
     *     anchor: \App\Models\Listing,
     *     slots: list<array{category: string, listing: ?\App\Models\Listing, blocked: bool, requires: ?string, url: string}>,
     *     total_cents: int,
     *     filled: int,
     *     complete: bool,
     * }>
     */
    public static function showcase(int $count = 3): array
    {
        return self::hydrate(self::plan($count));
    }

    /**
     * The same walk, as ids and strings — nothing but scalars.
     *
     * THIS EXISTS BECAUSE ELOQUENT MODELS MUST NOT BE CACHED. The page put the
     * assembled builds straight into `Cache::remember()`, which serialises
     * whatever it is given: the first request was a cache MISS and rendered
     * perfectly, and every request after it unserialised the models into
     * `__PHP_Incomplete_Class` and died with „tried to access a property on an
     * incomplete object". A bug that only appears on the second page load is
     * one that passes every test and every manual check.
     *
     * So the cacheable thing is the PLAN — which listing fills which slot — and
     * the listings themselves are fetched fresh on every request. That also
     * fixes a staleness bug nobody had noticed yet: a cached build would have
     * gone on showing a card for ten minutes after it sold.
     *
     * @return list<array{anchor_id: int, slots: list<array{category: string, listing_id: ?int, blocked: bool, requires: ?string, url: string}>}>
     */
    public static function plan(int $count = 3): array
    {
        $cards = self::liveIn('gpu')
            ->orderBy('price_cents')
            ->with('part')
            ->get();

        if ($cards->isEmpty()) {
            return [];
        }

        return collect(self::spread($cards, $count))
            ->map(fn (Listing $card) => self::around($card))
            ->all();
    }

    /**
     * Turn a plan back into listings, in one query for the whole page.
     *
     * Totals and counts are recomputed from what is ACTUALLY still there rather
     * than carried in the plan, so a part that sold since the plan was cached
     * drops out of the machine and out of its price instead of being quoted at
     * a number nobody can pay.
     *
     * @param  list<array{anchor_id: int, slots: list<array<string, mixed>>}>  $plan
     * @return list<array<string, mixed>>
     */
    public static function hydrate(array $plan): array
    {
        $ids = collect($plan)
            ->flatMap(fn (array $build) => collect($build['slots'])->pluck('listing_id'))
            ->filter()
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        $listings = Listing::query()
            ->visible()
            ->with('part')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($plan as $build) {
            $anchor = $listings[$build['anchor_id']] ?? null;

            // The card the machine is built around is gone: so is the machine.
            // Everything else in it was chosen to fit THAT card.
            if (! $anchor) {
                continue;
            }

            $slots  = [];
            $total  = 0;
            $filled = 0;

            foreach ($build['slots'] as $slot) {
                $listing = $slot['listing_id'] ? ($listings[$slot['listing_id']] ?? null) : null;

                if ($listing) {
                    $total += $listing->price_cents;
                    $filled++;
                }

                $slots[] = [
                    'category' => $slot['category'],
                    'listing'  => $listing,
                    'blocked'  => $slot['blocked'] && ! $listing,
                    'requires' => $slot['requires'],
                    'url'      => $slot['url'],
                ];
            }

            /*
             * Keyed first, and the null coalesce is not paranoia: a plan read
             * back from the cache was written by whatever version of this class
             * was deployed ten minutes ago, so a slot that has since been added
             * or renamed is simply absent. Indexing the result of firstWhere()
             * directly would warn on that rather than treating it as missing,
             * which is what it is.
             */
            $byCategory = collect($slots)->keyBy('category');

            $missing = array_values(array_filter(
                self::ESSENTIAL,
                fn (string $c) => ! ($byCategory[$c]['listing'] ?? null),
            ));

            $out[] = [
                'anchor'      => $anchor,
                'slots'       => $slots,
                'total_cents' => $total,
                'filled'      => $filled,
                'complete'    => $missing === [],
            ];
        }

        return $out;
    }

    /**
     * One machine built around a chosen card, as ids.
     *
     * Deliberately returns no models. This is the value that gets cached, and
     * an Eloquent model in a cache entry is a page that works exactly once —
     * see plan().
     *
     * @return array{anchor_id: int, slots: list<array{category: string, listing_id: ?int, blocked: bool, requires: ?string, url: string}>}
     */
    public static function around(Listing $anchor): array
    {
        /** @var array<string, \App\Models\Listing> $chosen */
        $chosen = ['gpu' => $anchor];
        $slots  = [];

        foreach (self::SLOTS as $category) {
            $listing = $category === 'gpu'
                ? $anchor
                : self::pick($category, $chosen);

            if ($listing) {
                $chosen[$category] = $listing;
            }

            $slots[] = [
                'category'   => $category,
                'listing_id' => $listing?->id,

                /*
                 * Two different kinds of empty, and the page should not say the
                 * same thing about both. „Nothing on the site fits" is a fact
                 * about supply; „we cannot tell what fits until a motherboard is
                 * chosen" is a fact about the build. Collapsing them would have
                 * the page report a shortage that does not exist.
                 */
                'blocked'    => $listing === null
                    && isset(self::REQUIRES[$category])
                    && ! isset($chosen[self::REQUIRES[$category]]),

                'requires'   => self::REQUIRES[$category] ?? null,

                // Where to go when the slot is empty, or when the visitor wants
                // a different one: the same constrained search, as a URL. A
                // string, so it caches; and it is built once rather than on
                // every render.
                'url'        => self::browseUrl($category, $chosen, $listing),
            ];
        }

        return [
            'anchor_id' => $anchor->id,
            'slots'     => $slots,
        ];
    }

    /**
     * The cheapest live listing in a category that satisfies every constraint
     * the already-chosen parts impose on it.
     *
     * Cheapest rather than best: this page exists to show that a usable machine
     * can be had from second-hand parts, and the number at the bottom is the
     * argument. Somebody who wants the better one has the link.
     *
     * @param  array<string, \App\Models\Listing>  $chosen
     */
    private static function pick(string $category, array $chosen): ?Listing
    {
        // No parent, no claim. See REQUIRES.
        $parent = self::REQUIRES[$category] ?? null;

        if ($parent !== null && ! isset($chosen[$parent])) {
            return null;
        }

        $query = self::liveIn($category)->with('part');

        self::constrain($query, $category, $chosen);

        // Two listings of the same model add nothing to a shopping list, and
        // the same seller's whole shelf is not a build.
        return $query->orderBy('price_cents')->first();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Listing>  $query
     * @param  array<string, \App\Models\Listing>  $chosen
     */
    private static function constrain($query, string $category, array $chosen): void
    {
        $filter = new SpecFilter($category);

        foreach ($chosen as $listing) {
            if (! $listing->part) {
                continue;
            }

            foreach (Compatibility::filtersBetween($listing->part, $category) as $rule) {
                $filter->apply($query, $rule['facet'], $rule['filter']);
            }
        }
    }

    /**
     * The browse URL for this slot, carrying the same constraints.
     *
     * So „нищо не пасва още" is a link to a real, correctly filtered search
     * rather than a dead end — and so a visitor who wants a different power
     * supply sees only the ones that will actually run their card.
     *
     * @param  array<string, \App\Models\Listing>  $chosen
     */
    private static function browseUrl(string $category, array $chosen, ?Listing $filled): string
    {
        $facets = [];

        foreach ($chosen as $key => $listing) {
            // A slot does not constrain itself.
            if ($key === $category || ! $listing->part) {
                continue;
            }

            foreach (Compatibility::filtersBetween($listing->part, $category) as $rule) {
                $facets[$rule['facet']] = $rule['filter'];
            }
        }

        return route('browse', array_filter([
            'kat' => $category,
            'f'   => $facets ?: null,
        ]));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Listing>  $cards
     * @return list<\App\Models\Listing>
     */
    private static function spread(Collection $cards, int $count): array
    {
        $n = $cards->count();

        if ($n <= $count) {
            return $cards->all();
        }

        $picked = [];

        // Evenly spaced across the sorted list: cheapest, middle, dearest for
        // three. Integer positions, so the ends are always included.
        //
        // Keyed by id rather than de-duplicated with array_unique(): that
        // compares models loosely, attribute by attribute, which is both slow
        // and wrong the moment two listings happen to match on everything it
        // looks at.
        for ($i = 0; $i < $count; $i++) {
            $card = $cards[(int) round($i * ($n - 1) / max(1, $count - 1))];

            $picked[$card->id] = $card;
        }

        return array_values($picked);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Listing>
     */
    private static function liveIn(string $category)
    {
        /*
         * visible(), matching the browse page and the compatibility counts. A
         * build that includes a listing the visitor cannot open is worse than a
         * build with a gap in it.
         *
         * whereNotNull('part_id') because a listing with no catalogue row has
         * no specs to check against, so it can neither be constrained nor be
         * trusted to fit.
         */
        return Listing::query()
            ->visible()
            ->where('category', $category)
            ->whereNotNull('part_id');
    }
}
