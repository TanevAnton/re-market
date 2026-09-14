<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strings the admin has judged not to be a model.
 *
 * „не знам", „компютър", „видеокарта", „както е на снимката" - sellers type all
 * of these into a field asking for a model name, and every one of them will sit
 * at the top of a queue sorted by frequency, because the uninformative answers
 * are exactly the ones many people give.
 *
 * Without somewhere to put that judgement the queue re-presents its own worst
 * rows on every visit, and a queue that cannot be cleared stops being worked -
 * the same failure the moderation queue is designed around.
 *
 * Keyed on the NORMALISED string, so dismissing „не знам" also dismisses
 * „Не Знам" and „не  знам". `sample` keeps one verbatim spelling, because the
 * key is squashed and unreadable on its own and the undo screen has to show a
 * human what they are looking at - the listings it came from may be long gone.
 *
 * Deliberately not resurfaced by volume. A dismissal says "this is not a
 * model", and that does not become false because ten more people typed it. A
 * mistake is recoverable instead: the hidden list shows each dismissed string
 * with its live count next to it, so a wrong call is visible rather than
 * permanent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_promotion_dismissals', function (Blueprint $table) {
            $table->id();
            $table->string('key', 160)->unique();
            $table->string('sample', 120);

            // Who decided, kept even if the account is later deleted: this is a
            // record of a judgement, and "nobody" is a worse answer than a
            // dangling id when somebody asks why a string is hidden.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_promotion_dismissals');
    }
};
