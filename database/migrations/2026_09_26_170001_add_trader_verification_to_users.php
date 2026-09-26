<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checking that a declared company is real and is theirs.
 *
 * WHAT ALREADY EXISTED, so that nobody rebuilds it: `users.seller_type` and
 * `users.trader_details` have been here since the beginning, and they carry the
 * Art. 6a disclosure — every listing page states whether the seller declared
 * themselves a trader and what that means for the buyer's rights. That is a
 * DECLARATION: self-reported, legally required, and nobody checks it.
 *
 * This adds the second, entirely optional half. A declaration says „I am a
 * company"; a verification says „somebody opened the Commercial Register and
 * the company exists with that ЕИК at that address". They are different claims
 * and the site must never show them as the same badge.
 *
 * WHY COLUMNS AND NOT ANOTHER KEY IN `trader_details`. That column is `jsonb`,
 * it is FILLABLE, and it is written from the user's own settings form. A
 * `verified` flag inside it would be one refactor away from being something a
 * seller can set on themselves — EditProfile happens to whitelist the four
 * keys it validates, and that is the only thing standing in the way today.
 * A moderation decision belongs where user input cannot reach it, which is why
 * none of these four are in $fillable.
 *
 * WHY NO HISTORY TABLE. A rejected applicant re-applies and overwrites the
 * decision, which loses the previous one. That is a deliberate trade for now:
 * the note survives on the row until it is replaced, and the alternative is a
 * table with one row per attempt for a queue that will see a handful of
 * applications a month. If disputes ever need a trail, this is where it goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * none → pending → verified | rejected, and back to pending on a
             * re-application. `none` rather than null so the column is never a
             * three-state boolean pretending to be an enum.
             */
            $table->string('trader_status', 20)->default('none');

            $table->timestamp('trader_verified_at')->nullable();
            $table->foreignId('trader_verified_by')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             * Why it was accepted or refused, in the moderator's words.
             *
             * On a refusal the applicant SEES this, so it has to be a sentence
             * about what did not match rather than a verdict — the same rule the
             * statement of reasons follows for a removed listing.
             */
            $table->string('trader_note', 500)->nullable();

            // The admin queue's only ordering: what is waiting, oldest first.
            $table->index(['trader_status', 'updated_at']);
        });

        // Same shape as the seller_type check above it in the original
        // migration: the database refuses a status nothing in the code knows.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_trader_status_check
                       CHECK (trader_status IN ('none','pending','verified','rejected'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_trader_status_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trader_verified_by');
            $table->dropIndex(['trader_status', 'updated_at']);
            $table->dropColumn(['trader_status', 'trader_verified_at', 'trader_note']);
        });
    }
};
