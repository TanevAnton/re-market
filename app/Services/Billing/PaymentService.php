<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use App\Support\BillingIdentity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Money coming in: asked for, then arrived or not.
 *
 * THE ORDER OF OPERATIONS IN confirm() IS THE WHOLE SERVICE. A confirmation
 * does three things — marks the payment, issues the invoice, credits the
 * balance — and they happen in one transaction because any two of the three
 * without the third is a mess somebody has to unpick by hand:
 *
 *   paid, credited, no invoice   → money taken with no document for it
 *   paid, invoiced, not credited → a seller with a receipt and no balance
 *   invoiced, not paid           → a real number on a document for nothing
 *
 * The invoice goes FIRST inside the transaction, because it is the one that
 * can refuse. A deployment with an empty `.env` cannot issue a document, and
 * finding that out before the ledger moves means nothing has to be reversed —
 * the whole confirmation simply does not happen and the admin is told why.
 */
class PaymentService
{
    public function __construct(
        private CreditService $credit,
        private InvoiceService $invoices,
    ) {}

    /** @return list<PaymentProvider> */
    public function providers(): array
    {
        return [new BankTransferProvider()];
    }

    public function provider(string $key): PaymentProvider
    {
        foreach ($this->providers() as $provider) {
            if ($provider->key() === $key) {
                return $provider;
            }
        }

        throw new RuntimeException('Няма такъв начин на плащане.');
    }

    /**
     * A seller asks to top up. Nothing has been paid yet.
     *
     * The billing details are taken here rather than at confirmation time
     * because this is the moment the buyer is present. An admin confirming a
     * transfer three days later cannot invent somebody's ЕИК, and chasing it
     * by email is how a confirmed payment sits uninvoiced for a fortnight.
     *
     * @param array{name: string, eik: ?string, vat: ?string, address: string, city: string, person: ?string} $billTo
     */
    public function request(User $user, int $amountCents, array $billTo, string $providerKey = Payment::BANK): Payment
    {
        $min = (int) config('remarket.billing.topup_min', 500);
        $max = (int) config('remarket.billing.topup_max', 50000);

        if ($amountCents < $min || $amountCents > $max) {
            throw new RuntimeException(
                'Сумата трябва да е между '.number_format($min / 100, 2, ',', ' ')
                .' € и '.number_format($max / 100, 2, ',', ' ').' €.'
            );
        }

        /*
         * Refused BEFORE the seller fills anything in, not after they have
         * made a transfer. A site that cannot issue an invoice cannot honestly
         * take money for a service — and the seller finding that out at the
         * point of payment is far better than finding it out afterwards.
         */
        if (! BillingIdentity::ready()) {
            throw new RuntimeException(
                'Плащанията не са активни в момента. Пиши ни и ще заредим кредит ръчно.'
            );
        }

        $provider = $this->provider($providerKey);

        $payment = new Payment();

        $payment->forceFill([
            'user_id'         => $user->id,
            'amount_cents'    => $amountCents,
            'provider'        => $provider->key(),
            'status'          => PaymentStatus::Pending,
            'reference'       => $this->freeReference(),
            'bill_to_name'    => mb_substr(trim($billTo['name']), 0, 160),
            'bill_to_eik'     => $this->clean($billTo['eik'] ?? null, 20),
            'bill_to_vat'     => $this->clean($billTo['vat'] ?? null, 24),
            'bill_to_address' => mb_substr(trim($billTo['address']), 0, 240),
            'bill_to_city'    => mb_substr(trim($billTo['city']), 0, 80),
            'bill_to_person'  => $this->clean($billTo['person'] ?? null, 160),
        ])->save();

        $provider->start($payment);

        return $payment;
    }

    /**
     * The money arrived. One transaction, three writes, invoice first.
     *
     * `$confirmer` is recorded because this is a human decision about real
     * money and „who said this arrived" is the first question anybody asks
     * when it turns out it did not.
     */
    public function confirm(Payment $payment, User $confirmer): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw new RuntimeException('Това плащане вече е приключено.');
        }

        return DB::transaction(function () use ($payment, $confirmer) {
            // First, because it is the one that can say no. See the class note.
            $invoice = $this->invoices->issue($payment);

            $payment->forceFill([
                'status'       => PaymentStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_by' => $confirmer->id,
            ])->save();

            $this->credit->topUp(
                $payment->user,
                $payment->amount_cents,
                'Фактура № '.$invoice->number,
                $payment,
            );

            return $payment->refresh();
        });
    }

    /**
     * It did not arrive, or it arrived wrong.
     *
     * No invoice, no ledger row, nothing to reverse — which is exactly why a
     * wrong-amount transfer is cancelled and re-requested rather than
     * „adjusted". A payment row that can change its mind about how much it was
     * worth cannot be reconciled against a bank statement by anybody.
     */
    public function cancel(Payment $payment, string $reason): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw new RuntimeException('Това плащане вече е приключено.');
        }

        $payment->forceFill([
            'status'            => PaymentStatus::Cancelled,
            'cancelled_at'      => now(),
            'cancelled_reason'  => mb_substr($reason, 0, 160),
        ])->save();

        return $payment;
    }

    /**
     * A reference nobody else is using.
     *
     * The alphabet has 26 characters over 8 places, so a collision is
     * vanishingly unlikely — but „vanishingly unlikely" on a unique column is
     * a 500 in front of somebody trying to pay, and the retry costs one query.
     */
    private function freeReference(): string
    {
        for ($try = 0; $try < 8; $try++) {
            $reference = Payment::newReference();

            if (! Payment::where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException('Не можах да създам номер за плащането. Опитай пак.');
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
