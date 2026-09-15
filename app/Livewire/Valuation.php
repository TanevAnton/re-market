<?php

namespace App\Livewire;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\Part;
use App\Support\SpecFilter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * „Колко струва картата ми" — a valuation page that is really a supply pump.
 *
 * Supply is the bottleneck on this site, and the moment somebody decides to
 * sell a card is the moment before they look up what it is worth. That search
 * currently ends on a competitor, on a Facebook group, or on a forum thread
 * from 2021. It should end here, on a page that answers the question honestly
 * and then offers the obvious next step.
 *
 * It reuses everything: the catalogue, the nightly percentiles, the search
 * aliases. What is new is only the framing - the same data pointed at a seller
 * rather than a buyer.
 *
 * DELIBERATELY PUBLIC AND INDEXABLE. It is an acquisition page; putting it
 * behind a login would defeat the entire purpose.
 */
class Valuation extends Component
{
    #[Url(as: 'q', except: '')]
    public string $q = '';

    #[Url(as: 'model', except: '')]
    public string $slug = '';

    /** How many live asks to show under the band. Enough to be checkable, not a listing page. */
    private const RECENT = 6;

    public function choose(string $slug): void
    {
        $this->slug = $slug;
        $this->q    = '';
        $this->part = false;      // the memo below is now about the wrong model
    }

    public function clear(): void
    {
        $this->reset(['q', 'slug']);
        $this->part = false;
    }

    /**
     * Typing into the box abandons whatever model was chosen.
     *
     * Without this, searching again from a chosen model leaves the old band on
     * screen under a list of new candidates - two answers on one page, one of
     * them stale.
     */
    public function updatedQ(): void
    {
        if (trim($this->q) !== '') {
            $this->slug = '';
            $this->part = false;
        }
    }

    /**
     * Per-request memo. Private, so Livewire never tries to hydrate it.
     *
     * `false` is "not looked up yet" and `null` is "looked up, nothing there" -
     * a plain null would re-run the query on every one of the six callers below
     * for exactly the models that have no page.
     */
    private Part|null|false $part = false;

    public function part(): ?Part
    {
        if ($this->part !== false) {
            return $this->part;
        }

        // is_published matters: an unpublished catalogue row is a draft, and a
        // half-typed spec sheet with a price band on it is worse than no page.
        return $this->part = $this->slug
            ? Part::where('slug', $this->slug)->where('is_published', true)->first()
            : null;
    }

    /** @return \Illuminate\Support\Collection<int, Part> */
    public function results()
    {
        if (mb_strlen(trim($this->q)) < 2) {
            return collect();
        }

        return Part::query()
            ->where('is_published', true)
            ->search(trim($this->q))
            ->limit(12)
            ->get();
    }

    /**
     * The asking prices behind the band, so the number is checkable.
     *
     * A median nobody can inspect is a number to be argued with. Six live
     * listings underneath it turn "според сайта" into "ето ги обявите".
     *
     * @return \Illuminate\Support\Collection<int, Listing>
     */
    public function recentAsks()
    {
        $part = $this->part();

        if (! $part) {
            return collect();
        }

        return Listing::query()
            ->where('part_id', $part->id)
            ->whereIn('status', [ListingStatus::Active, ListingStatus::Reserved])
            ->with(['images', 'city'])
            ->orderByDesc('published_at')
            ->limit(self::RECENT)
            ->get();
    }

    /**
     * What the seller should reasonably expect to ask.
     *
     * The band's own quartiles, said in the seller's language rather than the
     * buyer's: p25 is "sells quickly", p75 is "you will wait". Neither is
     * advice about what the item is WORTH - we do not know its condition, and
     * saying so is the difference between a useful page and one that gets
     * quoted back at us when a card does not sell.
     *
     * @return array{quick: int, typical: int, patient: int, at: \Illuminate\Support\Carbon}|null
     */
    public function guidance(): ?array
    {
        $band = $this->part()?->priceBand();

        if (! $band) {
            return null;
        }

        return [
            'quick'    => $band['p25'],
            'typical'  => $band['median'],
            'patient'  => $band['p75'],
            'at'       => $band['at'],
        ];
    }

    /** Live listings for this exact model, which is what the band is computed from. */
    public function liveCount(): int
    {
        $part = $this->part();

        return $part
            ? Listing::where('part_id', $part->id)
                ->whereIn('status', [ListingStatus::Active, ListingStatus::Reserved])
                ->count()
            : 0;
    }

    public function money(int $cents): string
    {
        return number_format($cents / 100, 0, ',', ' ').' €';
    }

    private function metaDescription(): string
    {
        if ($part = $this->part()) {
            $band = $this->guidance();

            return $band
                ? $part->fullName().' втора употреба в България — обявите се движат между '
                    .$this->money($band['quick']).' и '.$this->money($band['patient'])
                    .'. Виж актуалните обяви и публикувай своята.'
                : $part->fullName().' втора употреба — виж актуалните обяви в България и '
                    .'публикувай своята безплатно.';
        }

        return 'Виж на какви цени се предлагат видеокарти, процесори и компютърни '
            .'части втора употреба в България, преди да продадеш своята.';
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $part = $this->part();

        return view('livewire.valuation', [
            'part'       => $part,
            'results'    => $this->results(),
            'guidance'   => $this->guidance(),
            'asks'       => $this->recentAsks(),
            'live'       => $this->liveCount(),

            /*
             * The one number a seller wants that the band cannot give them.
             * „Струва 750 €" answers what to ask; „пада с 8% на месец" answers
             * whether to ask it this week or next, which is the decision they
             * actually came here to make.
             */
            'history'    => $part?->priceHistory() ?? collect(),
            'trend'      => $part?->priceTrend(),
            'categories' => SpecFilter::categories(),
        ])->layoutData([
            'title'       => $part
                ? 'Колко струва '.$part->fullName().' втора употреба'
                : 'Колко струва техниката ми',
            'description' => $this->metaDescription(),
            /*
             * One indexable page, not one per model.
             *
             * The band and the live asks on a chosen model ARE the /model/
             * page's content, seen from the seller's side. Letting both rank
             * would split whatever authority the model earns between two pages
             * we own, so the per-model states canonicalise into the model page
             * and the half-typed ones into the bare page.
             *
             * Canonical rather than noindex on purpose: the two together are a
             * contradictory instruction, and of the two, canonical is the one
             * that consolidates instead of discarding.
             */
            'canonical'   => $part ? route('part', $part) : route('valuation'),
        ]);
    }
}
