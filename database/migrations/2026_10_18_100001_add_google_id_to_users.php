<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Влез с Google".
 *
 * ONE COLUMN, and the interesting decisions are about what is NOT here.
 *
 * No provider column, no separate `social_accounts` table, no second row per
 * user. Those exist for sites that offer five providers; this one offers one,
 * and a table built for a generality nobody has asked for is a table somebody
 * has to keep correct for years. The day a second provider is added, this
 * column becomes that table in a migration of a dozen lines - and until then it
 * is a nullable string with a unique index on it.
 *
 * UNIQUE, which is the whole point: a Google account identifies at most one
 * RIGO account. Without it, two rows could claim the same Google identity and
 * the callback would have to guess which one to sign in.
 *
 * NULLABLE because almost nobody will have one. Postgres allows any number of
 * NULLs under a unique index, so every password account coexists happily.
 *
 * The id, not the email. Google's `sub` is stable for the life of the account;
 * the address on it is not - people change it, and a Workspace admin can change
 * it for them. Keying on the address would silently split one person into two
 * accounts the day they renamed their mailbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_id');
        });
    }
};
