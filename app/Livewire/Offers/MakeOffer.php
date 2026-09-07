<?php

namespace App\Livewire\Offers;

use App\Enums\OfferStatus;
use App\Models\Listing;
use App\Models\Offer;
use App\Services\Offers\OfferException;
use App\Services\Offers\OfferService;
use Livewire\Attributes\Locked;
use App\Livewire\Concerns\ChecksTurnstile;
use Livewire\Component;

/**
 * The offer box in the listing sidebar. Owns exactly one decision - what the
 * buyer sees and what they may type - and hands every state change to
 * OfferService.
 */
class MakeOffer extends Component
{
    use ChecksTurnstile;

    #[Locked]
    public Listing $listing;

    public bool $open = false;

    /** Euros, as typed. Converted to integer cents in one place, on submit. */
    public string $amount = '';

    public string $note = '';

    public function mount(Listing $listing): void
    {
        $this->listing = $listing;
    }

    /**
     * The buyer's own live offer, if any. Recomputed each render rather than
     * held in component state: two tabs open on the same listing would
     * otherwise let one act on what the other already resolved.
     */
    public function myOffer(): ?Offer
    {
        if (! auth()->check()) {
            return null;
        }

        return Offer::where('listing_id', $this->listing->id)
            ->where('buyer_id', auth()->id())
            ->whereIn('status', [OfferStatus::Pending, OfferStatus::Accepted])
            ->latest('id')
            ->first();
    }

    public function canOffer(): bool
    {
        return $this->listing->acceptsOffersFrom(auth()->user());
    }

    public function submit(OfferService $offers): void
    {
        if (! $this->passesTurnstile()) {
            return;
        }

        $data = $this->validate([
            // Two decimals, because a price is money. Bulgarians type both
            // "1200,50" and "1200.50"; normalise before the rule sees it.
            'amount' => ['required', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'note'   => ['nullable', 'string', 'max:'.config('remarket.offers.note_max_length', 200)],
        ], [
            'amount.required' => 'Въведи сума.',
            'amount.regex'    => 'Въведи сума в евро, например 850 или 849,50.',
            'note.max'        => 'Съобщението е твърде дълго.',
        ]);

        $cents = (int) round((float) str_replace(',', '.', $data['amount']) * 100);

        if ($cents < 1) {
            $this->addError('amount', 'Сумата трябва да е над нула.');

            return;
        }

        try {
            $offer = $offers->place($this->listing, auth()->user(), $cents, $data['note'] ?: null);
        } catch (OfferException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->reset('amount', 'note', 'open');

        session()->flash('status', self::resultMessage($offer));
    }

    /**
     * What the buyer is told after placing an offer.
     *
     * A named method rather than an inline string because one specific
     * property of this copy is load-bearing and worth asserting on its own:
     * the buyer learns "too low" and never how low. Naming the floor would
     * turn every listing into a two-guess game and hand the seller's private
     * number to anyone scraping the site.
     */
    public static function resultMessage(Offer $offer): string
    {
        return $offer->status === OfferStatus::AutoDeclined
            ? 'Офертата е под минимума на продавача и беше отказана автоматично.'
            : 'Офертата е изпратена. Продавачът има '
              .config('remarket.offers.ttl_hours', 48).' ч. да отговори.';
    }

    public function withdraw(OfferService $offers): void
    {
        $this->act(fn (Offer $o) => $offers->withdraw($o, auth()->user()),
            'Офертата е оттеглена.');
    }

    /**
     * The seller countered and the buyer is answering, from the listing page.
     *
     * Without these two the counter still arrived, but the only thing the buyer
     * could do with it here was "withdraw" - because the box saw a live offer
     * carrying their own buyer_id and assumed it was theirs.
     */
    public function acceptCounter(OfferService $offers): void
    {
        $this->act(fn (Offer $o) => $offers->acceptCounter($o, auth()->user()),
            'Прие насрещната оферта. Свържете се с продавача.');
    }

    public function declineCounter(OfferService $offers): void
    {
        $this->act(fn (Offer $o) => $offers->declineCounter($o, auth()->user()),
            'Отказа насрещната оферта.');
    }

    private function act(callable $action, string $success): void
    {
        $offer = $this->myOffer();

        if (! $offer) {
            return;
        }

        try {
            $action($offer);
            session()->flash('status', $success);
        } catch (OfferException $e) {
            $this->addError('amount', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.offers.make-offer', [
            'mine' => $this->myOffer(),
        ]);
    }
}
