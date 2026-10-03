<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GDPR Art. 15 (a copy of your data) and Art. 17 (erase me), as two things the
 * user can do themselves instead of opening a support ticket.
 *
 * WHY ERASURE IS ANONYMISATION AND NOT A DELETE, decided by reading the schema
 * rather than by preference. EVERY user foreign key in this database is
 * `cascadeOnDelete`: deals, ratings, threads, messages, offers, favourites,
 * payments AND invoices. So `$user->forceDelete()` is not „remove this person" —
 * it is a cascade that:
 *
 *   - deletes the counterparty's side of every deal they were ever in, taking
 *     that seller's completed-deal count down with it;
 *   - deletes the ratings they RECEIVED, which are somebody else's testimony
 *     about them, and the ratings they GAVE, which are part of somebody else's
 *     reputation;
 *   - and deletes INVOICES, which чл. 38 ДОПК requires be kept for five years.
 *
 * The last one is not a trade-off, it is illegal. GDPR Art. 17(3)(b) exists for
 * exactly this: erasure does not apply where processing is necessary for
 * compliance with a legal obligation. So the row stays, the person is scrubbed
 * out of it, and the deletion screen says plainly which records survive and why.
 *
 * `users.deleted_at` already exists (SoftDeletes). These two columns record the
 * two separate moments that were previously conflated with it: when they ASKED,
 * and when the data was actually destroyed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * The 30-day clock. Set when they ask; cleared if they log back in
             * and change their mind, which is the whole reason for a window —
             * an account taken over for two minutes must not be erasable
             * permanently in those two minutes.
             */
            $table->timestamp('deletion_requested_at')->nullable();

            // When the scrub actually ran. Separate from `deleted_at` because a
            // soft-deleted account still holds every personal column until this
            // is set, and „are we still holding their data" is the question that
            // matters for a data-protection answer.
            $table->timestamp('anonymised_at')->nullable();

            // The purge sweep's only query.
            $table->index(['deletion_requested_at', 'anonymised_at'], 'users_deletion_sweep_index');
        });

        /*
         * An export is a zip containing somebody's entire history on the site.
         * It is the most concentrated piece of personal data the system ever
         * produces, so it is a tracked object with an expiry rather than a file
         * dropped somewhere and forgotten.
         */
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // nullOnDelete, NOT cascade, and deliberately against the grain of
            // every other table here: if an account is ever genuinely removed,
            // an orphaned export row is a thing the purge can still find and
            // delete the file for. A cascaded row leaves the file on disk.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 16)->default('pending');
            // pending | ready | failed | expired

            // Private disk. NEVER the public one — a guessable path to this file
            // is every message, address and phone number the person ever had.
            $table->string('path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('error')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();

            // Not optional. A copy of someone's whole account sitting on disk
            // indefinitely is a breach waiting for a server misconfiguration.
            $table->timestamp('expires_at');

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement("ALTER TABLE data_exports ADD CONSTRAINT data_exports_status_check
                       CHECK (status IN ('pending','ready','failed','expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_deletion_sweep_index');
            $table->dropColumn(['deletion_requested_at', 'anonymised_at']);
        });
    }
};
