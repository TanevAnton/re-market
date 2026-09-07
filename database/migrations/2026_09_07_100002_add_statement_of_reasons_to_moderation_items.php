<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the user was actually told, and a guarantee the queue cannot show the
 * same thing twice.
 *
 * `decision_reason` holds the category a moderator picked; that is a code, not
 * an explanation. DSA Art. 17 requires the person to receive the facts relied
 * on, the ground, whether automation was involved and how to challenge it -
 * composed into one text and stored verbatim, because the record of what
 * someone was told is worth more than the ability to regenerate it later from
 * templates that will have changed by then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moderation_items', function (Blueprint $table) {
            $table->text('statement_of_reasons')->nullable()->after('decision_reason');
        });

        /*
         * One pending item per subject per trigger. Without this, two requests
         * publishing at once - or any future automated check that runs twice -
         * put the same listing in the queue twice, and a moderator approves it
         * once and is then asked about it again.
         *
         * Partial, so the history of decided items is kept in full: the same
         * listing may legitimately be flagged again later.
         */
        DB::statement("
            CREATE UNIQUE INDEX moderation_items_pending_unique
            ON moderation_items (subject_type, subject_id, trigger)
            WHERE status = 'pending'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS moderation_items_pending_unique');

        Schema::table('moderation_items', function (Blueprint $table) {
            $table->dropColumn('statement_of_reasons');
        });
    }
};
