<?php

namespace App\Support;

use App\Enums\BoostTier;
use App\Models\Boost;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Collection;

/**
 * „Is this listing paid for, and which way?" — asked once per card.
 *
 * WHY THIS IS A CLASS AND NOT A METHOD ON THE MODEL. A card asks twice (is it
 * highlighted, does it wear the badge), a browse page draws twenty-four of
 * them, and every one of those questions is a query if it goes through
 * `$listing->boosts()`. That is fifty round trips for a page that should make
 * one. Everything here reads the LOADED relation — `$listing->boosts`, no
 * parentheses — so a page that eager-loads once pays once.
 *
 * `Boosted::eagerLoad()` is the other half. Forget it and this class answers
 * „no boost" for everything, which looks exactly like a site nobody has paid
 * for. `BoostedBrowseTest` asserts the query count for that reason.
 */
class Boosted
{
    /** What browse and any other grid must add to its `with()`. */
    public static function eagerLoad(): array
    {
        return ['boosts' => fn ($q) => $q->running()];
    }

    /** The running boost of a tier, or null. Reads the loaded collection. */
    public static function of(Listing $listing, BoostTier $tier): ?Boost
    {
        if (! $listing->relationLoaded('boosts')) {
            // Falling back to a query would hide a missing eagerLoad() behind
            // a performance cliff nobody notices until the site is busy.
            $listing->load(self::eagerLoad());
        }

        /** @var Collection<int, Boost> $rows */
        $rows = $listing->boosts;

        return $rows->first(fn (Boost $b) => $b->tier === $tier && $b->isRunning());
    }

    public static function isHighlighted(Listing $listing): bool
    {
        return self::of($listing, BoostTier::Highlight) !== null;
    }

    public static function isPinned(Listing $listing): bool
    {
        return self::of($listing, BoostTier::Pin) !== null;
    }

    /**
     * Does this listing have to carry the „Промотирана" label?
     *
     * TRUE FOR ANY RUNNING BOOST, not only the ones that move it. The Omnibus
     * Directive is about a consumer being able to tell that money changed
     * hands; a highlighted card is paid placement in the plain sense of the
     * phrase even though its position is untouched, and arguing the
     * distinction to a regulator is not worth the two pixels it saves.
     *
     * A bump is excluded because it is never running — see BoostTier. Its
     * effect ended when it was bought, and a badge on a week-old bump would
     * mislead in the direction the rule exists to prevent.
     */
    public static function isLabelled(Listing $listing): bool
    {
        return self::isHighlighted($listing) || self::isPinned($listing);
    }
}
