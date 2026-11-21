<?php

namespace App\Support;

use App\Models\User;

/**
 * What a listing card can honestly say about its seller, in a few words.
 *
 * Two things, both read straight off columns the user row already carries, so
 * a card that has its seller loaded pays nothing for them:
 *
 *   „Проверена фирма" - a person at this site checked the company against the
 *   Commercial Register. NOT the same as having declared oneself a trader,
 *   which anybody can do; see User::isVerifiedTrader().
 *
 *   „★ 4.9 · 12 сделки" - a record, from completed deals.
 *
 * ONE-SIDED, as everywhere else on this site (the deal badge, the reply-speed
 * line): shown when it is good, absent when it is not. A grid where some cards
 * say „2 сделки, 60% приключени" is a grid that tells people not to buy from
 * half its sellers, and a new seller with nothing to show has done nothing
 * wrong. The absence of a signal is not a claim about anybody.
 *
 * AND WITHHELD UNTIL IT MEANS SOMETHING. Three completed deals is a thin
 * record, but one is an anecdote and two is a coincidence, and a rating of 5.0
 * from a single deal is exactly the number a seller would create for
 * themselves. The completion rate is the guard against the opposite trick: a
 * seller who agrees to many deals and walks away from half of them can have a
 * perfect rating from the other half.
 *
 * NO QUERIES. The reply-speed figure is deliberately not here: it is an
 * aggregate over the messages table, and one per card is the N+1 that
 * BoostEagerLoadTest exists to catch. It stays on the listing page.
 */
class SellerSignals
{
    /** Completed deals before a record is shown at all. */
    public const MIN_DEALS = 3;

    /** Of the deals a seller agrees to, the share they must complete. */
    public const MIN_COMPLETION_PERCENT = 90.0;

    /** Below this the stars are not worth printing. */
    public const MIN_RATING = 4.5;

    /**
     * @return list<array{kind: string, label: string, title: string}>
     */
    public static function for(User $seller): array
    {
        $signals = [];

        if ($seller->isVerifiedTrader()) {
            $signals[] = [
                'kind'  => 'verified',
                'label' => 'Проверена фирма',
                'title' => 'Фирмата е проверена от нас в Търговския регистър.',
            ];
        }

        $record = self::record($seller);

        if ($record !== null) {
            $signals[] = $record;
        }

        return $signals;
    }

    /** @return array{kind: string, label: string, title: string}|null */
    private static function record(User $seller): ?array
    {
        $completed = (int) $seller->deals_completed;

        if ($completed < self::MIN_DEALS) {
            return null;
        }

        $rate = $seller->completionRate();

        if ($rate === null || $rate < self::MIN_COMPLETION_PERCENT) {
            return null;
        }

        $label  = "{$completed} сделки";
        $rating = $seller->rating_avg !== null ? (float) $seller->rating_avg : 0.0;

        if ($rating >= self::MIN_RATING) {
            $label = '★ '.number_format($rating, 1, '.', '').' · '.$label;
        }

        return [
            'kind'  => 'record',
            'label' => $label,
            'title' => "Приключени сделки: {$completed}. "
                .'Приключва '.rtrim(rtrim(number_format($rate, 1, '.', ''), '0'), '.').'% от договорените.',
        ];
    }
}
