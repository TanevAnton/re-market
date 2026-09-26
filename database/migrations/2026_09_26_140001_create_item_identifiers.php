<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serial numbers and IMEIs — and the thing this site must not become.
 *
 * WHY THIS EXISTS. The question a cautious buyer of a €600 graphics card or a
 * used iPhone actually asks is „is this stolen", and nothing on any Bulgarian
 * classifieds site answers it. This does not answer it either — nobody can,
 * because the police hold no public register anyone may query — but it answers
 * a narrower and still useful question: has anybody reported THIS serial as
 * taken from them, and is the same serial on another listing right now.
 *
 * THE SERIALS ARE STORED HASHED, AND THAT IS THE WHOLE DESIGN CONSTRAINT.
 *
 * A table of plaintext serials on a hardware marketplace is two dangerous
 * things at once. It is a shopping list for anyone who breaks in — a verified
 * catalogue of valuable items and where they were last seen — and it is a
 * forgery kit, because a real serial pasted into a fake listing is exactly how
 * a scam listing passes a careful buyer's check. So the column holds
 * HMAC-SHA256 with a server-side pepper that lives in `.env` and never in the
 * database. A dump of this table on its own is inert.
 *
 * THE PEPPER CAN NEVER CHANGE. Rotate it and every hash here becomes
 * unmatchable against anything anyone types afterwards — the register silently
 * stops working while continuing to look full. `App\Support\ItemIdentifier`
 * says so again, and `php artisan remarket:doctor` refuses to call the feature
 * healthy without one.
 *
 * WHY `last4` IS STORED IN CLEAR, which is a deliberate concession rather than
 * an oversight. Without it the field is a black box: a seller who mistyped
 * cannot tell, and a moderator holding a police report cannot see whether the
 * claim in front of them is about this object at all. Four characters of an
 * IMEI whose last digit is a Luhn check leak roughly three digits, and the
 * hash is peppered, so the brute-force position does not change. The usability
 * is real and the cost is small — but it IS a cost, and the next person should
 * know it was weighed rather than missed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_identifiers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();

            // 'serial' or 'imei'. Kept apart because they are validated
            // differently — an IMEI has a checksum and a serial does not — and
            // because a serial that happens to equal an IMEI is not a match.
            $table->string('kind', 12);

            // HMAC-SHA256, hex. Never the serial itself.
            $table->char('hash', 64);
            $table->char('last4', 4)->nullable();

            $table->timestamps();

            /*
             * One identifier of each kind per listing. A seller with two
             * graphics cards posts two listings — that is what `quantity` is
             * not for, and a listing carrying two serials cannot be matched
             * against anything unambiguously.
             */
            $table->unique(['listing_id', 'kind']);

            // The two questions asked of this table: „is this serial anywhere"
            // and „is it on more than one live listing".
            $table->index(['hash', 'kind']);
        });

        Schema::create('stolen_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('kind', 12);
            $table->char('hash', 64);
            $table->char('last4', 4)->nullable();

            /*
             * Open to people with no account, like the DSA notice form: the
             * victim of a theft is by definition not necessarily a user here,
             * and requiring signup would drop the reports worth the most.
             */
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_email', 255);

            /*
             * THE POLICE REFERENCE IS REQUIRED, and it is the only thing
             * standing between this feature and a sabotage tool. A „stolen"
             * claim against a serial takes a competitor's listing off the site;
             * asking for the number of an actual filed report means the person
             * making the claim has put their name to it somewhere that is not
             * here. It is NOT verified — no public register exists to check it
             * against — and nothing in this codebase may ever imply otherwise.
             */
            $table->string('police_ref', 120);
            $table->text('detail');

            // pending → confirmed | rejected. Only `confirmed` is the register.
            $table->string('status', 20)->default('pending');

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->timestamps();

            // „Is there a confirmed claim against this serial?" — the only
            // question the rest of the site asks.
            $table->index(['hash', 'kind', 'status']);
            // The admin queue: what is waiting, oldest first.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stolen_reports');
        Schema::dropIfExists('item_identifiers');
    }
};
