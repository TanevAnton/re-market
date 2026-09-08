<?php

namespace App\Livewire\Listings;

use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Models\Listing;
use App\Services\Listings\ListingService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * The seller's own listings, and the things they can do to them.
 *
 * This page did not exist, which meant a published listing was write-once: a
 * mistyped price stood forever and a card sold elsewhere could not be taken
 * down. Everything here routes through ListingService, where the guards live.
 */
class MyListings extends Component
{
    use WithPagination;

    #[Url(as: 'sast', except: 'all')]
    public string $tab = 'all';

    /** Delete is the one action with no undo, so it asks first. */
    public ?int $confirmingDelete = null;

    public function tabs(): array
    {
        return [
            'all'      => 'Всички',
            'active'   => 'Активни',
            'pending'  => 'За одобрение',
            'reserved' => 'Запазени',
            'sold'     => 'Продадени',
            'expired'  => 'Изтекли',
        ];
    }

    public function bump(int $id): void
    {
        $this->act($id, fn (ListingService $s, Listing $l) => $s->bump($l, auth()->user()));
    }

    public function markSold(int $id): void
    {
        $this->act($id, fn (ListingService $s, Listing $l) => $s->markSold($l, auth()->user()),
            'Обявата е отбелязана като продадена.');
    }

    public function relist(int $id): void
    {
        $this->act($id, fn (ListingService $s, Listing $l) => $s->relist($l, auth()->user()),
            'Обявата е публикувана отново.');
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingDelete = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = null;
    }

    public function delete(int $id): void
    {
        $this->act($id, function (ListingService $s, Listing $l) {
            $s->delete($l, auth()->user());
        }, 'Обявата е изтрита.');

        $this->confirmingDelete = null;
    }

    /**
     * Every action fails the same handful of ways - not yours, wrong status,
     * still on cooldown - and all of them belong on the screen rather than as
     * a 500 in the middle of a seller tidying up their listings.
     */
    private function act(int $id, callable $action, ?string $ok = null): void
    {
        $listing = Listing::withTrashed()->find($id);

        if (! $listing) {
            $this->addError('listing', 'Обявата вече не съществува.');

            return;
        }

        try {
            $action(app(ListingService::class), $listing);
        } catch (RuntimeException $e) {
            $this->addError('listing', $e->getMessage());

            return;
        }

        if ($ok) {
            session()->flash('status', $ok);
        }

        $this->resetPage();
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        $query = Listing::query()
            ->where('user_id', auth()->id())
            ->with(['images', 'city', 'part'])
            // The count a seller actually cares about on this screen.
            ->withCount(['offers as pending_offers_count' => fn ($q) => $q->where('status', OfferStatus::Pending)]);

        $query = match ($this->tab) {
            'active'   => $query->where('status', ListingStatus::Active),
            'pending'  => $query->where('status', ListingStatus::PendingReview),
            'reserved' => $query->where('status', ListingStatus::Reserved),
            'sold'     => $query->where('status', ListingStatus::Sold),
            'expired'  => $query->where('status', ListingStatus::Expired),
            default    => $query,
        };

        return view('livewire.listings.my-listings', [
            'listings' => $query->latest('created_at')->paginate(15),
            'service'  => app(ListingService::class),
        ]);
    }
}
