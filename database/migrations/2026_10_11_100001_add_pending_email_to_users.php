<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Changing the email address, which until now was impossible.
 *
 * A typo at registration cost the account outright: the one recovery path is
 * the password reset, and that goes to the address the person cannot read. The
 * only fix was an admin editing the row by hand on the server.
 *
 * THE NEW ADDRESS WAITS HERE UNTIL IT IS PROVEN. It is not written over
 * users.email on request, because that would repeat the original bug on a
 * larger scale - one mistyped character and the account is now pointed at a
 * mailbox nobody owns, with the old one already gone. So the live address keeps
 * working, and the change happens in one step at the moment somebody opens the
 * link in the new inbox.
 *
 * NOT UNIQUE, deliberately. Two people may have a pending claim on the same
 * address at once; the first to confirm takes it and the second is refused at
 * that point, when users.email - which IS unique - is checked again. A unique
 * index here would instead let anybody reserve an address they do not own,
 * simply by typing it into a form, and block the real owner from ever adding
 * it. A claim nobody has proved is not evidence of anything.
 *
 * The token is stored HASHED, like a password reset token, so a leaked
 * database row is not a working change-of-address link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email');

            // sha256 hex, 64 chars. Hashed rather than stored in clear for the
            // same reason password_reset_tokens are.
            $table->string('pending_email_token', 64)->nullable()->after('pending_email');

            // What the expiry is measured from, and what the once-an-hour
            // resend throttle reads. The signed URL carries its own expiry too;
            // this is the half that survives somebody re-sending the mail.
            $table->timestamp('pending_email_sent_at')->nullable()->after('pending_email_token');
        });

        /*
         * Lookups go through the primary key, so no index on pending_email -
         * the confirmation link carries the user id. The column is only ever
         * read for one row that is already in hand.
         */
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_email', 'pending_email_token', 'pending_email_sent_at']);
        });
    }
};
