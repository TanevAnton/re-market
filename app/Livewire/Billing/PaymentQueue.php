<?php

namespace App\Livewire\Billing;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Billing\PaymentService;
use App\Support\BillingIdentity;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * „Has this transfer arrived?" — the one screen where a human turns a bank
 * statement into credit.
 *
 * THIS IS THE MANUAL PROVIDER'S ENTIRE COST, and it is worth being honest
 * about: somebody has to open their bank, match a reference and click a
 * button, and until that happens a seller who paid has nothing. That is the
 * trade for shipping without a PSP, and it is why the pending list is ordered
 * oldest first — the one that has been waiting longest is the one someone is
 * annoyed about.
 *
 * Confirming is irreversible by design: it issues a numbered document and
 * moves money. There is no „unconfirm" here and there must not be one — a
 * mistake is corrected with a credit note and a negative ledger row, both of
 * which leave a trail.
 */
class PaymentQueue extends Component
{
    use WithPagination;

    public ?int $cancelling = null;
    public string $cancelReason = '';

    public function confirm(int $id): void
    {
        $payment = Payment::find($id);

        if (! $payment) {
            $this->addError('payment', 'Плащането вече не съществува.');

            return;
        }

        try {
            app(PaymentService::class)->confirm($payment, auth()->user());
        } catch (RuntimeException $e) {
            // Most often the billing config: the service refuses to issue an
            // invoice from a half-filled .env, and says which field is blank.
            $this->addError('payment', $e->getMessage());

            return;
        }

        session()->flash('status', 'Плащането е потвърдено и кредитът е зареден.');
    }

    public function startCancel(int $id): void
    {
        $this->cancelling   = $id;
        $this->cancelReason = '';
    }

    public function cancelCancel(): void
    {
        $this->cancelling = null;
    }

    public function cancel(int $id): void
    {
        $payment = Payment::find($id);

        if (! $payment) {
            $this->addError('payment', 'Плащането вече не съществува.');

            return;
        }

        try {
            app(PaymentService::class)->cancel(
                $payment,
                trim($this->cancelReason) ?: 'Преводът не постъпи.',
            );
        } catch (RuntimeException $e) {
            $this->addError('payment', $e->getMessage());

            return;
        }

        $this->cancelling = null;

        session()->flash('status', 'Плащането е отказано.');
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.billing.payment-queue', [
            // Oldest first: the longest wait is the one somebody is chasing.
            'pending'  => Payment::pending()->with('user')->oldest('id')->get(),
            'settled'  => Payment::whereNot('status', PaymentStatus::Pending)
                ->with(['user', 'invoice', 'confirmer'])
                ->latest('id')
                ->paginate(25),

            // Named before anything can be clicked, rather than as a failure
            // after an admin has already told a seller their money arrived.
            'blanks'   => BillingIdentity::missing(),
        ]);
    }
}
