<?php

namespace App\Livewire\Offers;

use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Services\Offers\OfferException;
use App\Services\Offers\OfferService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Both sides of the negotiation in one screen: offers you received as a seller,
 * offers you sent as a buyer.
 *
 * Every action here re-fetches the offer by id and lets OfferService re-check
 * ownership and state. Livewire round-trips carry client-supplied ids, so a
 * component that trusts what it was handed is a component that lets anyone
 * accept anyone else's offer.
 */
class OfferInbox extends Component
{
    #[Url(as: 'tab')]
    public string $tab = 'received';

    /** Which offer's counter form is open, by id. */
    public ?int $counteringId = null;

    public string $counterAmount = '';

    public string $counterNote = '';

    public function updatedTab(): void
    {
        $this->reset('counteringId', 'counterAmount', 'counterNote');
    }

    /** @return Collection<int, Offer> */
    public function offers(): Collection
    {
        $query = Offer::query()->with(['listing.images', 'buyer', 'seller', 'counters']);

        if ($this->tab === 'sent') {
            // A counter is the seller's move but carries the same buyer_id, so
            // it lands in the buyer's list as something to answer.
            $query->where('buyer_id', auth()->id());
        } else {
            $query->where('seller_id', auth()->id())
                // Auto-declined lowballs are invisible to the seller. That is
                // the point of the floor - not a filter the seller can undo.
                ->where('status', '!=', OfferStatus::AutoDeclined)
                // The seller's own counters are shown nested under the offer
                // they answer, not as separate rows.
                ->where('is_counter', false);
        }

        // Anything awaiting a decision floats to the top; newest first within
        // each group. Order matters here - a second orderBy cannot outrank a
        // first, so the "needs you" clause has to come before the id sort.
        return $query
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /** Offers received as a seller, still unanswered. */
    public function pendingCount(): int
    {
        return Offer::where('seller_id', auth()->id())
            ->where('status', OfferStatus::Pending)
            ->where('is_counter', false)
            ->count();
    }

    /**
     * Counters sent BACK to this user, waiting on them.
     *
     * These live in the "sent" tab, which is not the default, so without a
     * number on that tab a countered buyer opens an empty "received" list and
     * concludes nothing happened.
     */
    public function counterCount(): int
    {
        return Offer::where('buyer_id', auth()->id())
            ->where('status', OfferStatus::Pending)
            ->where('is_counter', true)
            ->count();
    }

    // --- seller actions --------------------------------------------------

    public function accept(int $offerId, OfferService $offers): void
    {
        $this->run(fn (Offer $o) => $offers->accept($o, auth()->user()), $offerId,
            'Приета. Обявата е запазена, свържете се с купувача.');
    }

    public function decline(int $offerId, OfferService $offers): void
    {
        $this->run(fn (Offer $o) => $offers->decline($o, auth()->user()), $offerId,
            'Офертата е отказана.');
    }

    public function startCounter(int $offerId): void
    {
        $this->counteringId  = $offerId;
        $this->counterAmount = '';
        $this->counterNote   = '';
    }

    public function sendCounter(OfferService $offers): void
    {
        $data = $this->validate([
            'counterAmount' => ['required', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'counterNote'   => ['nullable', 'string', 'max:'.config('remarket.offers.note_max_length', 200)],
        ], [
            'counterAmount.required' => 'Въведи сума.',
            'counterAmount.regex'    => 'Въведи сума в евро, например 850 или 849,50.',
        ]);

        $cents = (int) round((float) str_replace(',', '.', $data['counterAmount']) * 100);
        $id    = $this->counteringId;

        if ($cents < 1 || $id === null) {
            $this->addError('counterAmount', 'Сумата трябва да е над нула.');

            return;
        }

        $this->run(
            fn (Offer $o) => $offers->counter($o, auth()->user(), $cents, $data['counterNote'] ?: null),
            $id,
            'Насрещната оферта е изпратена.',
            errorField: 'counterAmount',
        );

        $this->reset('counteringId', 'counterAmount', 'counterNote');
    }

    // --- buyer actions ---------------------------------------------------

    public function withdraw(int $offerId, OfferService $offers): void
    {
        $this->run(fn (Offer $o) => $offers->withdraw($o, auth()->user()), $offerId,
            'Офертата е оттеглена.');
    }

    public function acceptCounter(int $offerId, OfferService $offers): void
    {
        $this->run(fn (Offer $o) => $offers->acceptCounter($o, auth()->user()), $offerId,
            'Прие насрещната оферта. Свържете се с продавача.');
    }

    public function declineCounter(int $offerId, OfferService $offers): void
    {
        $this->run(fn (Offer $o) => $offers->declineCounter($o, auth()->user()), $offerId,
            'Отказа насрещната оферта.');
    }

    /**
     * One place where an action is looked up, authorised and reported.
     *
     * The lookup is scoped to offers this user is a party to, so a forged id
     * 404s here rather than reaching the service - and the service checks
     * ownership again anyway.
     */
    private function run(callable $action, int $offerId, string $success, string $errorField = 'offer'): void
    {
        $offer = Offer::where('id', $offerId)
            ->where(fn ($q) => $q->where('buyer_id', auth()->id())->orWhere('seller_id', auth()->id()))
            ->first();

        if (! $offer) {
            abort(404);
        }

        try {
            $action($offer);
            session()->flash('status', $success);
        } catch (OfferException $e) {
            $this->addError($errorField, $e->getMessage());
        }
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.offers.offer-inbox', [
            'offers' => $this->offers(),
        ]);
    }
}
