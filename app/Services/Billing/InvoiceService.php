<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\BillingIdentity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issuing the document, and handing out the number.
 *
 * THE NUMBER IS THE WHOLE JOB. Bulgarian invoice numbers are ten digits,
 * sequential and GAPLESS per issuer, and „gapless" is the word that rules out
 * every convenient implementation:
 *
 *   - A database SEQUENCE survives rollback by design. A transaction that
 *     fails after taking a number leaves a hole, and a hole is something
 *     somebody has to explain to an accountant years later.
 *   - MAX(number) + 1 is a race. Two confirmations in the same second read the
 *     same maximum and both write it, and one of them is a duplicate document
 *     with a real number on it.
 *
 * So: a counter row, taken with SELECT ... FOR UPDATE inside the same
 * transaction that writes the invoice. A rollback gives the number back
 * because the lock and the row move together; a second caller waits rather
 * than reading a stale value. This is slow, and it is supposed to be — it runs
 * once per confirmed payment, not once per page.
 *
 * WHY THE DOCUMENT COPIES EVERYTHING. Issuer, buyer, VAT rate, VAT ground: all
 * frozen onto the row. An invoice is a statement about a past fact. If the
 * company registers for VAT next month, or the seller changes their address,
 * the documents already issued must still say what they said — a join would
 * rewrite them silently, and nobody would ever notice.
 */
class InvoiceService
{
    public function issue(Payment $payment): Invoice
    {
        if ($payment->invoice()->exists()) {
            throw new RuntimeException('За това плащане вече е издадена фактура.');
        }

        /*
         * The refusal that protects a number series from a half-configured
         * deployment. `.env` on a fresh server is empty, and without this the
         * first confirmed payment issues 0000000001 with a blank company name
         * and no VAT treatment — a wrong document with a real number, which is
         * a correction procedure rather than a bug fix.
         */
        if ($blanks = BillingIdentity::missing()) {
            throw new RuntimeException(
                'Липсват данни за фактуриране: '.implode(', ', $blanks)
                .'. Попълни ги в .env, преди да потвърдиш плащане.'
            );
        }

        $split = BillingIdentity::split($payment->amount_cents);

        return DB::transaction(function () use ($payment, $split) {
            $number = $this->nextNumber();

            $invoice = new Invoice();

            $invoice->forceFill([
                'payment_id'  => $payment->id,
                'user_id'     => $payment->user_id,
                'number'      => $number,
                'issued_on'   => now()->toDateString(),
                'net_cents'   => $split['net'],
                'vat_cents'   => $split['vat'],
                'total_cents' => $split['total'],
                'vat_rate'    => $split['rate'],
                'vat_note'    => $split['note'],
                'issuer'      => BillingIdentity::issuerBlock(),
            ])->save();

            return $invoice;
        });
    }

    /**
     * The next number in this year's series, locked.
     *
     * Ten digits zero-padded, which is the conventional Bulgarian form. The
     * series restarts per year in the counter table but the NUMBER does not
     * reset — a fresh 0000000001 every January would collide with last
     * January's. The series exists so the counter row can be reasoned about
     * per year; continuity across years comes from seeding the new row with
     * the old one's value.
     *
     * MUST be called inside a transaction. The lock it takes is only worth
     * anything while that transaction is open.
     */
    private function nextNumber(): string
    {
        $series = now()->format('Y');

        // where('series'), NOT find(): the query builder's find() looks for a
        // column called `id`, and this table's key is the series string.
        $row = DB::table('invoice_counters')->where('series', $series)->lockForUpdate()->first();

        if (! $row) {
            // Continue from wherever the last series ended, so numbers never
            // repeat across a year boundary.
            $carry = (int) DB::table('invoice_counters')->max('next');

            DB::table('invoice_counters')->insert([
                'series'     => $series,
                'next'       => max($carry, 1),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('invoice_counters')->where('series', $series)->lockForUpdate()->first();
        }

        $number = (int) $row->next;

        DB::table('invoice_counters')
            ->where('series', $series)
            ->update(['next' => $number + 1, 'updated_at' => now()]);

        return str_pad((string) $number, 10, '0', STR_PAD_LEFT);
    }
}
