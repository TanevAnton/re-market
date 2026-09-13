<?php

namespace App\Services\Listings;

use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Models\Deal;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\Offer;
use App\Models\User;
use App\Notifications\PriceDropped;
use App\Services\Moderation\ListingScreener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Everything a seller can do to their own listing after publishing it.
 *
 * Until now they could do nothing at all - a mistyped price was permanent and a
 * card sold on Facebook could not be taken down. That is the hole this fills.
 *
 * The reason it is a service rather than a few methods on the component is that
 * every one of these actions can be turned into a bait-and-switch if the guards
 * are wrong: raise the price after an offer, swap the part for a cheaper one,
 * mark a deal sold to dodge the completion rate. The guards are the feature.
 */
class ListingService
{
    /**
     * Statuses a seller may still edit.
     *
     * Reserved is excluded because a deal is in flight against these exact
     * terms. Sold is excluded because it is finished. Removed is excluded for a
     * different and more important reason: a moderator took it down, and
     * editing it back into shape would be a way to walk around that decision.
     */
    private const EDITABLE = [
        ListingStatus::Draft,
        ListingStatus::PendingReview,
        ListingStatus::Active,
        ListingStatus::Expired,
    ];

    /**
     * Apply the seller's edits.
     *
     * `part_id` is deliberately NOT accepted. Changing which catalogue part a
     * listing points at changes what is being sold, and anyone who already
     * looked at it - or offered on it - was looking at something else. A seller
     * who picked the wrong card deletes the listing and posts again; that costs
     * them two minutes and removes an entire class of switch.
     *
     * @param  array<string, mixed>  $data  already validated by the form
     */
    public function update(Listing $listing, array $data, User $actor): Listing
    {
        $this->authorise($listing, $actor);

        if (! in_array($listing->status, self::EDITABLE, true)) {
            throw new RuntimeException(match ($listing->status) {
                ListingStatus::Reserved => 'Обявата е запазена по приета оферта. Приключи или откажи сделката първо.',
                ListingStatus::Sold     => 'Продадена обява не се редактира. Публикувай нова.',
                ListingStatus::Removed  => 'Обявата е премахната след преглед и не може да се редактира.',
                default                 => 'Обявата не може да се редактира в момента.',
            });
        }

        unset($data['part_id'], $data['category'], $data['user_id'], $data['status']);

        /*
         * The offer floor cannot end up above the asking price.
         *
         * There is a CHECK constraint on the table saying so, and EditListing
         * validates `lte:price` so the seller gets a form error rather than a
         * 500. Neither helps a PARTIAL update: `update(['price_cents' => …])`
         * alone, with a floor already set higher, sails past the form and hits
         * the database, which answers with a QueryException in the middle of
         * somebody editing their listing.
         *
         * No caller does that today - EditListing always sends both fields -
         * but the bulk importer on the backlog is exactly the sort of caller
         * that would. The guards are supposed to live here.
         *
         * Refused rather than fixed up: clearing the floor would silently
         * remove the seller's anti-lowball protection, and scaling it would be
         * inventing a number they did not choose. The edit form has both
         * fields; the message says which one to change.
         */
        $newPrice = array_key_exists('price_cents', $data)
            ? (int) $data['price_cents']
            : (int) $listing->price_cents;

        $newFloor = array_key_exists('min_offer_cents', $data)
            ? $data['min_offer_cents']
            : $listing->min_offer_cents;

        if ($newFloor !== null && (int) $newFloor > $newPrice) {
            throw new RuntimeException(
                'Минималната оферта е над новата цена. Намали и нея, преди да запазиш.'
            );
        }

        $wasCents = (int) $listing->price_cents;

        $listing = DB::transaction(function () use ($listing, $data, $wasCents) {
            $priceChanged = array_key_exists('price_cents', $data)
                && (int) $data['price_cents'] !== $wasCents;

            $listing->fill($data)->save();

            /*
             * A pending offer was made against a number that no longer exists.
             * Leaving it open would let a seller accept 800 lv on a listing now
             * advertised at 1200, or the reverse.
             *
             * Withdrawn, not Declined: a decline puts the buyer on a 24h
             * cooldown, and the buyer did nothing wrong here. Same reasoning as
             * declineCounter in OfferService.
             */
            if ($priceChanged) {
                $this->releasePendingOffers($listing, 'price_changed');
            }

            return $listing;
        });

        // Outside the transaction, like every other dispatch here: a queued job
        // can otherwise outrun its own commit and read a row that is not there.
        $this->announcePriceDrop($listing, $wasCents);

        return $listing;
    }

    /**
     * Tell the people who shortlisted this that it got cheaper.
     *
     * They are the warmest buyers on the site - they looked at this exact item,
     * decided they wanted it, and did not buy at the old number - and until now
     * a price cut reached everybody except them.
     *
     * Four conditions, each of which exists because of a specific way this goes
     * wrong:
     */
    private function announcePriceDrop(Listing $listing, int $wasCents): void
    {
        // 1. Down, not up. "The thing you wanted costs more now" is a message
        //    whose only effect is to make somebody regret giving us an address.
        $drop = $wasCents - (int) $listing->price_cents;

        if ($drop <= 0 || $wasCents <= 0) {
            return;
        }

        // 2. Big enough to be news. A 2 € cut on a 900 € card is not a reason
        //    to interrupt anyone, and a channel that interrupts for nothing
        //    gets muted before it ever carries something worth reading.
        $minPercent = (int) config('remarket.listings.price_drop_min_percent', 3);

        if ($drop / $wasCents * 100 < $minPercent) {
            return;
        }

        // 3. Not a stream. A seller feeling out the market moves the price
        //    several times in an afternoon - individually over the threshold,
        //    collectively the reason somebody turns notifications off.
        $cooldown = (int) config('remarket.listings.price_drop_cooldown_hours', 24);

        if ($listing->price_drop_notified_at?->addHours($cooldown)->isFuture()) {
            return;
        }

        // 4. Only for something a buyer can actually act on. A price change on
        //    a listing awaiting review, or already reserved, is not an offer to
        //    anybody.
        if ($listing->status !== ListingStatus::Active) {
            return;
        }

        $favouriters = User::query()
            ->whereIn('id', Favorite::where('listing_id', $listing->id)->select('user_id'))
            // A seller cannot favourite their own listing through the UI, but
            // the ids travel through the browser and this costs one clause.
            ->whereKeyNot($listing->user_id)
            ->get();

        if ($favouriters->isEmpty()) {
            // The watermark is NOT moved here. Nobody was told anything, so the
            // next drop - which might be the one someone is waiting for - would
            // otherwise be swallowed by a cooldown that protected no one.
            return;
        }

        foreach ($favouriters as $user) {
            $user->notify(new PriceDropped($listing, $wasCents));
        }

        // forceFill: this is not a column a seller submits.
        $listing->forceFill(['price_drop_notified_at' => now()])->save();
    }

    /**
     * Sold, and NOT through the platform.
     *
     * This does not create a Deal and does not touch deals_completed. It is a
     * delisting, not a transaction. Conflating the two would let anyone inflate
     * the completion rate - the one number on a profile that currently cannot
     * be faked - by marking imaginary sales.
     */
    public function markSold(Listing $listing, User $actor): Listing
    {
        $this->authorise($listing, $actor);

        if ($listing->status === ListingStatus::Reserved) {
            // There is an open deal on these terms. Marking it sold here would
            // skip the mutual confirmation both sides owe each other, and with
            // it the completion rate that makes the confirmation worth having.
            throw new RuntimeException('Има активна сделка по тази обява. Приключи я от „Сделки“.');
        }

        if ($listing->status === ListingStatus::Sold) {
            return $listing;
        }

        return DB::transaction(function () use ($listing) {
            $listing->forceFill([
                'status'  => ListingStatus::Sold,
                'sold_at' => now(),
            ])->save();

            $this->releasePendingOffers($listing, 'listing_sold');

            return $listing;
        });
    }

    /**
     * Back on the market - the undo for "marked sold by mistake", and the way
     * an expired listing comes back without being retyped.
     *
     * Re-screened rather than trusted: the photographs may now collide with a
     * listing that did not exist when this one was first published.
     */
    public function relist(Listing $listing, User $actor): Listing
    {
        $this->authorise($listing, $actor);

        if (! in_array($listing->status, [ListingStatus::Sold, ListingStatus::Expired], true)) {
            throw new RuntimeException('Само продадени или изтекли обяви могат да се публикуват отново.');
        }

        $listing = DB::transaction(function () use ($listing) {
            $listing->forceFill([
                'status'     => ListingStatus::Active,
                'sold_at'    => null,
                'bumped_at'  => now(),
                'expires_at' => now()->addDays(config('remarket.listings.expire_after_days', 60)),
            ])->save();

            return $listing;
        });

        app(ListingScreener::class)->screen($listing);

        return $listing->refresh();
    }

    /**
     * Soft delete.
     *
     * Soft, because deals and ratings point at this row and a hard delete would
     * take a completed transaction's history with it. The seller's reputation
     * is attached to deals rather than listings, so removing a listing hides
     * nothing they would want hidden.
     */
    public function delete(Listing $listing, User $actor): void
    {
        $this->authorise($listing, $actor);

        if ($listing->status === ListingStatus::Reserved) {
            throw new RuntimeException('Има активна сделка по тази обява. Приключи или откажи сделката първо.');
        }

        // Listing has a HasOne `deal`, but a listing can accumulate more than
        // one over its life (a cancelled deal, then another), so ask the Deal
        // table directly rather than through the relation.
        if (Deal::where('listing_id', $listing->id)->where('status', DealStatus::Open)->exists()) {
            throw new RuntimeException('Има активна сделка по тази обява.');
        }

        DB::transaction(function () use ($listing) {
            $this->releasePendingOffers($listing, 'listing_deleted');
            $listing->delete();
        });

        Log::info('[listing] deleted by owner', ['listing' => $listing->id]);
    }

    /**
     * Back to the top of the browse list.
     *
     * The cooldown is the whole mechanism: without it, bumping is free and
     * everyone bumps constantly, which makes the ordering meaningless and the
     * front page a race rather than a feed.
     */
    public function bump(Listing $listing, User $actor): Listing
    {
        $this->authorise($listing, $actor);

        if ($listing->status !== ListingStatus::Active) {
            throw new RuntimeException('Само активна обява може да се вдига.');
        }

        $hours = (int) config('remarket.listings.bump_cooldown_hours', 24);
        $next  = $listing->bumped_at?->addHours($hours);

        if ($next && $next->isFuture()) {
            throw new RuntimeException('Можеш да вдигнеш обявата отново '.$next->diffForHumans().'.');
        }

        $listing->forceFill(['bumped_at' => now()])->save();

        return $listing;
    }

    /** When can this seller bump again? Null means now. */
    public function bumpAvailableAt(Listing $listing): ?\Illuminate\Support\Carbon
    {
        $next = $listing->bumped_at?->addHours((int) config('remarket.listings.bump_cooldown_hours', 24));

        return $next?->isFuture() ? $next : null;
    }

    /**
     * Let go of every offer still waiting on this listing.
     *
     * Withdrawn rather than Declined throughout: a decline is a judgement on
     * the buyer's number and puts them on a cooldown. Nothing here is the
     * buyer's fault.
     */
    private function releasePendingOffers(Listing $listing, string $reason): int
    {
        $released = Offer::query()
            ->where('listing_id', $listing->id)
            ->where('status', OfferStatus::Pending)
            ->update([
                'status'       => OfferStatus::Withdrawn,
                'responded_at' => now(),
                'updated_at'   => now(),
            ]);

        if ($released > 0) {
            Log::info('[listing] pending offers released', [
                'listing' => $listing->id, 'count' => $released, 'reason' => $reason,
            ]);
        }

        return $released;
    }

    private function authorise(Listing $listing, User $actor): void
    {
        // Owner only. Moderators act through the moderation queue, which keeps
        // a statement of reasons; this path keeps none and must not become a
        // quiet way to edit somebody else's listing.
        if ($listing->user_id !== $actor->id) {
            throw new RuntimeException('Това не е твоя обява.');
        }
    }
}
