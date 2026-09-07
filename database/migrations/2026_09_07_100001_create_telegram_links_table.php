<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One pending "verify me through Telegram" handshake.
 *
 * The flow it tracks: the site shows a t.me deep link carrying a nonce, the
 * user opens it and presses Start, the bot receives that nonce and asks for
 * their contact, and Telegram - which verified the number when they signed up -
 * hands it over. We never send a code, so there is no code to intercept, guess,
 * or forward to someone else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What travels in the deep link. Single use, short lived, and the
            // only thing connecting a Telegram chat back to a logged-in user.
            $table->string('nonce', 64)->unique();

            // Learned when /start arrives; the contact message that follows
            // carries the same chat, and this is how the two are joined.
            $table->bigInteger('telegram_chat_id')->nullable()->index();

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_links');
    }
};
