<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paid visibility, and the money that buys it.
 *
 * TWO TABLES, AND THE SECOND ONE IS THE IMPORTANT ONE.
 *
 * `boosts` is what a seller bought. `credit_transactions` is the ledger it was
 * paid from — an append-only list of movements, never a balance. The balance
 * is SUM(amount_cents) and is computed every time it is asked for.
 *
 * WHY A LEDGER AND NOT A `users.credit_cents` COLUMN. A stored balance is a
 * number that can disagree with the history that produced it, and when it
 * does there is no way to tell which one is wrong. Every bug in that shape is
 * somebody's money. The sum of an indexed integer column over one user's rows
 * is a few microseconds; a balance that has drifted is an afternoon with a
 * spreadsheet and an angry seller.
 *
 * WHY A WALLET AT ALL, rather than charging a card per boost. A bump is €1.
 * Card fees in Bulgaria are a fixed component plus a percentage, so a €1
 * charge can lose a quarter of its value before it arrives — and three bumps
 * in a week is three fees. One €20 top-up and many small spends is how every
 * marketplace in this region does it, and the reason is arithmetic rather than
 * fashion.
 *
 * A note for whoever asks the lawyer: a balance spendable ONLY on this site's
 * own services is the classic shape of the PSD2 limited network exclusion —
 * the same shape as a shop gift card. That is a question for the accountant
 * before the first top-up, not an assertion by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boosts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();

            /*
             * The BUYER, kept even though the listing already knows its owner.
             * This is an accounting record: it has to stay answerable years
             * later, after the listing is gone and regardless of whether
             * ownership was ever reassigned.
             */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('tier', 16);

            // What it actually cost, frozen. Reading the price back out of
            // config months later gives today's price, not the one that was
            // charged — which is the wrong number on an invoice.
            $table->unsignedBigInteger('price_cents');

            $table->timestamp('starts_at');

            /*
             * NULLABLE, and null means „instant, already done" — a bump.
             * Highlight and pin carry a real end. Storing a zero-length window
             * for a bump instead would make every „is this still running"
             * query need a special case for the one tier that never is.
             */
            $table->timestamp('ends_at')->nullable();

            // Set when a boost is cut short: the listing sold, or a moderator
            // removed it. The row stays, because it was still bought.
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason', 40)->nullable();

            $table->timestamps();

            // The hot query: „which boosts are live for these listings".
            $table->index(['listing_id', 'ends_at']);
            $table->index(['tier', 'ends_at']);
            $table->index('user_id');
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * SIGNED. Positive is money in (a top-up, an admin grant, a
             * refund); negative is money out (a boost). One column with a sign
             * rather than two columns plus a direction flag, because the
             * balance is then SUM() and cannot be got wrong by reading the
             * flag backwards.
             */
            $table->bigInteger('amount_cents');

            $table->string('kind', 16);          // topup · spend · refund · grant
            $table->string('note', 160)->nullable();

            // What this movement was for: a Boost, a Payment, nothing.
            $table->nullableMorphs('source');

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        /*
         * The balance is read on nearly every authenticated page that can
         * spend it, always as one aggregate over one user. This index is what
         * keeps that a cheap decision rather than a reason to cache a number
         * that will drift.
         */
        DB::statement('CREATE INDEX credit_balance_idx ON credit_transactions (user_id) INCLUDE (amount_cents)');

        /*
         * The ledger is append-only, and the database enforces it rather than
         * trusting every future caller to remember. Editing a movement is not
         * a correction — it is the loss of the only record of what happened.
         * Corrections are new rows with the opposite sign.
         *
         * A TRIGGER THAT RAISES, not a rule that does nothing. The first
         * version of this migration used
         * `CREATE RULE ... ON UPDATE DO INSTEAD NOTHING`, which has two
         * problems and both are the quiet kind: the UPDATE appears to succeed
         * while changing nothing, and the matching DELETE rule would have
         * silently swallowed the `ON DELETE CASCADE` from `users`, leaving the
         * foreign key to fail a user deletion for reasons nothing explains.
         *
         * DELETE is deliberately left alone: the cascade has to work, and a
         * user erased under GDPR must take their ledger with them.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION credit_transactions_immutable()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'credit_transactions is append-only: post a correcting row instead of editing %',
                    OLD.id;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER credit_transactions_no_update
                BEFORE UPDATE ON credit_transactions
                FOR EACH ROW EXECUTE FUNCTION credit_transactions_immutable()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS credit_transactions_no_update ON credit_transactions');
        DB::statement('DROP FUNCTION IF EXISTS credit_transactions_immutable()');

        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('boosts');
    }
};
