<?php

namespace App\Services\Moderation;

use App\Enums\ModerationTrigger;
use App\Enums\ReportReason;
use App\Models\ModerationItem;
use App\Models\Report;
use App\Models\User;
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
    public function settle(ModerationItem $item, string $decision, ?string $statement, User $handler): int
    {
        return Report::query()
            ->where('reportable_type', $item->subject_type)
            ->where('reportable_id', $item->subject_id)
            ->where('status', 'open')
            ->update([
                'status'     => $decision === 'rejected' ? 'actioned' : 'rejected',
                'decision'   => $decision,
                'handled_by' => $handler->id,
                // When a listing was left up there is no restrictive measure and
                // so no Art. 17 statement; the notifier is still owed the
                // outcome, in words rather than a status code.
                'statement_of_reasons' => $statement
                    ?? 'Проверихме обявата и не установихме нарушение на условията за ползване.',
                'resolved_at' => now(),
                'updated_at'  => now(),
            ]);
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
