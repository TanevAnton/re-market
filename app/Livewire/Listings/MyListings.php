<?php

namespace App\Livewire\Listings;

use App\Enums\BoostTier;
use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Models\Listing;
use App\Services\Billing\BoostService;
use App\Services\Billing\CreditService;
use App\Services\Listings\ListingService;
use App\Support\Boosted;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    /**
     * Which listing has the paid ladder open.
     *
     * Closed by default, and one at a time. Three priced buttons under every
     * row would make this page a shop the seller has to read past to get at
     * the free controls — which is precisely the site OLX is and this one is
     * not. The ladder is there when it is asked for.
     */
    public ?int $boosting = null;

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

    // --- paid visibility --------------------------------------------------

    public function openBoost(int $id): void
    {
        $this->boosting        = $id;
        $this->confirmingDelete = null;
    }

    public function closeBoost(): void
    {
        $this->boosting = null;
    }

    /**
     * Buy a tier for one of your own listings.
     *
     * Everything that can go wrong here — not yours, wrong status, already
     * running, balance short, free right now — comes back from BoostService as
     * a sentence, and every one of them belongs on the screen. A seller who
     * cannot afford a pin needs to be told that, not handed a 500.
     */
    public function buyBoost(int $id, string $tier): void
    {
        $boostTier = BoostTier::tryFrom($tier);
        $listing   = Listing::find($id);

        if (! $boostTier || ! $listing) {
            $this->addError('boost', 'Обявата вече не съществува.');

            return;
        }

        try {
            $boost = app(BoostService::class)->buy($listing, auth()->user(), $boostTier);
        } catch (HttpException $e) {
            /*
             * FIRST, AND IT HAS TO BE.
             *
             * Symfony's HttpException extends \RuntimeException, so the
             * `abort(404)` that BoostService uses for „not your listing" falls
             * straight into the catch below — where it becomes an inline error
             * with an EMPTY message, because abort() carries none. An
             * ownership violation would render as a blank red line and a 200.
             *
             * A refusal the seller can act on is a message. A request that
             * should never have been made is a status code. Rethrowing keeps
             * the second one a 404.
             */
            throw $e;
        } catch (RuntimeException $e) {
            $this->addError('boost', $e->getMessage());

            return;
        }

        $this->boosting = null;

        session()->flash('status', $boostTier->label().' е активно · '.$boost->formattedPrice());
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
            // Boosted::eagerLoad() for the same reason browse needs it: the
            // ladder asks each row what is running, and without this that is
            // a query per listing on a page that shows fifteen.
            ->with(['images', 'city', 'part', ...Boosted::eagerLoad()])
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
            'boosts'   => app(BoostService::class),
            'balance'  => app(CreditService::class)->balance(auth()->user()),
        ]);
    }
}
