<?php

namespace App\Services\Listings;

use App\Enums\BundleStatus;
use App\Enums\ListingStatus;
use App\Enums\ModerationTrigger;
use App\Models\Bundle;
use App\Models\Listing;
use App\Models\User;
use App\Services\Moderation\ModerationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creating, changing and publishing a bundle.
 *
 * A service rather than logic in the component, for the reason the offer floor
 * is a service: the rules below are the ones a caller must not be able to route
 * around, and there are already two screens that would each need them.
 *
 * WHAT IT REFUSES, and why each one is a real door rather than defensive
 * programming:
 *
 *  - Somebody else's listing. A bundle of other people's hardware is a listing
 *    for goods you do not have, which is the oldest fraud on any marketplace.
 *  - A listing that is already in another bundle. `listings.bundle_id` is a
 *    single column, so the second bundle would silently steal it from the
 *    first and leave that one advertising a part it no longer contains.
 *  - A listing that is not for sale. Sold, reserved, expired or removed: a
 *    bundle assembled out of those has a price nobody can pay.
 *  - Fewer than two members. One listing in a group is not a group; it is the
 *    listing, with an extra page competing against it for the same search.
 */
class BundleService
{
    public const MIN_MEMBERS = 2;

    public function __construct(private ModerationService $moderation) {}

    /**
     * @param  list<int>  $listingIds
     */
    public function create(User $seller, array $data, array $listingIds): Bundle
    {
        return DB::transaction(function () use ($seller, $data, $listingIds) {
            /*
             * make() + forceFill + save, rather than create().
             *
             * `status` is not fillable, on purpose: this class is fed seller
             * input, and nobody should be able to post a pre-approved bundle.
             * But the column's default lives in the database, so a model
             * straight out of create() has `status` UNSET in memory — null,
             * not Draft — and every caller that then asks „is this a draft?"
             * gets false. That is a silent bug, not a loud one: the bundle is
             * created and simply never published.
             */
            $bundle = Bundle::make([
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'price_cents' => $data['price_cents'] ?? null,
            ]);

            $bundle->forceFill([
                'user_id' => $seller->id,
                'status'  => BundleStatus::Draft,
            ])->save();

            $this->setMembers($bundle, $listingIds, $seller);

            return $bundle;
        });
    }

    public function update(Bundle $bundle, array $data, array $listingIds, User $actor): Bundle
    {
        $this->authorise($bundle, $actor);

        return DB::transaction(function () use ($bundle, $data, $listingIds, $actor) {
            $bundle->update([
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'price_cents' => $data['price_cents'] ?? null,
            ]);

            $this->setMembers($bundle, $listingIds, $actor);

            return $bundle->fresh();
        });
    }

    /**
     * Replace the membership wholesale.
     *
     * Detach-then-attach rather than a diff: the set is small, the diff is
     * where an off-by-one leaves a listing pointing at a bundle it is no longer
     * in, and that listing then shows „част от комплект" linking to a page it
     * does not appear on.
     *
     * @param  list<int>  $listingIds
     */
    private function setMembers(Bundle $bundle, array $listingIds, User $actor): void
    {
        $ids = array_values(array_unique(array_map('intval', $listingIds)));

        if (count($ids) < self::MIN_MEMBERS) {
            throw new RuntimeException(
                'Комплектът трябва да съдържа поне '.self::MIN_MEMBERS.' обяви.'
            );
        }

        $listings = Listing::whereIn('id', $ids)->get();

        if ($listings->count() !== count($ids)) {
            throw new RuntimeException('Част от избраните обяви вече не съществуват.');
        }

        foreach ($listings as $listing) {
            if ($listing->user_id !== $actor->id) {
                throw new RuntimeException('Можеш да групираш само свои обяви.');
            }

            if ($listing->status !== ListingStatus::Active) {
                throw new RuntimeException(
                    '„'.$listing->title.'“ не е активна и не може да влезе в комплект.'
                );
            }

            if ($listing->bundle_id !== null && $listing->bundle_id !== $bundle->id) {
                throw new RuntimeException(
                    '„'.$listing->title.'“ вече участва в друг комплект.'
                );
            }
        }

        // Free anything that was in this bundle and is not in the new set...
        Listing::where('bundle_id', $bundle->id)
            ->whereNotIn('id', $ids)
            ->update(['bundle_id' => null]);

        // ...then claim the new set.
        Listing::whereIn('id', $ids)->update(['bundle_id' => $bundle->id]);
    }

    /**
     * Publish, through the same moderation gate a listing goes through.
     *
     * The members have already been moderated individually, so the queue item
     * is about the TITLE and the PRICE CLAIM only — „цялата машина за 900 €" is
     * seller-written text making an offer, and that is the part nobody has
     * looked at yet.
     */
    public function publish(Bundle $bundle, User $actor): Bundle
    {
        $this->authorise($bundle, $actor);

        if ($bundle->listings()->count() < self::MIN_MEMBERS) {
            throw new RuntimeException('Комплектът е празен.');
        }

        $needsReview = $this->needsReview($actor);

        return DB::transaction(function () use ($bundle, $needsReview) {
            // forceFill for the same reason listings use it: `status` and
            // `published_at` are not fillable, so nothing taking seller input
            // can post a pre-approved bundle.
            $bundle->forceFill([
                'status'       => $needsReview ? BundleStatus::PendingReview : BundleStatus::Active,
                'published_at' => now(),
            ])->save();

            /*
             * Held and queued in one transaction — the same rule as publishing
             * a listing. A PendingReview row with no queue entry is invisible
             * to the public AND to every moderator, and the seller waits for a
             * review nobody scheduled.
             */
            if ($needsReview) {
                $this->moderation->enqueue($bundle, ModerationTrigger::NewAccount, [
                    'members'     => $bundle->listings()->count(),
                    'price_cents' => $bundle->price_cents,
                ]);
            }

            return $bundle;
        });
    }

    /**
     * Is this bundle held for review?
     *
     * THE LISTING RULE ALONE DOES NOT WORK HERE, and the test suite is what
     * said so. `holdsNewSeller()` holds a seller until they have two listings;
     * a bundle needs two listings to exist. So every seller capable of making
     * a bundle is, by that rule, already trusted — the gate the features doc
     * asked for would have been dead code that never once fired.
     *
     * So: the FIRST bundle is held, which is the same idea one level up. The
     * members were each moderated on their own way in; what nobody has read is
     * this seller's bundle title and the price claim on it — „цялата машина за
     * 900 €" is an offer, written by hand, and the first one from any given
     * seller is the one worth a person's eyes.
     */
    private function needsReview(User $seller): bool
    {
        if ($this->moderation->holdsNewSeller($seller)) {
            return true;
        }

        // The bundle being published is still Draft at this point, so it does
        // not count itself out of its own first-bundle check.
        return $seller->bundles()
            ->where('status', BundleStatus::Active)
            ->doesntExist();
    }

    /**
     * Break the group up. The listings stay exactly as they were.
     *
     * Deleting is the common case for a bundle that did not work, and it must
     * never be a way to lose eight saleable listings — which is also why the
     * foreign key is nullOnDelete rather than cascade.
     */
    public function dissolve(Bundle $bundle, User $actor): void
    {
        $this->authorise($bundle, $actor);

        DB::transaction(function () use ($bundle) {
            Listing::where('bundle_id', $bundle->id)->update(['bundle_id' => null]);

            $bundle->delete();
        });
    }

    /**
     * 404 rather than 403: whether somebody else's bundle exists is not public.
     *
     * No admin exemption. A moderator's way to act on a bundle is to reject it
     * in the queue, which records who decided, on what grounds, and what the
     * seller was told — DSA Art. 17. A second, quieter door that changes
     * somebody's content with none of that record is exactly what this service
     * exists to prevent.
     */
    private function authorise(Bundle $bundle, User $actor): void
    {
        abort_unless($bundle->user_id === $actor->id, 404);
    }
}
