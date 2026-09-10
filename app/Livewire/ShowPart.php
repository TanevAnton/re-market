<?php

namespace App\Livewire;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\Part;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The page for one piece of hardware.
 *
 * This is the whole organic-search strategy in one file. Nobody searches for
 * "RE-MARKET"; they search for "rtx 4070 цена бг" and "колко струва 5700x3d
 * втора употреба". A listing page cannot answer either - it is one person's
 * asking price and it disappears when the card sells, taking its rankings with
 * it. This page is permanent, accumulates links, and answers the question the
 * search actually asked.
 *
 * Which is also why it must not be a thin wrapper around a filtered search: a
 * page that says "0 results" to a crawler teaches it the site is empty. What
 * makes it worth indexing is the part of it that survives having no listings -
 * the specs, the price history, the honest statement that nothing is for sale
 * right now and an offer to tell you when that changes.
 */
class ShowPart extends Component
{
    public Part $part;

    public function mount(Part $part): void
    {
        // Unpublished catalogue entries are drafts, not content. Indexing one
        // is how a half-typed spec sheet ends up as our result for a model.
        abort_unless($part->is_published, 404);

        $this->part = $part;
    }

    /** Per-request cache. Private, so Livewire never tries to hydrate it. */
    private ?int $liveCount = null;

    /**
     * How many are for sale right now.
     *
     * Counted, not read from `parts.active_listings_count`. That column is
     * refreshed nightly, which is fine for ranking related models but wrong
     * for anything the reader can check against the page in front of them: a
     * page listing three cards while its own description says there are none
     * is worse than one that says nothing, and it is the description Google
     * prints under the link.
     */
    public function liveCount(): int
    {
        return $this->liveCount ??= Listing::query()
            ->where('part_id', $this->part->id)
            ->where('status', ListingStatus::Active)
            ->count();
    }

    /** Live listings for exactly this part. The reason to be on this page. */
    public function listings()
    {
        return Listing::visible()
            ->with(['images', 'city', 'part'])
            ->where('part_id', $this->part->id)
            ->orderByDesc('bumped_at')
            ->limit(24)
            ->get();
    }

    /**
     * The price band, or null when it cannot be stated honestly.
     *
     * Two ways it is withheld, and both matter more than showing a number:
     * too few listings to mean anything (the command refuses to compute one),
     * and a band old enough to be wrong. A stale median is a specific kind of
     * harmful - it looks current, it gets quoted in negotiations, and nothing
     * on the page tells the reader how old it is.
     *
     * @return array{p25: int, median: int, p75: int, at: Carbon}|null
     */
    public function priceBand(): ?array
    {
        if (! $this->part->price_median_cents || ! $this->part->price_stats_at) {
            return null;
        }

        $maxAge = (int) config('remarket.parts.price_band_max_age_days', 7);

        if ($this->part->price_stats_at->lt(now()->subDays($maxAge))) {
            return null;
        }

        return [
            'p25'    => (int) $this->part->price_p25_cents,
            'median' => (int) $this->part->price_median_cents,
            'p75'    => (int) $this->part->price_p75_cents,
            'at'     => $this->part->price_stats_at,
        ];
    }

    /**
     * Other models someone comparing this one would look at.
     *
     * Same category, ranked by what is actually for sale rather than by
     * catalogue completeness - a page that links to five dead ends is worse
     * than one that links to two live ones. Internal links between these pages
     * are also what gets the long tail crawled at all.
     */
    public function relatedParts()
    {
        return Part::query()
            ->where('category', $this->part->category)
            ->where('is_published', true)
            ->whereKeyNot($this->part->getKey())
            // Counted live rather than read from the nightly column, for the
            // same reason as liveCount(): a number printed beside a link is
            // checked by clicking it, and "3" leading to an empty page is a
            // worse answer than no number.
            ->withCount(['listings as live_count' => fn ($q) => $q->where('status', ListingStatus::Active)])
            ->orderByDesc('live_count')
            ->orderBy('model')
            ->limit(8)
            ->get();
    }

    /** The specs, in the order the catalogue schema declares them. */
    public function specRows(): array
    {
        $schema = $this->part->specSchema();
        $specs  = $this->part->specs ?? [];
        $locale = app()->getLocale();

        $rows = [];

        foreach ($schema as $key => $spec) {
            if (! array_key_exists($key, $specs)) {
                continue;
            }

            $value = $specs[$key];

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $rows[] = [
                'label' => $spec['label'][$locale] ?? $spec['label']['en'] ?? $key,
                'value' => match (true) {
                    is_bool($value)  => $value ? 'да' : 'не',
                    is_array($value) => implode(', ', $value),
                    default          => (string) $value,
                },
                'unit'  => $spec['unit'] ?? null,
                'order' => $spec['priority'] ?? 99,
            ];
        }

        usort($rows, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $rows;
    }

    /** What the tab, the search result and the shared link all say. */
    public function metaTitle(): string
    {
        return $this->part->fullName().' — цени и обяви втора употреба';
    }

    /**
     * The sentence Google prints under the link.
     *
     * Built from the live figures rather than a template, because a
     * description repeated verbatim across ten thousand catalogue pages is
     * treated as boilerplate and ignored - and rightly, since it says nothing
     * about the page it is on.
     */
    public function metaDescription(): string
    {
        $name  = $this->part->fullName();
        $count = $this->liveCount();

        if ($band = $this->priceBand()) {
            return sprintf(
                '%s втора употреба в България: %d активни обяви, средна цена %s. '
                .'Провери състояние, гаранция и цена преди да купиш.',
                $name, $count, $this->money($band['median']),
            );
        }

        if ($count > 0) {
            return sprintf(
                '%s втора употреба в България — %d активни обяви от проверени продавачи.',
                $name, $count,
            );
        }

        return sprintf(
            '%s — спецификации и пазарни цени втора употреба в България. '
            .'В момента няма активни обяви; запази търсене и ще те известим.',
            $name,
        );
    }

    /**
     * Structured data.
     *
     * AggregateOffer is only emitted when there is genuinely something for
     * sale at a real price. Declaring offers that do not exist is the kind of
     * thing that earns a manual penalty rather than a rich result, and it
     * would be a lie told to a machine on the site's behalf.
     */
    public function jsonLd(): string
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => $this->part->fullName(),
            'brand'    => ['@type' => 'Brand', 'name' => $this->part->manufacturer],
            'category' => $this->part->category,
            'url'      => route('part', $this->part),
        ];

        if ($this->part->image_path) {
            $schema['image'] = url($this->part->image_path);
        }

        $live = Listing::query()
            ->where('part_id', $this->part->id)
            ->where('status', ListingStatus::Active)
            ->selectRaw('count(*) as n, min(price_cents) as lo, max(price_cents) as hi')
            ->first();

        if ($live && (int) $live->n > 0) {
            $schema['offers'] = [
                '@type'         => 'AggregateOffer',
                'offerCount'    => (int) $live->n,
                'lowPrice'      => round($live->lo / 100, 2),
                'highPrice'     => round($live->hi / 100, 2),
                'priceCurrency' => 'EUR',
                'availability'  => 'https://schema.org/InStock',
                'itemCondition' => 'https://schema.org/UsedCondition',
            ];
        }

        return json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 0, ',', ' ').' €';
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.show-part', [
            'listings' => $this->listings(),
            'band'      => $this->priceBand(),
            'related'   => $this->relatedParts(),
            'rows'      => $this->specRows(),
            'liveCount' => $this->liveCount(),
        ])->layoutData([
            'title'       => $this->metaTitle(),
            'description' => $this->metaDescription(),
            'canonical'   => route('part', $this->part),
            'ogType'      => 'product',
            'jsonLd'      => $this->jsonLd(),
        ]);
    }
}
