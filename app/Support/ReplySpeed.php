<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * „Обикновено отговаря до 2 часа."
 *
 * The one thing a buyer wants to know before typing a message and waiting, and
 * the site already had every timestamp needed to answer it: a thread records
 * who the seller is, and every message records who sent it and when. Nothing
 * new is stored — this reads what messaging has been writing all along.
 *
 * THE MEASURE. Median time from the buyer's first message in a thread to the
 * seller's first reply in that same thread, over the last 90 days. Median and
 * not mean, for the same reason the price band is `percentile_cont`: one seller
 * who came back from holiday after three weeks should not decide the number
 * everybody else sees.
 *
 * THE TRAP THIS CLOSES, which is the whole reason it is not a one-line query.
 * Response time can only be measured on threads that GOT a response, so a
 * seller who answers one message in five and answers it fast scores better than
 * one who answers all five within a day. That is exactly backwards, and it is
 * the shape of statistic that gets shipped because the query looks right. So
 * the answered RATE is computed alongside and the badge is withheld below
 * `min_answer_rate` — the number is about a seller you can reach, not about the
 * lucky few who were.
 *
 * ONE-SIDED, like the deal badge. Shown when it is good, absent when it is not.
 * „Отговаря до 6 дни" reads as a punishment for a seller who has a job, and
 * this site does not have the supply to spend on that; the absence of a badge
 * is not a claim about anybody. The same reasoning is already written down for
 * `Listing::priceAdvantage()`: cheap yes, expensive never.
 */
class ReplySpeed
{
    /**
     * @return array{seconds: int, answered: int, threads: int}|null
     *     null when there is not enough to say, or not enough that is good.
     */
    public static function forSeller(User $seller): ?array
    {
        /*
         * Cached per seller, and it holds THREE INTEGERS. Nothing here is an
         * Eloquent model — a cache entry containing one is a page that works
         * exactly once, which this codebase has already paid for.
         *
         * Six hours: a reply time is a habit, not a state, and recomputing it
         * on every listing view would put two aggregate queries in front of
         * every buyer.
         */
        return Cache::remember(
            "reply-speed:{$seller->id}",
            now()->addHours(6),
            fn () => self::measure($seller),
        );
    }

    /** @return array{seconds: int, answered: int, threads: int}|null */
    private static function measure(User $seller): ?array
    {
        $window = (int) config('remarket.replies.window_days', 90);

        /*
         * One row per thread: when the buyer first wrote, and when the seller
         * first wrote back.
         *
         * `sender_id <> seller_id` rather than `= buyer_id` because a thread's
         * buyer is on the thread, not on the message, and going through the
         * join for it would cost a column for no gain — on a two-party thread
         * „not the seller" IS the buyer.
         */
        $rows = DB::table('threads')
            ->join('messages', 'messages.thread_id', '=', 'threads.id')
            ->where('threads.seller_id', $seller->id)
            ->where('threads.created_at', '>=', now()->subDays($window))
            ->groupBy('threads.id')
            ->selectRaw('
                MIN(CASE WHEN messages.sender_id <> threads.seller_id THEN messages.created_at END) AS asked,
                MIN(CASE WHEN messages.sender_id  = threads.seller_id THEN messages.created_at END) AS answered
            ')
            ->get();

        $threads = $rows->count();

        if ($threads < (int) config('remarket.replies.min_threads', 3)) {
            return null;
        }

        $gaps = $rows
            ->filter(fn ($r) => $r->asked !== null && $r->answered !== null)
            // A seller's own listing bump or a message they sent first cannot
            // be a reply to something that had not been asked yet.
            ->map(fn ($r) => strtotime($r->answered) - strtotime($r->asked))
            ->filter(fn (int $gap) => $gap > 0)
            ->values()
            ->sort()
            ->values();

        $answered = $gaps->count();

        if ($answered < (int) config('remarket.replies.min_threads', 3)) {
            return null;
        }

        // The survivorship guard. See the class comment.
        if ($answered / $threads < (float) config('remarket.replies.min_answer_rate', 0.6)) {
            return null;
        }

        $median = self::median($gaps->all());

        // One-sided: a slow number is simply not shown.
        if ($median > (int) config('remarket.replies.max_hours', 24) * 3600) {
            return null;
        }

        return ['seconds' => $median, 'answered' => $answered, 'threads' => $threads];
    }

    /** @param  list<int>  $sorted */
    private static function median(array $sorted): int
    {
        $n = count($sorted);
        $i = intdiv($n, 2);

        return $n % 2 === 1
            ? $sorted[$i]
            : (int) round(($sorted[$i - 1] + $sorted[$i]) / 2);
    }

    /**
     * „до 2 часа", „до 1 ден" — rounded UP and deliberately coarse.
     *
     * „до 47 минути" is a promise nobody made and invites a buyer to time it.
     * Rounding up also means the number is one a seller can keep: the claim is
     * an upper bound, not an average dressed as one.
     */
    public static function label(int $seconds): string
    {
        if ($seconds <= 900) {
            return 'до 15 минути';
        }

        if ($seconds <= 3600) {
            return 'до час';
        }

        if ($seconds < 86400) {
            $hours = (int) ceil($seconds / 3600);

            return "до {$hours} ".($hours === 1 ? 'час' : 'часа');
        }

        $days = (int) ceil($seconds / 86400);

        return "до {$days} ".($days === 1 ? 'ден' : 'дни');
    }
}
