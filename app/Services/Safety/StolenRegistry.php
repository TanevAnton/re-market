<?php

namespace App\Services\Safety;

use App\Enums\ListingStatus;
use App\Enums\ModerationTrigger;
use App\Models\ItemIdentifier as IdentifierRow;
use App\Models\Listing;
use App\Models\StolenReport;
use App\Models\User;
use App\Services\Moderation\ModerationService;
use App\Support\ItemIdentifier;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The serial register: recording identifiers, taking claims, and what a
 * confirmed claim does.
 *
 * WHAT THIS DOES NOT DO, said first because it is the part most easily
 * misunderstood by whoever reads this next. It does not determine that anything
 * was stolen, it does not check a police reference against anything, and it
 * does not accuse a seller. It records that a person filed a report and gave a
 * number; on confirmation it puts every listing carrying that serial in front
 * of a moderator, who decides — with a statement of reasons the seller can
 * dispute. Every string this class produces is worded for that, and a future
 * change that turns a claim into a public accusation is a legal problem rather
 * than a product decision.
 *
 * THE TWO SIGNALS. A confirmed claim is one. The other is free and nearly as
 * useful: the SAME SERIAL ON TWO LIVE LISTINGS. One of them is wrong — a
 * relist that should have been an edit, or one seller using another's photos
 * and details — and either way a person should look.
 */
class StolenRegistry
{
    public function __construct(private ModerationService $moderation) {}

    /**
     * Record a listing's serial, and screen it.
     *
     * @return list<ModerationTrigger> whatever the screen flagged, for tests
     * @throws RuntimeException on an invalid value or a missing pepper
     */
    public function attach(Listing $listing, string $kind, string $value): array
    {
        if (! array_key_exists($kind, ItemIdentifier::kinds())) {
            throw new RuntimeException('Непознат вид идентификатор.');
        }

        if ($problem = ItemIdentifier::problem($kind, $value)) {
            throw new RuntimeException($problem);
        }

        $hash = ItemIdentifier::hash($kind, $value);

        /*
         * ON CONFLICT rather than create(): editing a listing and retyping the
         * same serial must not fail, and a seller correcting a typo must
         * replace the old hash rather than add a second row. The unique index
         * is on (listing_id, kind) — see the migration.
         */
        IdentifierRow::query()->upsert([[
            'listing_id' => $listing->id,
            'kind'       => $kind,
            'hash'       => $hash,
            'last4'      => ItemIdentifier::last4($kind, $value),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['listing_id', 'kind'], ['hash', 'last4', 'updated_at']);

        return $this->screen($listing, $kind, $hash);
    }

    public function detach(Listing $listing, string $kind): void
    {
        IdentifierRow::where('listing_id', $listing->id)->where('kind', $kind)->delete();
    }

    /**
     * What the register knows about a value somebody typed.
     *
     * @return array{known: bool, reported: bool, listings: int, last4: string}
     */
    public function check(string $kind, string $value): array
    {
        $hash = ItemIdentifier::hash($kind, $value);

        return [
            // „Is this serial on the site at all?" — a buyer holding a serial
            // that matches the listing they are looking at learns the seller
            // did record it, which is worth something on its own.
            'known'    => IdentifierRow::matching($kind, $hash)->exists(),
            'reported' => StolenReport::confirmed()->where('kind', $kind)->where('hash', $hash)->exists(),
            'listings' => $this->liveListings($kind, $hash)->count(),
            'last4'    => ItemIdentifier::last4($kind, $value),
        ];
    }

    /**
     * File a claim. Nothing happens to any listing yet.
     *
     * @throws RuntimeException on an invalid value
     */
    public function report(
        string $kind,
        string $value,
        string $policeRef,
        string $detail,
        string $reporterEmail,
        ?User $reporter = null,
    ): StolenReport {
        if ($problem = ItemIdentifier::problem($kind, $value)) {
            throw new RuntimeException($problem);
        }

        $hash = ItemIdentifier::hash($kind, $value);

        /*
         * One open claim per serial per reporter. Somebody anxiously filing the
         * same report four times must not become four items in a queue that a
         * different theft is waiting in — the same rule the DSA notice form
         * follows, for the same reason.
         */
        $existing = StolenReport::pending()
            ->where('kind', $kind)
            ->where('hash', $hash)
            ->where('reporter_email', mb_strtolower(trim($reporterEmail)))
            ->first();

        if ($existing) {
            return $existing;
        }

        $report = new StolenReport();

        $report->forceFill([
            'kind'           => $kind,
            'hash'           => $hash,
            'last4'          => ItemIdentifier::last4($kind, $value),
            'reporter_id'    => $reporter?->id,
            'reporter_email' => mb_strtolower(trim($reporterEmail)),
            'police_ref'     => mb_substr(trim($policeRef), 0, 120),
            'detail'         => mb_substr(trim($detail), 0, 2000),
            'status'         => StolenReport::PENDING,
        ])->save();

        return $report;
    }

    /**
     * A human accepted the claim.
     *
     * Confirming does NOT remove anything. It puts every live listing carrying
     * that serial into the moderation queue, where a person decides and the
     * seller gets a statement of reasons they can dispute. Confirming a claim
     * and removing somebody's listing are two decisions, and collapsing them
     * would mean an unverifiable claim taking a named seller's ad down with
     * nobody accountable for it.
     *
     * @return int how many listings were queued
     */
    public function confirm(StolenReport $report, User $moderator, string $note = ''): int
    {
        if ($report->status !== StolenReport::PENDING) {
            throw new RuntimeException('Този сигнал вече е решен.');
        }

        return DB::transaction(function () use ($report, $moderator, $note) {
            $report->forceFill([
                'status'        => StolenReport::CONFIRMED,
                'decided_by'    => $moderator->id,
                'decided_at'    => now(),
                'decision_note' => $note === '' ? null : mb_substr($note, 0, 500),
            ])->save();

            return $this->flagMatching($report->kind, $report->hash, $report->police_ref);
        });
    }

    public function reject(StolenReport $report, User $moderator, string $note): StolenReport
    {
        if ($report->status !== StolenReport::PENDING) {
            throw new RuntimeException('Този сигнал вече е решен.');
        }

        if (mb_strlen(trim($note)) < 5) {
            // A rejected claim about somebody's property needs a recorded
            // reason, for the same reason a rejected listing does.
            throw new RuntimeException('Напиши защо сигналът се отхвърля.');
        }

        $report->forceFill([
            'status'        => StolenReport::REJECTED,
            'decided_by'    => $moderator->id,
            'decided_at'    => now(),
            'decision_note' => mb_substr(trim($note), 0, 500),
        ])->save();

        return $report;
    }

    /**
     * The screen run when a serial is recorded.
     *
     * Two triggers, and neither decides anything — both put the listing in
     * front of a person, like every other automated check here.
     *
     * @return list<ModerationTrigger>
     */
    private function screen(Listing $listing, string $kind, string $hash): array
    {
        $flagged = [];

        if ($report = StolenReport::confirmed()->where('kind', $kind)->where('hash', $hash)->first()) {
            $this->moderation->enqueue($listing, ModerationTrigger::StolenClaim, [
                'kind'       => $kind,
                'police_ref' => $report->police_ref,
                'report'     => $report->uuid,
            ]);

            $flagged[] = ModerationTrigger::StolenClaim;
        }

        /*
         * The same serial on another live listing. Free, and one of the
         * strongest signals available: a physical object is in one place, so
         * two live listings for it means one of them is wrong.
         */
        $others = $this->liveListings($kind, $hash)->where('listings.id', '!=', $listing->id)->count();

        if ($others > 0) {
            $this->moderation->enqueue($listing, ModerationTrigger::DuplicateSerial, [
                'kind'         => $kind,
                'other_listings' => $others,
            ]);

            $flagged[] = ModerationTrigger::DuplicateSerial;
        }

        /*
         * Anything flagged comes off the public site until somebody has looked,
         * exactly as ListingScreener does it: a queued listing left visible
         * makes the queue decorative, because the damage is done before anyone
         * opens it.
         */
        if ($flagged && $listing->status === ListingStatus::Active) {
            $listing->forceFill(['status' => ListingStatus::PendingReview])->save();
        }

        return $flagged;
    }

    /** Every publicly visible listing carrying this hash. */
    private function liveListings(string $kind, string $hash)
    {
        return Listing::query()
            ->join('item_identifiers', 'item_identifiers.listing_id', '=', 'listings.id')
            ->where('item_identifiers.kind', $kind)
            ->where('item_identifiers.hash', $hash)
            ->whereIn('listings.status', [ListingStatus::Active, ListingStatus::Reserved])
            ->whereNull('listings.deleted_at')
            ->select('listings.*');
    }

    /** @return int listings queued */
    private function flagMatching(string $kind, string $hash, string $policeRef): int
    {
        $queued = 0;

        foreach ($this->liveListings($kind, $hash)->get() as $listing) {
            $this->moderation->enqueue($listing, ModerationTrigger::StolenClaim, [
                'kind'       => $kind,
                'police_ref' => $policeRef,
            ]);

            if ($listing->status === ListingStatus::Active) {
                $listing->forceFill(['status' => ListingStatus::PendingReview])->save();
            }

            $queued++;
        }

        return $queued;
    }
}
