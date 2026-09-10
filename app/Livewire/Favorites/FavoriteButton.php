<?php

namespace App\Livewire\Favorites;

use App\Models\Favorite;
use App\Models\Listing;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The one control on a listing that does not require talking to anybody.
 *
 * Which is the point: a buyer comparing six graphics cards is not ready to
 * message anyone, and without somewhere to put the shortlist they use browser
 * tabs and lose them. Every saved listing is also a reason to come back, and
 * on a marketplace this thin, return visits are worth more than new ones.
 */
class FavoriteButton extends Component
{
    #[Locked]
    public int $listingId;

    #[Locked]
    public bool $compact = false;

    public bool $saved = false;

    public function mount(Listing $listing, bool $compact = false): void
    {
        $this->listingId = $listing->id;
        $this->compact   = $compact;

        $this->saved = auth()->check() && Favorite::query()
            ->where('user_id', auth()->id())
            ->where('listing_id', $listing->id)
            ->exists();
    }

    public function toggle(): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        if ($this->saved) {
            Favorite::where('user_id', auth()->id())
                ->where('listing_id', $this->listingId)
                ->delete();

            $this->saved = false;

            // The saved-listings page removes the card when this fires, rather
            // than leaving a heart the user just cleared sitting on screen.
            $this->dispatch('favorite-removed', listingId: $this->listingId);

            return;
        }

        /*
         * insertOrIgnore, not create-and-catch.
         *
         * Double-clicking, or having the listing open in two tabs, races the
         * unique index. Catching the violation works - but in Postgres the
         * failed statement aborts the surrounding transaction, so every query
         * after it in the same transaction fails too. Harmless on a plain web
         * request, fatal anywhere a transaction is already open.
         *
         * ON CONFLICT DO NOTHING never raises, so there is no failed statement
         * to poison anything. The intent was "save it", and it ends up saved.
         */
        Favorite::query()->insertOrIgnore([
            'user_id'    => auth()->id(),
            'listing_id' => $this->listingId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.favorites.favorite-button');
    }
}
