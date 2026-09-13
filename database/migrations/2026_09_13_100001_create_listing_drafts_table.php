<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unfinished wizard state, so abandoning step 3 stops costing a listing.
 *
 * A separate table rather than a half-filled `listings` row, which is the
 * obvious alternative and wrong for four reasons:
 *
 * 1. `CreateListing::publish()` gates new-account moderation on
 *    `$user->listings()->count()`. Drafts as listings inflate that count, so a
 *    spammer opens two wizards, abandons them, and their first real ad skips
 *    review. That is the highest-value anti-spam measure on the site, defeated
 *    by a feature that looks unrelated to it.
 * 2. `remarket:repair-drafts` identifies listings stranded by the old
 *    mass-assignment bug as "status = draft with photos". Real drafts would
 *    make that heuristic meaningless.
 * 3. `listings.title` and `listings.price_cents` are NOT NULL, and the model's
 *    `saving` hook slugs the title. A partially filled row needs either
 *    placeholder values or a weaker schema on the table that matters.
 * 4. Every query that lists, counts or screens listings would have to remember
 *    to exclude them. One that forgets is silent.
 *
 * A draft is wizard state, not a listing. Different shape, different lifetime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_drafts', function (Blueprint $table) {
            $table->id();

            // One draft per seller: "continue where you left off" has exactly
            // one answer, and a list of half-written ads is a second inbox
            // nobody asked for. Unique so updateOrCreate cannot race into two.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->jsonb('payload');
            $table->unsignedTinyInteger('step')->default(1);

            // Denormalised out of the payload purely so the resume prompt can
            // say what the draft is without decoding it, and so prune can
            // report something readable.
            $table->string('title')->nullable();
            $table->string('category', 32)->nullable();

            $table->timestamps();

            // prune walks these by age.
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_drafts');
    }
};
