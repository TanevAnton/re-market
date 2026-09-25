<?php

namespace App\Services\Wanted;

use App\Enums\ModerationTrigger;
use App\Enums\WantedStatus;
use App\Models\Listing;
use App\Models\User;
use App\Models\WantedAd;
use App\Models\WantedResponse;
use App\Notifications\WantedAnswered;
use App\Notifications\WantedMatchFound;
use App\Services\Moderation\ModerationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Wanted ads: posting them, answering them, and closing them.
 *
 * THE REFUSALS ARE THE FEATURE, same as everywhere else here. „Търся" is the
 * easiest surface on a marketplace to abuse — it is free to post, it reaches
 * sellers directly, and on every Bulgarian classifieds site it has turned into
 * „купувам всичко, пишете на Вайбър". Every guard below exists to stop that
 * specific outcome:
 *
 *   - a new account's first requests go through moderation, exactly like their
 *     first listings;
 *   - a cap on how many one person may have open;
 *   - an answer must be a REAL LISTING that already passed moderation, owned by
 *     the person answering, and actually matching what was asked for;
 *   - one listing may answer one request once, enforced by a unique index.
 */
class WantedService
{
    /** How many open requests one buyer may have. */
    private const MAX_OPEN = 5;

    public function __construct(
        private WantedMatcher $matcher,
        private ModerationService $moderation,
    ) {}

    /**
     * Post a request.
     *
     * @param  array{category: string, part_id: ?int, title: string, detail: ?string, budget_max_cents: ?int, city_id: ?int}  $data
     */
    public function create(User $buyer, array $data): WantedAd
    {
        $open = WantedAd::where('user_id', $buyer->id)->open()->count();

        if ($open >= self::MAX_OPEN) {
            throw new RuntimeException(
                'Имаш '.$open.' отворени търсения. Затвори някое, преди да добавиш ново.'
            );
        }

        /*
         * THE SAME RULE AS A FIRST LISTING, and it has to be. A wanted ad is
         * user-written text that reaches sellers directly, so an account that
         * is not trusted enough to publish a listing unreviewed is not trusted
         * enough to broadcast a request either — and the spammer who finds
         * that gap will use it, because it is cheaper than making listings.
         */
        $held = $this->moderation->holdsNewSeller($buyer);

        $ad = DB::transaction(function () use ($buyer, $data, $held) {
            $ad = new WantedAd();

            $ad->fill([
                'category'         => $data['category'],
                'part_id'          => $data['part_id'] ?? null,
                'title'            => mb_substr(trim($data['title']), 0, 160),
                'detail'           => ($d = trim((string) ($data['detail'] ?? ''))) === '' ? null : mb_substr($d, 0, 2000),
                'budget_max_cents' => $data['budget_max_cents'] ?? null,
                'city_id'          => $data['city_id'] ?? null,
            ]);

            $ad->forceFill([
                'user_id'    => $buyer->id,
                'status'     => $held ? WantedStatus::PendingReview : WantedStatus::Active,
                'expires_at' => now()->addDays((int) config('remarket.wanted.expire_after_days', 30)),
            ])->save();

            return $ad;
        });

        if ($held) {
            $this->moderation->enqueue($ad, ModerationTrigger::NewAccount, [
                'reason' => 'първи търсения от нов профил',
            ]);

            return $ad;
        }

        $this->announce($ad);

        return $ad;
    }

    /**
     * Tell the sellers whose listings already answer this.
     *
     * ONCE PER AD, stamped on `matched_at`. Without that stamp, a buyer
     * editing a typo in their request re-notifies every matching seller — and
     * two of those turn a useful feature into a mailing list people mute. Also
     * called from approve() when a held ad is let through, which is why it is
     * public.
     */
    public function announce(WantedAd $ad): int
    {
        if ($ad->matched_at || ! $ad->status->isPubliclyVisible()) {
            return 0;
        }

        $listings = $this->matcher->listingsFor($ad);

        // Stamped BEFORE the sends, so a failure halfway through cannot lead to
        // a retry that notifies the first half twice.
        $ad->forceFill(['matched_at' => now()])->save();

        $sellers = $listings->groupBy('user_id');

        foreach ($sellers as $ownListings) {
            $ownListings->first()->user->notify(
                new WantedMatchFound($ad, $ownListings->first())
            );
        }

        return $sellers->count();
    }

    /**
     * A new listing arrived — tell the buyers whose requests it answers.
     *
     * Called from ListingService when a listing becomes active, including out
     * of the moderation queue. The buyer who found nothing in March hears
     * about it in May, which is the whole loop this feature exists to close.
     */
    public function announceListing(Listing $listing): int
    {
        $ads = $this->matcher->wantedFor($listing);

        foreach ($ads as $ad) {
            $ad->user->notify(new WantedMatchFound($ad, $listing, forBuyer: true));
        }

        return $ads->count();
    }

    /**
     * „Имам такова." A seller points at one of their own listings.
     */
    public function respond(WantedAd $ad, Listing $listing, User $seller): WantedResponse
    {
        abort_unless($listing->user_id === $seller->id, 404);

        if ($ad->user_id === $seller->id) {
            throw new RuntimeException('Това е твоето търсене.');
        }

        if (! $ad->status->isPubliclyVisible()) {
            throw new RuntimeException('Търсенето вече не е активно.');
        }

        if (! $this->matcher->matches($ad, $listing)) {
            throw new RuntimeException(
                'Обявата не отговаря на търсенето — провери категорията, модела и града.'
            );
        }

        try {
            $response = DB::transaction(function () use ($ad, $listing, $seller) {
                $response = new WantedResponse();

                $response->forceFill([
                    'wanted_ad_id' => $ad->id,
                    'listing_id'   => $listing->id,
                    'seller_id'    => $seller->id,
                    'status'       => WantedResponse::PENDING,
                ])->save();

                return $response;
            });
        } catch (UniqueConstraintViolationException) {
            // The index did its job: one listing answers one request once.
            throw new RuntimeException('Вече си предложил тази обява по това търсене.');
        }

        $ad->user->notify(new WantedAnswered($ad, $listing));

        return $response;
    }

    /** The buyer clears a card they are not interested in. Nobody is told. */
    public function dismiss(WantedResponse $response, User $buyer): WantedResponse
    {
        abort_unless($response->wantedAd->user_id === $buyer->id, 404);

        $response->forceFill([
            'status'       => WantedResponse::DISMISSED,
            'dismissed_at' => now(),
        ])->save();

        return $response;
    }

    /**
     * „Намерих." Closes the request.
     *
     * Its own status rather than a delete, because „how many requests ever got
     * answered" is the only honest measure of whether this feature works —
     * and a deleted row cannot answer it.
     */
    public function fulfil(WantedAd $ad, User $buyer): WantedAd
    {
        abort_unless($ad->user_id === $buyer->id, 404);

        $ad->forceFill([
            'status'       => WantedStatus::Fulfilled,
            'fulfilled_at' => now(),
        ])->save();

        return $ad;
    }

    public function reopen(WantedAd $ad, User $buyer): WantedAd
    {
        abort_unless($ad->user_id === $buyer->id, 404);

        if (! in_array($ad->status, [WantedStatus::Fulfilled, WantedStatus::Expired], true)) {
            throw new RuntimeException('Това търсене е активно.');
        }

        $ad->forceFill([
            'status'       => WantedStatus::Active,
            'fulfilled_at' => null,
            'expires_at'   => now()->addDays((int) config('remarket.wanted.expire_after_days', 30)),
        ])->save();

        return $ad;
    }

    /**
     * The sweeper. Closes what nobody answered.
     *
     * `visible()` already hides an expired ad, so this is housekeeping rather
     * than correctness — but a status column that disagrees with what the site
     * shows is how the next person writes a query against the wrong one.
     */
    public function expire(): int
    {
        return WantedAd::query()
            ->where('status', WantedStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => WantedStatus::Expired, 'updated_at' => now()]);
    }
}
