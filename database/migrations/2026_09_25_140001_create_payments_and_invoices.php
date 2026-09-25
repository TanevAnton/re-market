<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How money gets INTO the balance, and the document that proves it did.
 *
 * `credit_transactions` already records that credit appeared. It does not
 * record that somebody paid for it, who they were when they did, or what the
 * site owes them on paper. Those are three different questions and they have
 * three different lifetimes:
 *
 *   payments  — a request to pay, and whether it arrived. Days.
 *   invoices  — the document. Ten years, by law, and immutable.
 *   ledger    — the balance movement. Forever, already built.
 *
 * WHY A PAYMENT IS NOT JUST A LEDGER ROW WITH A NOTE. A bank transfer exists
 * for a while as a thing that was asked for and has not arrived. The ledger
 * has no state — a row in it means the money is THERE — so a pending transfer
 * cannot live in it without inventing a kind that means "not really". The
 * payment row is that state, and the ledger row is written only when it ends.
 *
 * WHY A PROVIDER COLUMN ON DAY ONE, with exactly one provider. The manual
 * bank driver and a card PSP differ in what confirms them (a human reading a
 * statement; a signed webhook) and in nothing else. Naming the provider now
 * means the second one is a row value rather than a migration on a table that
 * by then holds real money.
 *
 * THE INVOICE NUMBER IS THE PART THAT CANNOT BE FIXED LATER. Bulgarian
 * invoice numbers are sequential and GAPLESS, ten digits, per issuer. A
 * database sequence is the wrong tool: sequences deliberately survive
 * rollback, so a failed transaction burns a number and leaves a hole that has
 * to be explained to an accountant. `invoice_counters` is a single row taken
 * with SELECT ... FOR UPDATE inside the issuing transaction — so a rollback
 * gives the number back, and two concurrent issues queue instead of colliding.
 * Slow, and it should be: it happens once per payment, not once per request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->integer('amount_cents');

            // 'bank' today. 'stripe' is a value, not a migration.
            $table->string('provider', 20);

            // pending → confirmed | cancelled. Nothing returns from the last two.
            $table->string('status', 20)->default('pending');

            /*
             * What the payer writes in the transfer's reason field, and the
             * only thing tying a line on a bank statement to a row here.
             * Unique and short enough to be typed correctly by a person on a
             * phone in a banking app.
             */
            $table->string('reference', 24)->unique();

            /*
             * The buyer AS THEY WERE AT THE MOMENT OF PAYMENT, copied rather
             * than joined. An invoice is a statement about a past fact: if the
             * seller changes company name next year, the document issued today
             * must still show the name that was on it today. A join would
             * quietly rewrite history.
             */
            $table->string('bill_to_name', 160);
            $table->string('bill_to_eik', 20)->nullable();        // ЕИК / Булстат
            $table->string('bill_to_vat', 24)->nullable();        // ДДС номер
            $table->string('bill_to_address', 240);
            $table->string('bill_to_city', 80);
            $table->string('bill_to_person', 160)->nullable();    // МОЛ

            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason', 160)->nullable();

            // The provider's own id — a Stripe PaymentIntent, later.
            $table->string('provider_ref', 120)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            // The admin screen's only query: what is waiting, oldest first.
            $table->index(['status', 'created_at']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             * One invoice per payment, enforced by the database rather than by
             * remembering. Issuing twice for one payment is a duplicate
             * document with a real number on it, which is a correction
             * procedure rather than a bug fix.
             */
            $table->foreignId('payment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Ten digits, zero-padded, as a string: it is an identifier, not a
            // quantity, and leading zeros are part of it.
            $table->string('number', 10)->unique();
            $table->date('issued_on');

            $table->integer('net_cents');
            $table->integer('vat_cents');
            $table->integer('total_cents');

            /*
             * The VAT treatment, in words, frozen onto the document.
             *
             * Either a rate was charged or there is a GROUND for not charging
             * one, and which of those is true depends on a registration this
             * migration cannot see. Both the rate and the sentence are copied
             * from config at issue time, so changing the config later cannot
             * retroactively alter what a document already says.
             */
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->string('vat_note', 240)->nullable();

            // The issuer, also frozen. Same reason as the buyer.
            $table->string('issuer', 500);

            $table->timestamps();

            $table->index(['user_id', 'issued_on']);
        });

        /*
         * The counter. One row per series, and the series is the year.
         *
         * `next` is the number that will be handed out, not the last one used
         * — an off-by-one here is a duplicated invoice number.
         */
        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->string('series', 16)->primary();
            $table->bigInteger('next');
            $table->timestamps();
        });

        /*
         * An issued invoice is not editable, and the database says so rather
         * than trusting every future caller to know it.
         *
         * A RAISING TRIGGER, not a rule that quietly does nothing: the same
         * decision as the credit ledger, for the same reason. A correction to
         * an invoice is a credit note — another document — never a rewrite of
         * this one.
         *
         * DELETE is deliberately left alone, so a GDPR erasure of a user can
         * still cascade. Whether it SHOULD cascade is a question for the
         * accountant: the ten-year retention obligation on an issued invoice
         * and the right to erasure point in opposite directions, and that
         * conflict is not resolved by a migration.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoices_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'An issued invoice cannot be changed. Issue a credit note.';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER invoices_no_update
            BEFORE UPDATE ON invoices
            FOR EACH ROW EXECUTE FUNCTION invoices_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS invoices_no_update ON invoices');
        DB::statement('DROP FUNCTION IF EXISTS invoices_immutable()');

        Schema::dropIfExists('invoice_counters');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payments');
    }
};
