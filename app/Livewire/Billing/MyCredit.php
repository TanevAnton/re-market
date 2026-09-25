<?php

namespace App\Livewire\Billing;

use App\Models\Boost;
use App\Models\CreditTransaction;
use App\Models\Payment;
use App\Services\Billing\CreditService;
use App\Services\Billing\PaymentService;
use App\Support\BillingIdentity;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * The seller's balance, its history, and the way to add to it.
 *
 * THE HISTORY IS THE POINT, not the number at the top. A seller who paid for
 * a pin and then sold the item has three rows — the top-up, the spend, the
 * partial refund — and without a screen that shows them, the balance is a
 * number the site asserts and the seller has to trust. That is exactly the
 * relationship this marketplace is supposed to be the opposite of.
 *
 * The ledger itself is read-only. Every row is written by CreditService and
 * the table refuses UPDATE at the database level, so there is nothing here to
 * edit — only a request to add more.
 */
class MyCredit extends Component
{
    use WithPagination;

    /** The top-up form, closed until asked for. */
    public bool $toppingUp = false;

    public int $amount = 2000;

    public string $billName    = '';
    public string $billEik     = '';
    public string $billVat     = '';
    public string $billAddress = '';
    public string $billCity    = '';
    public string $billPerson  = '';

    public function mount(): void
    {
        /*
         * Prefilled from the last top-up rather than from a profile field.
         *
         * A dealer's invoice details do not change and retyping an ЕИК and a
         * registered address every time is how a seller decides not to bother.
         * Read from the last payment because that is already the authoritative
         * copy — adding columns to `users` for it would create a second place
         * for the same facts to be wrong.
         */
        $last = Payment::where('user_id', auth()->id())->latest('id')->first();

        if ($last) {
            $this->billName    = $last->bill_to_name;
            $this->billEik     = (string) $last->bill_to_eik;
            $this->billVat     = (string) $last->bill_to_vat;
            $this->billAddress = $last->bill_to_address;
            $this->billCity    = $last->bill_to_city;
            $this->billPerson  = (string) $last->bill_to_person;
        } else {
            $this->billName = auth()->user()->name ?? '';
            $this->billCity = auth()->user()->city?->name() ?? '';
        }
    }

    public function startTopUp(): void
    {
        $this->toppingUp = true;
    }

    public function cancelTopUp(): void
    {
        $this->toppingUp = false;
        $this->resetErrorBag();
    }

    public function requestTopUp(): void
    {
        $min = (int) config('remarket.billing.topup_min', 500);
        $max = (int) config('remarket.billing.topup_max', 50000);

        $this->validate([
            'amount'      => "required|integer|min:{$min}|max:{$max}",
            // Required because they go on a legal document. „Fill it in later"
            // means an admin chasing an ЕИК by email while a confirmed payment
            // sits uninvoiced.
            'billName'    => 'required|string|min:2|max:160',
            'billAddress' => 'required|string|min:4|max:240',
            'billCity'    => 'required|string|min:2|max:80',
            'billEik'     => 'nullable|string|max:20',
            'billVat'     => 'nullable|string|max:24',
            'billPerson'  => 'nullable|string|max:160',
        ], [], [
            'amount'      => 'сумата',
            'billName'    => 'наименованието',
            'billAddress' => 'адресът',
            'billCity'    => 'градът',
        ]);

        try {
            app(PaymentService::class)->request(auth()->user(), $this->amount, [
                'name'    => $this->billName,
                'eik'     => $this->billEik,
                'vat'     => $this->billVat,
                'address' => $this->billAddress,
                'city'    => $this->billCity,
                'person'  => $this->billPerson,
            ]);
        } catch (RuntimeException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->toppingUp = false;

        session()->flash('status', 'Заявката е създадена. Направи превода с посоченото основание.');
    }

    #[Layout('components.layouts.app')]
    public function render()
    {
        return view('livewire.billing.my-credit', [
            'balance' => app(CreditService::class)->balance(auth()->user()),

            // Waiting on a transfer. Shown above everything else, because a
            // seller who has paid and sees nothing assumes it went wrong.
            'pending' => Payment::where('user_id', auth()->id())
                ->pending()
                ->latest('id')
                ->get(),

            'payments' => Payment::where('user_id', auth()->id())
                ->whereNot('status', \App\Enums\PaymentStatus::Pending)
                ->with('invoice')
                ->latest('id')
                ->limit(12)
                ->get(),

            'provider'  => app(PaymentService::class)->provider(Payment::BANK),
            // When this is false the top-up button does not appear at all,
            // rather than appearing and then refusing. See BillingIdentity.
            'canPay'    => BillingIdentity::ready(),
            'options'   => config('remarket.billing.topup_options', [1000, 2000, 5000]),

            'rows' => CreditTransaction::where('user_id', auth()->id())
                /*
                 * The morph target is a Boost on every spend and refund, and
                 * the row links to the listing it promoted. morphWith() rather
                 * than `with('source.listing')`: a morphTo cannot eager-load a
                 * nested relation blindly, because not every target has one —
                 * and without this the page is a query per row.
                 */
                ->with(['source' => fn (MorphTo $m) => $m->morphWith([Boost::class => ['listing']])])
                ->latest('id')
                ->paginate(30),
        ]);
    }
}
