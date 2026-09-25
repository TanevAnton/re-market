<?php

namespace App\Services\Billing;

use App\Models\Payment;

/**
 * What a way of paying has to be able to do.
 *
 * THREE METHODS, AND THE INTERFACE EXISTS FOR THE SECOND ONE. A bank transfer
 * and a card differ in exactly one place — what tells the site the money
 * arrived. For the bank that is a human reading a statement; for Stripe it is
 * a signed webhook. Everything on either side of that moment (the amount, the
 * reference, the ledger row, the invoice) is identical, and writing it twice
 * is how the two paths end up disagreeing about something that matters.
 *
 * `instructions()` is here rather than in a view because what a payer must do
 * is a property of the provider: an IBAN and a reference for one, a redirect
 * for the other. The screen renders whatever it is handed.
 */
interface PaymentProvider
{
    /** The value stored in `payments.provider`. */
    public function key(): string;

    /** What this looks like in the seller's language. */
    public function label(): string;

    /**
     * Start a payment. May prepare something on the provider's side.
     *
     * The Payment row already exists and is `pending` — this is the hook for
     * whatever the provider needs doing before the payer is shown anything.
     */
    public function start(Payment $payment): void;

    /**
     * What the payer has to do now, as label => value pairs, ready to render.
     *
     * @return array<string, string>
     */
    public function instructions(Payment $payment): array;
}
