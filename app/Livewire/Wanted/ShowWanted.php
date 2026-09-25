<?php

namespace App\Livewire\Wanted;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\WantedAd;
use App\Models\WantedResponse;
use App\Services\Wanted\WantedMatcher;
use App\Services\Wanted\WantedService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * One request, and the listings offered against it.
 *
 * THE SELLER'S SIDE IS THE INTERESTING HALF. A seller arriving here is shown
 * their OWN matching listings with one button each — not a form, not a message
 * box, not a file upload. The entire cost of answering somebody's request is
 * recognising your own card and clicking once, and everything the buyer needs
 * (photos, specs, price, rating) comes with it because it is already a listing.
 *
 * The buyer's side is a list of those cards, with the budget printed beside
 * each price so an over-budget answer can be judged rather than hidden.
 */
class ShowWanted extends Component
{
    #[Locked]
    public WantedAd $ad;

    public function mount(WantedAd $ad): void
    {
        // A removed or held request is visible to its own author and to an
        // admin, so the buyer can see what happened to it. 404 to everyone
        // else, as everywhere here.
        $mine = auth()->check() && $ad->user_id === auth()->id();

        abort_unless(
            $ad->status->isPubliclyVisible() || $mine || auth()->user()?->is_admin,
            404,
        );

        $this->ad = $ad;

        if (! $mine) {
            $ad->increment('view_count');
        }
    }

    public function isMine(): bool
    {
        return auth()->check() && $this->ad->user_id === auth()->id();
    }

    /** „Имам такова." */
    public function offer(int $listingId, WantedService $wanted): void
    {
        $listing = Listing::find($listingId);

        if (! $listing) {
            $this->addError('offer', 'Обявата вече не съществува.');

            return;
        }

        try {
            $wanted->respond($this->ad, $listing, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('offer', $e->getMessage());

            return;
        }

        session()->flash('status', 'Предложи обявата си. Купувачът получи известие.');
    }

    /** The buyer clears a card. The seller is not told — see WantedResponse. */
    public function dismiss(int $responseId, WantedService $wanted): void
    {
        if ($response = WantedResponse::find($responseId)) {
            $wanted->dismiss($response, auth()->user());
        }
    }

    public function fulfil(WantedService $wanted): void
    {
        try {
            $wanted->fulfil($this->ad, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('offer', $e->getMessage());

            return;
        }

        $this->ad->refresh();

        session()->flash('status', 'Търсенето е затворено.');
    }

    public function reopen(WantedService $wanted): void
    {
        try {
            $wanted->reopen($this->ad, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('offer', $e->getMessage());

            return;
        }

        $this->ad->refresh();
    }

    #[Layout('components.layouts.app')]
    public function render(WantedMatcher $matcher)
    {
        $responses = $this->ad->responses()
            ->pending()
            ->with([
                'listing.images', 'listing.city', 'listing.part', 'seller',
                // The card asks each listing whether it is boosted, and without
                // this that is a query per response. Caught by
                // BoostEagerLoadTest, which is exactly what it is for.
                'listing.boosts' => fn ($q) => $q->running(),
            ])
            ->latest('id')
            ->get()
            // A seller can mark their own listing sold after offering it; a
            // dead card on the buyer's screen is worse than none.
            ->filter(fn (WantedResponse $r) => $r->listing?->status->isPubliclyVisible());

        /*
         * What THIS seller could offer. Only their own, only active, only
         * matching — and only the ones they have not already offered, so the
         * button never appears for something that would be refused.
         */
        $offerable = collect();

        if (auth()->check() && ! $this->isMine() && $this->ad->status->isPubliclyVisible()) {
            $already = $this->ad->responses()->pluck('listing_id');

            $offerable = Listing::query()
                ->where('user_id', auth()->id())
                ->where('status', ListingStatus::Active)
                ->where('category', $this->ad->category)
                ->when($this->ad->part_id, fn ($q) => $q->where('part_id', $this->ad->part_id))
                ->when($this->ad->city_id, fn ($q) => $q->where('city_id', $this->ad->city_id))
                ->whereNotIn('id', $already)
                ->with(['images', 'city', 'part', ...\App\Support\Boosted::eagerLoad()])
                ->latest('bumped_at')
                ->limit(12)
                ->get();
        }

        return view('livewire.wanted.show-wanted', [
            'responses' => $responses,
            'offerable' => $offerable,
        ])->layoutData([
            'title'       => 'Търси се: '.$this->ad->title,
            'description' => mb_substr((string) ($this->ad->detail ?: $this->ad->title), 0, 160),
            'canonical'   => route('wanted.show', $this->ad),
            // A request that is closed or held should not be indexed as live
            // demand — it would send a dealer to a page that can do nothing.
            'noindex'     => ! $this->ad->status->isPubliclyVisible(),
        ]);
    }
}
