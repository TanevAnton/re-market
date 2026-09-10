<?php

namespace App\Livewire\Favorites;

use App\Models\Favorite;
use App\Models\Listing;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The shortlist.
 *
 * Sold and removed listings are kept rather than hidden, marked for what they
 * are. A card that silently disappears leaves the buyer wondering whether they
 * imagined saving it; one that says "продадена" answers the question they
 * actually have, which is what the thing went for and whether to keep waiting.
 */
class MyFavorites extends Component
{
    use WithPagination;

    #[On('favorite-removed')]
    public function refreshList(): void
    {
        // Unsaving the last item on page 2 must not leave an empty page.
        $this->resetPage();
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $favorites = Favorite::query()
            ->where('user_id', auth()->id())
            ->whereHas('listing')
            ->with(['listing' => fn ($q) => $q->with(['images', 'city', 'part'])])
            ->latest()
            ->paginate(24);

        return view('livewire.favorites.my-favorites', [
            'favorites' => $favorites,
            'gone'      => $favorites->getCollection()
                ->filter(fn (Favorite $f) => ! $f->listing->status->isPubliclyVisible())
                ->count(),
        ])->layoutData(['title' => 'Запазени обяви']);
    }
}
