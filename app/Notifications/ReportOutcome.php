<?php

namespace App\Notifications;

use App\Models\Report;
use App\Models\User;

/**
 * What happened to something you reported.
 *
 * DSA Art. 16(5): the decision goes back to the notifier, not only into our own
 * records. It was being stored and never sent, which from the reporter's side is
 * indistinguishable from nobody having looked.
 *
 * That matters beyond compliance. Reporting is unpaid work done by people with
 * no stake in the outcome, and the only thing they get for it is knowing it
 * counted. A report that vanishes into silence is the last one that person
 * files - and the people most likely to spot a stolen photograph are exactly
 * the ones we cannot afford to lose.
 */
class ReportOutcome extends RemarketNotification
{
    public function __construct(private readonly Report $report) {}

    public function subject(User $user): string
    {
        return $this->wasActioned()
            ? 'Разгледахме сигнала ти — съдържанието е премахнато'
            : 'Разгледахме сигнала ти';
    }

    public function lines(User $user): array
    {
        $lines = [$this->wasActioned()
            ? 'Благодарим ти. Проверихме подадения от теб сигнал и предприехме мерки.'
            : 'Благодарим ти. Проверихме подадения от теб сигнал.'];

        // The decision verbatim, not a summary of it. It is the same text the
        // affected user received, and two versions of one decision is how a
        // dispute becomes unresolvable.
        if ($statement = $this->report->statement_of_reasons) {
            $lines[] = $statement;
        }

        if (! $this->wasActioned()) {
            // Said plainly rather than left implied. Someone who reported in
            // good faith and was told "no violation" deserves to know that is
            // a judgement about our rules, not about them.
            $lines[] = 'Ако смяташ, че решението е грешно, можеш да подадеш нов сигнал '
                .'с допълнителна информация.';
        }

        return $lines;
    }

    public function url(User $user): string
    {
        return route('legal.notice');
    }

    public function action(User $user): string
    {
        return 'Как работят сигналите';
    }

    private function wasActioned(): bool
    {
        return $this->report->decision === 'rejected';
    }
}
