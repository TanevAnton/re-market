<?php

namespace App\Services\Moderation;

use App\Enums\ListingStatus;
use App\Enums\ModerationTrigger;
use App\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * The automated checks that run when a listing is published.
 *
 * Neither of these decides anything. They put a listing in front of a person,
 * which is the only defensible design: a perceptual hash match can be a
 * coincidence, and a cheap price can be someone who wants rid of a card. An
 * automated takedown on either signal would remove honest listings and would
 * also be an automated restrictive decision under DSA Art. 17, which is a
 * heavier thing to justify than a queue entry.
 */
class ListingScreener
{
    public function __construct(
        private readonly ModerationService $moderation,
    ) {}

    /** @return list<ModerationTrigger> whatever was flagged, for logging and tests */
    public function screen(Listing $listing): array
    {
        $flagged = [];

        if ($context = $this->duplicatePhoto($listing)) {
            $this->moderation->enqueue($listing, ModerationTrigger::PhashCollision, $context);
            $flagged[] = ModerationTrigger::PhashCollision;
        }

        if ($context = $this->priceOutlier($listing)) {
            $this->moderation->enqueue($listing, ModerationTrigger::PriceOutlier, $context);
            $flagged[] = ModerationTrigger::PriceOutlier;
        }

        // Anything flagged comes off the public site until it has been looked
        // at. Queueing a listing while leaving it visible would make the queue
        // decorative - the damage is done by the time anyone opens it.
        if ($flagged && $listing->status === ListingStatus::Active) {
            $listing->forceFill(['status' => ListingStatus::PendingReview])->save();
        }

        return $flagged;
    }

    /**
     * Has one of these photographs appeared in somebody else's listing?
     *
     * The single most common scam on any hardware marketplace is reposting
     * another seller's pictures, and this is the only check that catches it
     * before a buyer does.
     *
     * The distance is computed in Postgres rather than in PHP: a bigint cast to
     * bit(64), XORed, with the set bits counted. That is a sequential scan over
     * listing_images, which is fine at this size and will need a BK-tree or a
     * bit-sampling index somewhere north of a few hundred thousand images.
     * Written here so the day it matters, the reason is on the page.
     *
     * @return array<string, mixed>|null context for the queue, null if clean
     */
    private function duplicatePhoto(Listing $listing): ?array
    {
        $threshold = (int) config('remarket.antispam.phash_distance', 8);

        // Set bits in the XOR of the two hashes - the Hamming distance. Cast
        // through bit(64) because Postgres has no bit-count for bigint, and a
        // negative hash (top bit set) two's-complements into exactly the bits
        // we want.
        $distance = "length(replace((li.phash::bit(64) # ?::bigint::bit(64))::text, '0', ''))";

        foreach ($listing->images()->whereNotNull('phash')->get() as $image) {
            $match = DB::table('listing_images as li')
                ->join('listings as l', 'l.id', '=', 'li.listing_id')
                ->selectRaw("l.uuid as listing_uuid, l.user_id, {$distance} as distance", [$image->phash])
                ->where('li.listing_id', '!=', $listing->id)
                ->whereNotNull('li.phash')
                ->whereNull('l.deleted_at')
                // whereRaw, not having: HAVING without GROUP BY requires an
                // aggregate in Postgres, and this is a per-row test.
                ->whereRaw("{$distance} <= ?", [$image->phash, $threshold])
                ->orderBy('distance')
                ->first();

            if ($match) {
                return [
                    'matched_listing_uuid' => $match->listing_uuid,
                    // Same photo, same seller is a relisting, not a theft - but
                    // it is still worth a moderator knowing which it was.
                    'same_seller'          => (int) $match->user_id === (int) $listing->user_id,
                    'distance'             => (int) $match->distance,
                ];
            }
        }

        return null;
    }

    /**
     * Is this price wildly off what the same part actually sells for?
     *
     * Catches the classic bait - an RTX 5090 at 300 лв - and, in the other
     * direction, a mistyped price with an extra zero, which is a favour to the
     * seller rather than a suspicion.
     *
     * A median needs a sample to mean anything. Below the minimum the check
     * says nothing rather than guessing, because a new catalogue part with two
     * listings would otherwise flag every third one and train the moderator to
     * approve without looking - which is worse than not checking at all.
     *
     * @return array<string, mixed>|null
     */
    private function priceOutlier(Listing $listing): ?array
    {
        if (! $listing->part_id) {
            return null;
        }

        $peers = Listing::query()
            ->where('part_id', $listing->part_id)
            ->whereKeyNot($listing->id)
            ->whereIn('status', [ListingStatus::Active, ListingStatus::Reserved, ListingStatus::Sold])
            ->where('published_at', '>=', now()->subDays(90));

        $sample = (clone $peers)->count();

        if ($sample < (int) config('remarket.antispam.price_sample_minimum', 5)) {
            return null;
        }

        $median = (int) round((float) $peers
            ->selectRaw('percentile_cont(0.5) within group (order by price_cents) as m')
            ->value('m'));

        if ($median <= 0) {
            return null;
        }

        $percent = (int) round($listing->price_cents / $median * 100);
        $low     = (int) config('remarket.antispam.price_outlier_low', 40);
        $high    = (int) config('remarket.antispam.price_outlier_high', 250);

        if ($percent >= $low && $percent <= $high) {
            return null;
        }

        return [
            'median_cents'       => $median,
            'percent_of_median'  => $percent,
            'sample_size'        => $sample,
            'direction'          => $percent < $low ? 'too_cheap' : 'too_expensive',
        ];
    }
}
