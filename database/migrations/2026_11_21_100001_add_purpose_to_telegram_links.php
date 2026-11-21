<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second reason to open the bot: „пиши ми тук".
 *
 * The handshake used to exist for one purpose only - proving a phone number -
 * so the only people the site could message on Telegram were people who had
 * verified one. Phone verification is switched off in production, which means
 * nobody new could ever connect, and saved-search alerts (the thing a buyer
 * most wants a fast channel for) fell back to e-mail for everyone.
 *
 * One column, not a second table: the nonce, the expiry, the single-use rule
 * and the deep link are the same for both. `purpose` says what pressing Start
 * is allowed to do - 'verify' goes on to ask for a contact, 'alerts' just
 * remembers the chat.
 *
 * Defaults to 'verify' so every link already in the table, and every caller
 * that does not know about this column, behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->string('purpose', 16)->default('verify')->after('nonce');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_links', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
