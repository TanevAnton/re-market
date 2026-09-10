<?php

namespace App\Services\Moderation;

use App\Enums\ModerationTrigger;
use App\Enums\ReportReason;
use App\Models\ModerationItem;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportOutcome;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Notice and action - DSA Art. 16.
 *
 * The obligations are specific and all of them are structural rather than
 * cosmetic: the mechanism must be electronic and easy to use, it must accept a
 * precise location for the content, it must confirm receipt, and the person who
 * reported must be told the outcome. None of that can be bolted on later
 * without a schema change, so it is all here from the start.
 */
class ReportService
{
    public function __construct(
        private readonly ModerationService $moderation,
    ) {}

    /**
     * Accept a notice.
     *
     * Anonymous notices are allowed - Art. 16 does not permit requiring an
     * account, and insisting on one would silently drop reports from the people
     * most likely to spot a scam: buyers who have not signed up yet.
     */
    public function file(
        Model $reportable,
        ReportReason $reason,
        string $detail,
        ?User $reporter = null,
        ?string $reporterEmail = null,
        ?string $evidenceUrl = null,
    ): Report {
        // One person, one open notice per thing. Ten reports from one angry
        // buyer must not become ten items in a queue that a real problem is
        // waiting in.
        $existing = $this->openNoticeFrom($reportable, $reporter, $reporterEmail);

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use (
            $reportable, $reason, $detail, $reporter, $reporterEmail, $evidenceUrl
        ) {
            $report = Report::create([
                'reporter_id'     => $reporter?->id,
                'reporter_email'  => $reporter?->email ?? $reporterEmail,
                'reportable_type' => $reportable->getMorphClass(),
                'reportable_id'   => $reportable->getKey(),
                'reason'          => $reason->value,
                'detail'          => $detail,
                'evidence_url'    => $evidenceUrl,
            ]);

            // Art. 16(4): confirm receipt without undue delay. There is nothing
            // to wait for - the notice is stored, so it is received.
            $report->forceFill(['acknowledged_at' => now()])->save();

            $item = $this->moderation->enqueue($reportable, ModerationTrigger::Reported, [
                'reports' => [],
            ]);

            /*
             * Several people reporting the same listing is one review, not
             * several - but the moderator needs to see all of them, because
             * three independent "this is my photo" notices mean something one
             * does not.
             */
            $context             = $item->context ?? [];
            $context['reports']  = array_merge($context['reports'] ?? [], [[
                'uuid'   => $report->uuid,
                'reason' => $reason->value,
                'label'  => $reason->label(),
                'detail' => $detail,
                'by'     => $reporter?->username ?? 'анонимен',
            ]]);

            // The most serious notice sets the pace for the whole item.
            $item->forceFill([
                'context'  => $context,
                'priority' => min($item->priority, $reason->priority()),
            ])->save();

            return $report;
        });
    }

    /**
     * Tell everyone who reported this what happened to it.
     *
     * Art. 16(5) - the decision goes back to the notifier, not only into our
     * own records. Called from ModerationService when an item is decided.
     */
    public function settle(ModerationItem $item, string $decision, ?string $statement, User $handler): Collection
    {
        // When a listing was left up there is no restrictive measure and so no
        // Art. 17 statement; the notifier is still owed the outcome, in words
        // rather than a status code.
        $outcome = $statement
            ?? 'Проверихме обявата и не установихме нарушение на условията за ползване.';

        /*
         * Read the notices BEFORE the update, and keep them.
         *
         * This used to be one bulk `update()` returning a row count. A bulk
         * update touches rows without loading a model, so there was nothing to
         * notify and nobody was told - the method's own docblock said "tell
         * everyone who reported this", and it told no one. Exactly the trap the
         * losing bidders fell into in OfferService.
         *
         * After the update these rows no longer match `status = open`, so the
         * read has to come first or it comes back empty.
         */
        $reports = Report::query()
            ->with('reporter')
            ->where('reportable_type', $item->subject_type)
            ->where('reportable_id', $item->subject_id)
            ->where('status', 'open')
            ->get();

        if ($reports->isEmpty()) {
            return $reports;
        }

        Report::whereKey($reports->modelKeys())->update([
            'status'               => $decision === 'rejected' ? 'actioned' : 'rejected',
            'decision'             => $decision,
            'handled_by'           => $handler->id,
            'statement_of_reasons' => $outcome,
            'resolved_at'          => now(),
            'updated_at'           => now(),
        ]);

        // Carried on the in-memory copies so the caller can send them without
        // re-reading rows that no longer match the query above.
        return $reports->each(fn (Report $r) => $r->forceFill([
            'decision'             => $decision,
            'statement_of_reasons' => $outcome,
        ]));
    }

    /**
     * Send the outcome to the people who reported.
     *
     * Called by ModerationService AFTER its transaction commits: these are
     * queued jobs, and a queued job can start before the commit it depends on
     * and read a row that does not exist yet.
     *
     * Only reporters with an account are reached. Anonymous notices are
     * accepted on purpose - Art. 16 does not permit demanding an account - but
     * they leave only an email address, and mailing an unverified address
     * supplied by a stranger is an open relay for whoever wants to use our
     * domain to send someone a message. Their outcome is on the record and
     * available on request instead. This is a deliberate limit, not an
     * oversight; revisit it if a verified reply-address flow is ever built.
     *
     * @param  Collection<int, Report>  $reports
     */
    public function notifySettled(Collection $reports): void
    {
        $seen = [];

        foreach ($reports as $report) {
            $reporter = $report->reporter;

            // One outcome per person, even if they filed against several
            // things in the same batch - and never to the person who was
            // reported, who gets the Art. 17 statement of reasons instead.
            if (! $reporter || isset($seen[$reporter->id])) {
                continue;
            }

            $seen[$reporter->id] = true;
            $reporter->notify(new ReportOutcome($report));
        }
    }

    private function openNoticeFrom(Model $reportable, ?User $reporter, ?string $email): ?Report
    {
        $query = Report::query()
            ->where('reportable_type', $reportable->getMorphClass())
            ->where('reportable_id', $reportable->getKey())
            ->where('status', 'open');

        if ($reporter) {
            return $query->where('reporter_id', $reporter->id)->first();
        }

        if (filled($email)) {
            return $query->whereNull('reporter_id')->where('reporter_email', $email)->first();
        }

        return null;
    }
}
