<?php

namespace App\Services\Billing;

use App\Enums\BoostTier;
use App\Enums\ListingStatus;
use App\Models\Boost;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Buying visibility, and losing it again.
 *
 * WHAT IT REFUSES, and each one is a door rather than defensive programming:
 *
 *  - Somebody else's listing. Paying to promote a stranger's advert is not a
 *    feature anyone asked for and is a good way to get a competitor's listing
 *    pinned somewhere embarrassing.
 *  - A listing that is not publicly visible. Buying a top slot for a sold,
 *    expired or removed listing is selling nothing — and for a PendingReview
 *    listing it would sell a queue-jump, which is the single most dangerous
 *    thing a marketplace can put on sale. THE PEOPLE MOST WILLING TO PAY FOR
 *    INSTANT VISIBILITY ARE THE ONES WITH THE MOST TO GAIN FROM A FAST SCAM.
 *    Money buys a better position among approved listings, never a faster
 *    approval.
 *  - A second boost of a tier that is already running. That is a seller
 *    paying twice for one effect, and they will notice.
 */
class BoostService
{
    public function __construct(private CreditService $credit) {}

    /**
     * Buy a tier for a listing, paid out of the seller's balance.
     *
     * One transaction around the spend and the boost, so a failure cannot
     * leave money taken with nothing bought, or a boost running that nobody
     * paid for.
     */
    public function buy(Listing $listing, User $buyer, BoostTier $tier): Boost
    {
        $this->authorise($listing, $buyer);

        return DB::transaction(function () use ($listing, $buyer, $tier) {
            if ($tier !== BoostTier::Bump && $this->running($listing, $tier)) {
                throw new RuntimeException(
                    'Вече имаш активно „'.$tier->label().'" за тази обява.'
                );
            }

            /*
             * NEVER SELL WHAT IS FREE RIGHT NOW.
             *
             * Every listing gets one free bump a day, and a paid bump does the
             * same thing to the same column. Taking a euro for it while the
             * free button is available — a double click, a stale screen, a
             * direct POST — is the site charging for nothing, and it is the
             * kind of thing a seller finds once and tells everybody about.
             *
             * In the service rather than only in the view, because the view is
             * a screenshot of a moment and this is somebody's money.
             */
            if ($tier === BoostTier::Bump && $listing->freeBumpAvailableAt() === null) {
                throw new RuntimeException(
                    'Можеш да вдигнеш обявата безплатно точно сега — не плащай за това.'
                );
            }

            $price = $tier->priceCents();

            $boost = new Boost();

            $boost->forceFill([
                'listing_id'  => $listing->id,
                'user_id'     => $buyer->id,
                'tier'        => $tier,
                'price_cents' => $price,
                'starts_at'   => now(),
                // Null for a bump: it is an event, not a window. See BoostTier.
                'ends_at'     => $tier->days() > 0 ? now()->addDays($tier->days()) : null,
            ])->save();

            $this->credit->spend($buyer, $price, $tier->label().' · '.$listing->title, $boost);

            /*
             * The bump's entire effect, applied here rather than by a listener.
             * `bumped_at` is what browse sorts on by default, so this IS the
             * product — and doing it inside the transaction means a listing
             * never rises without the payment that lifted it.
             *
             * forceFill because `bumped_at` is not fillable, deliberately:
             * it is a lifecycle column and belongs to the services.
             */
            if ($tier === BoostTier::Bump) {
                $listing->forceFill(['bumped_at' => now()])->save();
            }

            return $boost;
        });
    }

    /**
     * Stop every running boost on a listing and give the money back.
     *
     * Called when a listing leaves public view — sold, reserved, expired, or
     * taken down by a moderator. The seller paid for seven days of a top slot
     * and got two, so five days' worth of nothing is returned as credit.
     *
     * PRO RATA, and rounded in the seller's favour. The arithmetic is small
     * enough that rounding the other way saves nothing and looks like
     * cheating the moment somebody checks it.
     */
    public function stopAll(Listing $listing, string $reason): int
    {
        $refunded = 0;

        foreach (Boost::where('listing_id', $listing->id)->running()->get() as $boost) {
            $refunded += $this->stop($boost, $reason);
        }

        return $refunded;
    }

    public function stop(Boost $boost, string $reason): int
    {
        if (! $boost->isRunning()) {
            return 0;
        }

        return DB::transaction(function () use ($boost, $reason) {
            $total   = max(1, (int) round($boost->starts_at->diffInMinutes($boost->ends_at)));
            $left    = max(0, (int) round(now()->diffInMinutes($boost->ends_at)));
            $refund  = (int) ceil($boost->price_cents * min($left, $total) / $total);

            $boost->forceFill([
                'cancelled_at'     => now(),
                'cancelled_reason' => mb_substr($reason, 0, 40),
            ])->save();

            if ($refund > 0) {
                $this->credit->refund(
                    $boost->user,
                    $refund,
                    'Върнато · '.$boost->tier->label(),
                    $boost,
                );
            }

            return $refund;
        });
    }

    /** Is a tier already running on this listing? */
    public function running(Listing $listing, BoostTier $tier): bool
    {
        return Boost::where('listing_id', $listing->id)
            ->where('tier', $tier)
            ->running()
            ->exists();
    }

    /**
     * What a seller may buy for this listing right now, with the reason when
     * they may not — so the screen can explain rather than just grey a button.
     *
     * @return array<string, string|null>  tier value => refusal, or null if fine
     */
    public function availability(Listing $listing): array
    {
        $out = [];

        foreach (BoostTier::ladder() as $tier) {
            $out[$tier->value] = match (true) {
                $listing->status !== ListingStatus::Active => 'Обявата не е активна.',
                $tier === BoostTier::Bump && $listing->freeBumpAvailableAt() === null
                    => 'Можеш да я вдигнеш безплатно точно сега.',
                $tier !== BoostTier::Bump && $this->running($listing, $tier) => 'Вече е активно.',
                default => null,
            };
        }

        return $out;
    }

    private function authorise(Listing $listing, User $buyer): void
    {
        // 404 rather than 403, like every other owner-only path here.
        abort_unless($listing->user_id === $buyer->id, 404);

        /*
         * ACTIVE, not merely „publicly visible". `isPubliclyVisible()` also
         * covers Reserved, and a reserved listing has an accepted offer on it
         * — selling a top slot for something somebody has already agreed to
         * buy is selling nothing, and the buyer who clicks it finds a deal
         * they cannot have.
         */
        if ($listing->status !== ListingStatus::Active) {
            throw new RuntimeException(match ($listing->status) {
                ListingStatus::PendingReview => 'Обявата чака преглед. Издигането не ускорява проверката.',
                ListingStatus::Reserved      => 'Обявата е запазена — изчакай сделката да приключи.',
                default                      => 'Само активна обява може да се издигне.',
            });
        }
    }
}
