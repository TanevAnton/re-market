<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Търся" — the other half of a marketplace.
 *
 * WHAT THIS IS FOR, because it is not obvious from the table. A marketplace
 * with too few listings loses the buyer who found nothing, permanently and
 * silently: they look once, see an empty category, and never come back. There
 * is no way to know it happened. A wanted ad is the only thing that turns that
 * departure into a signal — the buyer leaves a trace, and a seller with the
 * card in a drawer has a reason to list it.
 *
 * So this table is aimed at the problem the site actually has (supply), not at
 * a feature gap. Everything about its shape follows from that: it is public so
 * a dealer can read demand without an account and Google can index it, and a
 * response has to be a REAL LISTING rather than a message.
 *
 * WHY A RESPONSE IS A LISTING AND NOT A MESSAGE. „Имам такова, пиши ми на
 * Вайбър" is the exact thing this site exists not to be, and a free-text reply
 * box is how it gets back in. Requiring a listing that already passed
 * moderation means a spammer has to do the work of being a real seller first —
 * and the buyer gets photos, specs, a price and a rating instead of a promise.
 *
 * WHY NOT REUSE `offers`. An offer is a buyer bidding on a seller's listing.
 * This is a seller proposing a listing to a buyer — the opposite direction,
 * with no amount of its own and no accept/counter ladder. Forcing it through
 * the same table would mean every offer query growing a „but not that kind"
 * clause, which is how both features get slower and harder to reason about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wanted_ads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('category', 32);

            /*
             * The catalogue model, when the buyer picked one. Nullable on
             * purpose: „търся видеокарта до 300 €, каквато и да е" is a real
             * and useful request, and forcing a model would turn away the
             * buyer who does not know which one they want yet — who is
             * precisely the person a seller can help.
             */
            $table->foreignId('part_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 160);
            $table->text('detail')->nullable();

            /*
             * A CEILING, not a price. The buyer is saying „no more than this",
             * so a seller can tell at a glance whether it is worth responding —
             * which is the difference between a wanted ad that gets answers and
             * one that wastes everybody's time. Nullable: „колкото струва" is
             * also an honest position.
             */
            $table->integer('budget_max_cents')->nullable();

            // Null means anywhere in the country.
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('active');

            /*
             * Wanted ads go stale faster than listings — somebody who needed a
             * card in March has bought one by May, and an answered request
             * left open wastes a seller's time. Shorter default than a
             * listing's 60 days, and the buyer can close it themselves the
             * moment they are sorted.
             */
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();

            $table->unsignedInteger('view_count')->default(0);

            /*
             * When sellers were told about this ad. Stamped once, so a buyer
             * editing their wanted ad cannot re-notify the same sellers — the
             * cheapest possible way to turn a good feature into a mailing list.
             */
            $table->timestamp('matched_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The browse page: what is open, newest first.
            $table->index(['status', 'created_at']);
            // The reverse match, run when a listing is published.
            $table->index(['category', 'status']);
            $table->index(['part_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('wanted_responses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wanted_ad_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();

            // pending → dismissed. Nothing else: see WantedResponse.
            $table->string('status', 20)->default('pending');
            $table->timestamp('dismissed_at')->nullable();

            $table->timestamps();

            /*
             * One listing may be offered to one wanted ad ONCE. Without this a
             * seller can answer the same request with the same card every day,
             * and the buyer's screen becomes a feed of one person. Enforced by
             * the database rather than by a check, because the check is what
             * gets removed during a refactor.
             */
            $table->unique(['wanted_ad_id', 'listing_id']);

            $table->index(['wanted_ad_id', 'status']);
            $table->index(['seller_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wanted_responses');
        Schema::dropIfExists('wanted_ads');
    }
};
