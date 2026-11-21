<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a model is asked for on another site: one row per part per source.
 *
 * ONE ROW, OVERWRITTEN. Unlike `part_price_points` this is not a history. It is
 * a reference figure for the seller's price step, it is only ever the latest
 * one, and `captured_at` is what lets the guide stop showing it when it is old.
 *
 * AGGREGATES ONLY. There is deliberately nowhere in this table to put an ad's
 * title, text, address, photo or seller - the importer reads those once and
 * throws them away, and a column for them would be an invitation to keep them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_market_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();

            // 'olx' today; the column is here so a second source is a new row
            // rather than a new table.
            $table->string('source', 24);

            $table->unsignedInteger('p25_cents');
            $table->unsignedInteger('median_cents');
            $table->unsignedInteger('p75_cents');

            // A median over eight ads and over eighty are different claims.
            $table->unsignedInteger('sample_size');

            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['part_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_market_prices');
    }
};
