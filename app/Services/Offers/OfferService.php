<?php

namespace App\Services\Offers;

use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every offer state transition in the application goes through this class.
 *
 * Livewire components read and render; they do not move offers between states.
 * That is deliberate: the rules below (the floor, the cooldown, the per-listing
 * cap, the single counter) are the product. Spread across three components they
 * would drift apart within a month.
 *
 * The database enforces the hard invariants independently - no self-offers, one
 * pending offer per buyer per listing, positive amounts. This class produces
 * good error messages BEFORE hitting them, but never assumes it is the only
 * guard.
 */
class OfferService
{
    /**
     * Place a buyer's offer.
     *
     * An offer under the seller's private floor is recorded as auto_declined
     * and returned to the buyer looking exactly like any other decline. The
     * seller is never told it happened. That asymmetry is the entire anti-
     * lowball mechanism: the buyer learns "too low" without learning the floor,
     * and the seller's inbox stays worth opening.
     */
    public function place(Listing $listing, User $buyer, int $amountCents, ?string $note = null): Offer
    {
        if (! $listing->acceptsOffersFrom($buyer)) {
            throw OfferException::notAllowed();
        }

        if ($amountCents > $listing->price_cents) {
            throw OfferException::aboveAsking();
        }

        $this->assertBuyerMayOffer($listing, $buyer);

        $belowFloor = $listing->isBelowFloor($amountCents);

        return DB::transaction(function () use ($listing, $buyer, $amountCents, $note, $belowFloor) {
            $offer = new Offer([
                'listing_id'   => $listing->id,
                'buyer_id'     => $buyer->id,
                'seller_id'    => $listing->user_id,
                'amount_cents' => $amountCents,
                'note'         => $note,
                'expires_at'   => now()->addHours(config('remarket.offers.ttl_hours', 48)),
            ]);

            if ($belowFloor) {
                // forceFill: status and responded_at are system-set, and
                // update()/create() would silently drop responded_at.
                $offer->forceFill([
                    'status'       => OfferStatus::AutoDeclined,
                    'responded_at' => now(),
                ]);
            }

            $offer->save();

            if (! $belowFloor) {
                $listing->increment('offer_count');
            }

            return $offer;
        });
    }

    /**
     * Seller accepts. This is the only path that creates a Deal.
     *
     * Accepting closes the auction: every other live offer on the listing is
     * declined in the same transaction, and the listing goes to `reserved` for
     * the configured window. A seller who accepts two offers has sold one item
     * twice, and the buyer who loses that race never comes back.
     */
    public function accept(Offer $offer, User $actor): Deal
    {
        $this->assertRespondable($offer, $actor, seller: true);

        return DB::transaction(function () use ($offer, $actor) {
            $listing = $offer->listing()->lockForUpdate()->first();

            $offer->forceFill([
                'status'       => OfferStatus::Accepted,
                'responded_at' => now(),
            ])->save();

            // Everyone else in the queue is out, including counter-offers.
            Offer::where('listing_id', $listing->id)
                ->whereKeyNot($offer->getKey())
                ->where('status', OfferStatus::Pending)
                ->update([
                    'status'       => OfferStatus::Declined,
                    'responded_at' => now(),
                ]);

            $listing->forceFill([
                'status'         => ListingStatus::Reserved,
                'reserved_until' => now()->addHours(config('remarket.deals.reservation_hours', 72)),
            ])->save();

            $deal = new Deal([
                'listing_id'            => $listing->id,
                'offer_id'              => $offer->id,
                'buyer_id'              => $offer->buyer_id,
                'seller_id'             => $offer->seller_id,
                'inspect_test_selected' => (bool) $listing->accepts_inspect_test,
                'expires_at'            => now()->addHours(config('remarket.deals.reservation_hours', 72)),
            ]);

            // Recording the exact figure weakens the DAC7 advertising carve-out
            // (plan 8.4). The band is the safer shape; the flag decides which.
            if (config('remarket.deals.store_exact_price', true)) {
                $deal->agreed_price_cents = $offer->amount_cents;
            } else {
                $deal->price_band_low_cents  = (int) floor($offer->amount_cents / 10000) * 10000;
                $deal->price_band_high_cents = $deal->price_band_low_cents + 10000;
            }

            $deal->save();

            return $deal;
        });
    }

    public function decline(Offer $offer, User $actor): Offer
    {
        $this->assertRespondable($offer, $actor, seller: true);

        $offer->forceFill([
            'status'       => OfferStatus::Declined,
            'responded_at' => now(),
        ])->save();

        return $offer;
    }

    /**
     * Seller counters. One per chain, enforced here and surfaced in the UI.
     *
     * The counter reuses the same buyer_id/seller_id: those columns say who the
     * two parties ARE, not who moved last - `is_counter` says that. Keeping
     * them stable is also what lets the partial unique index keep working,
     * since the parent leaves `pending` in the same transaction.
     */
    public function counter(Offer $offer, User $actor, int $amountCents, ?string $note = null): Offer
    {
        $this->assertRespondable($offer, $actor, seller: true);

        if ($this->chainCounters($offer) >= config('remarket.offers.max_counters', 1)) {
            throw OfferException::counterAlreadySent();
        }

        if ($amountCents > $offer->listing->price_cents) {
            throw OfferException::aboveAsking();
        }

        return DB::transaction(function () use ($offer, $amountCents, $note) {
            $offer->forceFill([
                'status'       => OfferStatus::Countered,
                'responded_at' => now(),
            ])->save();

            $counter = new Offer([
                'listing_id'      => $offer->listing_id,
                'buyer_id'        => $offer->buyer_id,
                'seller_id'       => $offer->seller_id,
                'amount_cents'    => $amountCents,
                'note'            => $note,
                'parent_offer_id' => $offer->id,
                'is_counter'      => true,
                'expires_at'      => now()->addHours(config('remarket.offers.ttl_hours', 48)),
            ]);

            $counter->save();

            return $counter;
        });
    }

    /** The buyer says yes to the seller's counter. Same outcome as accept(). */
    public function acceptCounter(Offer $counter, User $actor): Deal
    {
        $this->assertRespondable($counter, $actor, seller: false);

        if (! $counter->is_counter) {
            throw OfferException::notYours();
        }

        return DB::transaction(function () use ($counter) {
            $listing = $counter->listing()->lockForUpdate()->first();

            $counter->forceFill([
                'status'       => OfferStatus::Accepted,
                'responded_at' => now(),
            ])->save();

            Offer::where('listing_id', $listing->id)
                ->whereKeyNot($counter->getKey())
                ->where('status', OfferStatus::Pending)
                ->update([
                    'status'       => OfferStatus::Declined,
                    'responded_at' => now(),
                ]);

            $listing->forceFill([
                'status'         => ListingStatus::Reserved,
                'reserved_until' => now()->addHours(config('remarket.deals.reservation_hours', 72)),
            ])->save();

            $deal = new Deal([
                'listing_id'            => $listing->id,
                'offer_id'              => $counter->id,
                'buyer_id'              => $counter->buyer_id,
                'seller_id'             => $counter->seller_id,
                'inspect_test_selected' => (bool) $listing->accepts_inspect_test,
                'expires_at'            => now()->addHours(config('remarket.deals.reservation_hours', 72)),
            ]);

            if (config('remarket.deals.store_exact_price', true)) {
                $deal->agreed_price_cents = $counter->amount_cents;
            } else {
                $deal->price_band_low_cents  = (int) floor($counter->amount_cents / 10000) * 10000;
                $deal->price_band_high_cents = $deal->price_band_low_cents + 10000;
            }

            $deal->save();

            return $deal;
        });
    }

    /**
     * Buyer declines the seller's counter, ending the chain.
     *
     * Recorded as Withdrawn, not Declined, and the distinction is not cosmetic:
     * Declined starts the buyer's cooldown, which exists to stop a buyer
     * pestering a seller who already said no. Here the buyer is the one saying
     * no, so locking them out of their own listing for a day would be backwards.
     */
    public function declineCounter(Offer $counter, User $actor): Offer
    {
        $this->assertRespondable($counter, $actor, seller: false);

        $counter->forceFill([
            'status'       => OfferStatus::Withdrawn,
            'responded_at' => now(),
        ])->save();

        return $counter;
    }

    /**
     * Buyer pulls their own offer back. Distinct from a decline: withdrawing
     * is the buyer's own choice, so it must not start the decline cooldown.
     */
    public function withdraw(Offer $offer, User $actor): Offer
    {
        if ($offer->buyer_id !== $actor->id) {
            throw OfferException::notYours();
        }

        if ($offer->status !== OfferStatus::Pending) {
            throw OfferException::notPending();
        }

        $offer->forceFill([
            'status'       => OfferStatus::Withdrawn,
            'responded_at' => now(),
        ])->save();

        return $offer;
    }

    /**
     * Sweep offers whose TTL ran out. Called from the scheduler.
     *
     * Doing this in bulk SQL rather than model-by-model matters: this runs
     * every few minutes forever, and the set it touches only grows.
     */
    public function expireStale(): int
    {
        return Offer::where('status', OfferStatus::Pending)
            ->where('expires_at', '<', now())
            ->update([
                'status'       => OfferStatus::Expired,
                'responded_at' => now(),
            ]);
    }

    // --- rules -----------------------------------------------------------

    private function assertBuyerMayOffer(Listing $listing, User $buyer): void
    {
        $mine = Offer::where('listing_id', $listing->id)
            ->where('buyer_id', $buyer->id)
            ->get();

        if ($mine->contains(fn (Offer $o) => $o->status === OfferStatus::Pending)) {
            throw OfferException::alreadyOpen();
        }

        // Withdrawn offers do not count. Changing your mind is not spam, and
        // counting them would let a buyer lock themselves out by being careful.
        $spent = $mine->reject(fn (Offer $o) => $o->status === OfferStatus::Withdrawn)->count();
        $max   = (int) config('remarket.offers.max_per_listing', 3);

        if ($spent >= $max) {
            throw OfferException::tooMany($max);
        }

        $cooldown = (int) config('remarket.offers.decline_cooldown', 24);

        $lastDecline = $mine
            ->filter(fn (Offer $o) => $o->status->triggersCooldown())
            ->filter(fn (Offer $o) => $o->responded_at !== null)
            ->sortByDesc('responded_at')
            ->first();

        if ($lastDecline && $lastDecline->responded_at->addHours($cooldown)->isFuture()) {
            throw OfferException::cooling($cooldown);
        }
    }

    /**
     * @param  bool  $seller  true when the seller must be the one acting
     */
    private function assertRespondable(Offer $offer, User $actor, bool $seller): void
    {
        $owner = $seller ? $offer->seller_id : $offer->buyer_id;

        if ($owner !== $actor->id) {
            throw OfferException::notYours();
        }

        if ($offer->status !== OfferStatus::Pending) {
            throw OfferException::notPending();
        }

        // An offer past its TTL is dead even if the sweeper has not run yet.
        // Without this check the winner of a race is the scheduler's timing.
        if ($offer->expires_at->isPast()) {
            $offer->forceFill([
                'status'       => OfferStatus::Expired,
                'responded_at' => now(),
            ])->save();

            throw OfferException::expired();
        }
    }

    /**
     * How many counters this buyer and seller have already exchanged on this
     * listing. Scoped by (listing, buyer) rather than by walking parent_offer_id
     * so that starting a fresh offer after a decline cannot reset the budget -
     * otherwise "one counter per chain" is one counter per chain the buyer
     * chooses to start, which is haggling with extra steps.
     */
    private function chainCounters(Offer $offer): int
    {
        return Offer::where('listing_id', $offer->listing_id)
            ->where('buyer_id', $offer->buyer_id)
            ->where('is_counter', true)
            ->count();
    }
}
