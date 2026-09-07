<?php

namespace App\Services\Ratings;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ratings, which exist only on the back of a completed deal.
 *
 * That constraint is the whole design. On a site where anyone can rate anyone,
 * a five-star average costs a few sock puppets and an afternoon. Here it costs
 * a real transaction that both sides confirmed, which is expensive enough that
 * faking reputation stops being worth it.
 *
 * The numbers a buyer actually reads are still deals_completed and the
 * completion rate - "honoured 24 of 26 agreements" says more than five stars.
 * The star average is context, not the headline.
 */
class RatingService
{
    /** How long after completion a deal can still be rated. */
    private const WINDOW_DAYS = 60;

    public function rate(Deal $deal, User $rater, int $score, ?string $comment = null): Rating
    {
        if ($score < 1 || $score > 5) {
            throw RatingException::badScore();
        }

        if (! $this->canRate($deal, $rater)) {
            throw RatingException::notAllowed();
        }

        $isBuyer = $deal->buyer_id === $rater->id;

        return DB::transaction(function () use ($deal, $rater, $score, $comment, $isBuyer) {
            $rating = Rating::create([
                'deal_id'  => $deal->id,
                'rater_id' => $rater->id,
                'ratee_id' => $isBuyer ? $deal->seller_id : $deal->buyer_id,
                // The role the RATEE played. A profile shows "as a seller" and
                // "as a buyer" separately, because being reliable at one says
                // little about the other.
                'role'     => $isBuyer ? 'seller' : 'buyer',
                'score'    => $score,
                'comment'  => $comment !== null ? mb_substr(trim($comment), 0, 1000) : null,
            ]);

            $this->recompute($rating->ratee_id);

            return $rating;
        });
    }

    /**
     * The rated person gets exactly one public reply. Not a thread: a comment
     * section under a rating turns every disagreement into an argument the
     * whole site can read, and the second reply is never the last one.
     */
    public function reply(Rating $rating, User $ratee, string $reply): Rating
    {
        if ($rating->ratee_id !== $ratee->id) {
            throw RatingException::notYours();
        }

        if ($rating->reply !== null) {
            throw RatingException::alreadyReplied();
        }

        $rating->forceFill(['reply' => mb_substr(trim($reply), 0, 1000)])->save();

        return $rating;
    }

    public function canRate(Deal $deal, User $rater): bool
    {
        if ($deal->status !== DealStatus::Completed) {
            return false;
        }

        if ($deal->buyer_id !== $rater->id && $deal->seller_id !== $rater->id) {
            return false;
        }

        // Months later nobody remembers the handover well enough for the
        // rating to mean anything, and a stale grudge is not information.
        if ($deal->completed_at?->addDays(self::WINDOW_DAYS)->isPast()) {
            return false;
        }

        return ! Rating::where('deal_id', $deal->id)
            ->where('rater_id', $rater->id)
            ->exists();
    }

    /**
     * Recalculate a user's cached average.
     *
     * Cached on the user rather than averaged on demand because it renders on
     * every listing card on the browse page - an AVG subquery per card is how
     * that page gets slow. Hidden ratings are excluded here, which is the only
     * thing that makes moderation of an unfair rating actually work.
     */
    public function recompute(int $userId): void
    {
        $stats = Rating::where('ratee_id', $userId)
            ->where('is_hidden', false)
            ->selectRaw('count(*) as n, avg(score) as avg')
            ->first();

        $count = (int) ($stats->n ?? 0);

        User::whereKey($userId)->update([
            'rating_count' => $count,
            'rating_avg'   => $count > 0 ? round((float) $stats->avg, 2) : null,
        ]);
    }
}
