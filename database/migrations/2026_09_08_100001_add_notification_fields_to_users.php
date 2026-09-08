<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where to reach a user, and whether they want to be reached.
 *
 * The chat id was already known - the bot learns it the moment someone
 * verifies - and was being thrown away with the consumed link. Keeping it on
 * the user turns the verification bot into a notification channel that is free
 * at any volume and needs no new credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Telegram chat ids are 64-bit; they are not phone numbers and
            // carry nothing about the person beyond "this conversation".
            $table->bigInteger('telegram_chat_id')->nullable()->after('phone_country');

            /*
             * Both default true. A marketplace where the seller is not told an
             * offer arrived is a marketplace where offers expire - the whole
             * 48-hour window assumes someone knows the clock is running.
             * Opting out stays one checkbox away.
             */
            $table->boolean('notify_email')->default(true);
            $table->boolean('notify_telegram')->default(true);

            $table->index('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['telegram_chat_id']);
            $table->dropColumn(['telegram_chat_id', 'notify_email', 'notify_telegram']);
        });
    }
};
