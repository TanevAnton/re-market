<?php

namespace App\Services\Deals;

use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What happens after an offer is accepted.
 *
 * The platform never touches the money - it moves between the two people
 * through the courier's cash-on-delivery. So a "deal" here is not a payment
 * record. It is the thing that measures whether the handshake was honoured,
 * and that measurement is the only real trust signal the site has.
 *
 * Which is why completion is MUTUAL. A seller who could mark their own sale
 * complete would be scoring their own exam; ratings hung off that would be
 * worth nothing. Both sides say it happened, or it did not happen.
 */
class DealService
{
    /**
     * One side says the handover went through.
     *
     * When that makes both sides, the deal completes: the listing is sold, and
     * both people get a completed deal on their record.
     */
    public function confirm(Deal $deal, User $actor): Deal
    {
        $this->assertParty($deal, $actor);
        $this->assertOpen($deal);

        $isBuyer = $deal->buyer_id === $actor->id;
        $column  = $isBuyer ? 'buyer_confirmed_at' : 'seller_confirmed_at';

        if ($deal->{$column} !== null) {
            throw DealException::alreadyConfirmed();
        }

        return DB::transaction(function () use ($deal, $column) {
            $deal->forceFill([$column => now()])->save();

            if ($deal->isMutuallyConfirmed()) {
                $this->complete($deal);
            }

            return $deal;
        });
    }

    /**
     * Called off by one side while the deal is still open.
     *
     * A cancellation costs no reputation. That is deliberate: if backing out
     * honestly and early carried the same penalty as ghosting, nobody would
     * ever do it - they would just stop replying, which is strictly worse for
     * the person on the other end. The reason is required and the other side
     * sees it.
     */
    public function cancel(Deal $deal, User $actor, string $reason): Deal
    {
        $this->assertParty($deal, $actor);
        $this->assertOpen($deal);

        if (trim($reason) === '') {
            throw DealException::reasonRequired();
        }

        return DB::transaction(function () use ($deal, $actor, $reason) {
            $deal->forceFill([
                'status'        => DealStatus::Cancelled,
                'cancelled_at'  => now(),
                'cancelled_by'  => $actor->id,
                'cancel_reason' => mb_substr(trim($reason), 0, 255),
            ])->save();

            // The item is still for sale, so the listing goes back on the
            // market rather than staying reserved for a deal that is over.
            $this->releaseListing($deal);

            return $deal;
        });
    }

    /**
     * Sweep deals whose reservation window ran out.
     *
     * This is where the reputation metric is actually earned. Whoever did not
     * confirm gets an abandoned deal on their record - and if neither did,
     * both do. It reads harshly, and it is meant to: "accepted, then vanished"
     * is the single worst experience this marketplace can produce, and the
     * completion rate on a profile is the only thing that makes it visible to
     * the next person.
     *
     * @return int deals abandoned
     */
    public function lapseStale(): int
    {
        $stale = Deal::query()
            ->where('status', DealStatus::Open)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($stale as $deal) {
            DB::transaction(function () use ($deal) {
                $deal->forceFill([
                    'status'       => DealStatus::Abandoned,
                    'cancelled_at' => now(),
                ])->save();

                if ($deal->buyer_confirmed_at === null) {
                    User::whereKey($deal->buyer_id)->increment('deals_abandoned');
                }

                if ($deal->seller_confirmed_at === null) {
                    User::whereKey($deal->seller_id)->increment('deals_abandoned');
                }

                $this->releaseListing($deal);
            });
        }

        return $stale->count();
    }

    // --- internals -------------------------------------------------------

    private function complete(Deal $deal): void
    {
        $deal->forceFill([
            'status'       => DealStatus::Completed,
            'completed_at' => now(),
        ])->save();

        // Counters live on the user rather than being counted on demand: a
        // profile page renders them on every listing card, and COUNT(*) over
        // deals on every card is how a browse page gets slow.
        User::whereKey($deal->buyer_id)->increment('deals_completed');
        User::whereKey($deal->seller_id)->increment('deals_completed');

        $listing = $deal->listing;

        if ($listing) {
            $listing->forceFill([
                'status'         => ListingStatus::Sold,
                'sold_at'        => now(),
                'reserved_until' => null,
            ])->save();
        }
    }

    /**
     * Put an unsold listing back on the market.
     *
     * Without this a buyer who ghosts takes the listing down with them - the
     * seller's item sits `reserved` forever and the honest party is punished
     * for the other one's behaviour.
     */
    private function releaseListing(Deal $deal): void
    {
        $listing = $deal->listing;

        if (! $listing || $listing->status !== ListingStatus::Reserved) {
            return;
        }

        $listing->forceFill([
            'status'         => ListingStatus::Active,
            'reserved_until' => null,
            'bumped_at'      => now(),
        ])->save();
    }

    private function assertParty(Deal $deal, User $actor): void
    {
        if ($deal->buyer_id !== $actor->id && $deal->seller_id !== $actor->id) {
            throw DealException::notYours();
        }
    }

    private function assertOpen(Deal $deal): void
    {
        if ($deal->status !== DealStatus::Open) {
            throw DealException::notOpen();
        }

        // Past the window the deal is dead even if the sweeper has not run,
        // so acting on it cannot depend on the scheduler's timing.
        if ($deal->expires_at->isPast()) {
            throw DealException::lapsed();
        }
    }
}
