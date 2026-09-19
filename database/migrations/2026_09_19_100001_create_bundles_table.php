<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Продавам цялата конфигурация, или на части."
 *
 * A bundle is a GROUPING of listings, not a listing that contains them. That
 * distinction is the whole migration, and the features doc says it outright:
 * do not model a bundle as a `prebuilt` listing pointing at others, because
 * mark-sold, the offer floor and the moderation gate all assume a listing is
 * one item. A bundle that was a listing would inherit every one of those
 * assumptions and break each of them differently.
 *
 * So: its own table, and a nullable pointer on the listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundles', function (Blueprint $table) {
            $table->id();

            // uuid in the URL, like listings: the site never advertises how
            // many of these exist.
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title', 120);
            $table->text('description')->nullable();

            /*
             * NULLABLE, and that is a feature rather than laziness. „Ето ги
             * всичките части от машината" is useful with no package price at
             * all — the grouping itself is the information, and a seller who
             * has not decided on a discount should not be blocked from making
             * one.
             */
            $table->unsignedBigInteger('price_cents')->nullable();

            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });

        Schema::table('listings', function (Blueprint $table) {
            /*
             * nullOnDelete, NOT cascade. A bundle is a grouping; deleting it
             * must free its listings, never take them with it. Cascading here
             * would let one mistaken delete destroy eight separate listings
             * that were each perfectly saleable on their own.
             */
            $table->foreignId('bundle_id')
                ->nullable()
                ->after('part_id')
                ->constrained()
                ->nullOnDelete();

            $table->index('bundle_id');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('bundle_id');
        });

        Schema::dropIfExists('bundles');
    }
};
