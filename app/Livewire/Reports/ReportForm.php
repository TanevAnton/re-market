<?php

namespace App\Livewire\Reports;

use App\Enums\ReportReason;
use App\Models\Listing;
use App\Models\User;
use App\Services\Moderation\ReportService;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use App\Livewire\Concerns\ChecksTurnstile;
use Livewire\Component;

/**
 * The notice-and-action form - DSA Art. 16.
 *
 * Deliberately reachable without an account. Requiring signup to report would
 * silently drop notices from exactly the people most likely to spot a scam:
 * buyers who have not signed up yet, and the seller whose photographs were
 * stolen and who has never heard of this site.
 */
class ReportForm extends Component
{
    use ChecksTurnstile;

    /** Locked: these address a database row and arrive from the page. */
    #[Locked]
    public string $type;

    #[Locked]
    public int $id;

    public bool $open = false;
    public bool $sent = false;

    public string $reason = '';
    public string $detail = '';
    public string $email  = '';
    public string $evidence = '';

    /** The reference the reporter is given, so a follow-up can be traced. */
    public ?string $reference = null;

    public function mount(Model $subject): void
    {
        $this->type = $subject::class === Listing::class ? 'listing' : 'user';
        $this->id   = (int) $subject->getKey();
    }

    public function submit(ReportService $reports): void
    {
        $rules = [
            'reason'   => ['required', 'string'],
            // Art. 16(2)(a) wants a notice precise enough to act on. A one-word
            // "измама" is not something a moderator can check.
            'detail'   => ['required', 'string', 'min:15', 'max:2000'],
            'evidence' => ['nullable', 'url', 'max:500'],
        ];

        // Anonymous notices are allowed, but there has to be somewhere to send
        // the decision, which Art. 16(5) requires us to do.
        if (! auth()->check()) {
            $rules['email'] = ['required', 'email', 'max:255'];
        }

        $this->validate($rules, [
            'reason.required' => 'Избери причина.',
            'detail.required' => 'Опиши какъв е проблемът.',
            'detail.min'      => 'Опиши накратко какъв е проблемът — това го чете модератор.',
            'email.required'  => 'Трябва ни имейл, за да ти съобщим решението.',
            'evidence.url'    => 'Връзката не изглежда валидна.',
        ]);

        if (! $this->passesTurnstile()) {
            return;
        }

        $reason = ReportReason::tryFrom($this->reason);

        if (! $reason) {
            $this->addError('reason', 'Избери причина.');

            return;
        }

        $subject = $this->subject();

        if (! $subject) {
            $this->addError('reason', 'Съдържанието вече не съществува.');

            return;
        }

        $report = $reports->file(
            reportable: $subject,
            reason: $reason,
            detail: $this->detail,
            reporter: auth()->user(),
            reporterEmail: $this->email ?: null,
            evidenceUrl: $this->evidence ?: null,
        );

        $this->reference = $report->uuid;
        $this->sent      = true;
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;

        if (! $this->open) {
            $this->reset(['reason', 'detail', 'email', 'evidence']);
            $this->resetErrorBag();
        }
    }

    private function subject(): ?Model
    {
        return $this->type === 'listing'
            ? Listing::find($this->id)
            : User::find($this->id);
    }

    public function render()
    {
        return view('livewire.reports.report-form', [
            'reasons' => ReportReason::options(),
        ]);
    }
}
