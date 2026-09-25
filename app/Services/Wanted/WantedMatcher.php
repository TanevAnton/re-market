<?php

namespace App\Services\Wanted;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\WantedAd;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * „Who should hear about this?" — asked from both directions, answered once.
 *
 * THIS CLASS IS THE FEATURE. A wanted ad nobody sees is a diary entry; what
 * makes it worth building is that a seller with the right card in a drawer
 * finds out. Two directions, and they are not the same event:
 *
 *   NEW WANTED AD → the sellers of listings that already match. „Somebody is
 *   looking for exactly what you have listed" is the strongest possible reason
 *   to open the site.
 *
 *   NEW LISTING → the buyers whose open requests it answers. This is the one
 *   that closes the loop: the buyer who found nothing in March is told in May.
 *
 * ONE SET OF RULES FOR BOTH, in `matches()`, because two copies drift and the
 * drift is invisible — a buyer told about a listing that a seller is never
 * told about looks to everyone like nothing happened at all.
 *
 * THE RULES ARE DELIBERATELY NARROW. A notification nobody wanted is worse
 * than no notification: it is the thing that makes people turn off email and
 * then miss the offer on their own listing. So a match needs the category,
 * the model when one was named, the budget when one was set, and the city when
 * one was chosen. „Близко" does not qualify.
 */
class WantedMatcher
{
    /** How many people one event may reach. See notify(). */
    private const FANOUT = 25;

    /**
     * Listings that answer this wanted ad, newest first.
     *
     * @return Collection<int, Listing>
     */
    public function listingsFor(WantedAd $ad, int $limit = self::FANOUT): Collection
    {
        return Listing::query()
            ->where('status', ListingStatus::Active)
            // A buyer does not want to be shown their own stock.
            ->where('user_id', '!=', $ad->user_id)
            ->where('category', $ad->category)
            ->when($ad->part_id, fn (Builder $q) => $q->where('part_id', $ad->part_id))
            ->when($ad->budget_max_cents, fn (Builder $q) => $q->where('price_cents', '<=', $ad->budget_max_cents))
            ->when($ad->city_id, fn (Builder $q) => $q->where('city_id', $ad->city_id))
            ->with(['images', 'city', 'part', 'user'])
            ->latest('bumped_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Open wanted ads that this listing answers.
     *
     * The mirror of the query above, field for field. When one changes, both
     * change — which is the reason they sit next to each other in one file.
     *
     * @return Collection<int, WantedAd>
     */
    public function wantedFor(Listing $listing, int $limit = self::FANOUT): Collection
    {
        if ($listing->status !== ListingStatus::Active) {
            return collect();
        }

        return WantedAd::query()
            ->visible()
            ->where('user_id', '!=', $listing->user_id)
            ->where('category', $listing->category)
            ->where(fn (Builder $q) => $q->whereNull('part_id')->orWhere('part_id', $listing->part_id))
            ->where(fn (Builder $q) => $q->whereNull('budget_max_cents')
                ->orWhere('budget_max_cents', '>=', $listing->price_cents))
            ->where(fn (Builder $q) => $q->whereNull('city_id')->orWhere('city_id', $listing->city_id))
            ->with('user')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Does this one listing answer this one wanted ad?
     *
     * The same rules again, in memory — used to refuse a response that does
     * not actually match, so „Имам такова" cannot become a way to put an
     * unrelated listing in front of somebody. The two queries above are how
     * this question is asked in bulk; this is how it is asked about a pair.
     */
    public function matches(WantedAd $ad, Listing $listing): bool
    {
        if ($listing->status !== ListingStatus::Active) {
            return false;
        }

        if ($listing->category !== $ad->category) {
            return false;
        }

        if ($ad->part_id && $listing->part_id !== $ad->part_id) {
            return false;
        }

        if ($ad->city_id && $listing->city_id !== $ad->city_id) {
            return false;
        }

        /*
         * The budget is a ceiling, and it is NOT enforced here.
         *
         * A seller with a card at 320 € answering „до 300 €" is making an
         * argument — better condition, warranty left, a bundle — and that is a
         * conversation worth having. What the buyer must not get is an
         * unrelated item, which is what the checks above are for. The screen
         * shows the price next to the budget and lets the buyer decide; a hard
         * refusal here would only teach sellers to ignore the field.
         */
        return true;
    }
}
