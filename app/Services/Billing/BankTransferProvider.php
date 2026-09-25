<?php

namespace App\Services\Billing;

use App\Models\Payment;

/**
 * A bank transfer, confirmed by a person reading a statement.
 *
 * SLOW ON PURPOSE, AND THE RIGHT FIRST PROVIDER. It costs nothing per
 * transaction, needs no PSP account, no PCI posture and no webhook endpoint
 * to get wrong — and it answers the only question that matters before any of
 * that work is worth doing, which is whether anybody will pay at all. If three
 * sellers a month top up, a card integration is a week spent saving them a
 * day; if three a day do, it pays for itself immediately and the interface
 * above means it drops in beside this one.
 *
 * The site never sees a card number, an IBAN of the payer's, or anything else
 * that would make this deployment interesting to attack.
 */
class BankTransferProvider implements PaymentProvider
{
    public function key(): string
    {
        return Payment::BANK;
    }

    public function label(): string
    {
        return 'Банков превод';
    }

    public function start(Payment $payment): void
    {
        // Nothing to prepare. The reference was generated with the row, and
        // the bank knows nothing about this site until the money arrives.
    }

    public function instructions(Payment $payment): array
    {
        $b = config('remarket.billing');

        return array_filter([
            'Получател'      => \App\Support\BillingIdentity::company(),
            'IBAN'           => (string) $b['iban'],
            'BIC'            => (string) $b['bic'],
            'Банка'          => (string) $b['bank'],
            'Сума'           => $payment->formattedAmount(),
            // The one line that has to be typed exactly. It is last because
            // it is what a payer should still be looking at when they switch
            // to their banking app.
            'Основание'      => $payment->reference,
        ], fn ($v) => $v !== '');
    }
}
