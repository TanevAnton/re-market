<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a model cost, on each day it was possible to say.
 *
 * The one thing on the whole backlog that cannot be caught up later. Every
 * other feature can be built in a month and work immediately; this one only
 * ever knows what it was running for. A day that passes without a row is a day
 * that cannot be reconstructed from anything the site stores — `parts` keeps a
 * single current band and overwrites it every night at 04:10.
 *
 * ONE ROW PER PART PER DAY, and `captured_on` is a DATE rather than a timestamp
 * for exactly that reason: the nightly command must be safe to re-run — after a
 * failed deploy, by hand while debugging, twice because a timer fired twice —
 * and a timestamp would quietly turn each of those into a second point that
 * flattens or spikes the chart it feeds.
 *
 * `sample_size` is not decoration. A median over three listings and a median
 * over forty are different claims, and a year from now nothing else will
 * remember which one a given point was. It is what lets a reader — or a later
 * version of the chart — discount the early, thin part of the series instead of
 * drawing it with the same confidence as the rest.
 *
 * NO ROW when there is no band. The nightly command refuses to compute one
 * below the minimum sample and nulls it out above the staleness cut-off, and
 * inventing a point for those days would be inventing a price. A gap in the
 * series is the honest record of a model nobody was selling that week.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_price_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->date('captured_on');

            $table->unsignedInteger('p25_cents');
            $table->unsignedInteger('median_cents');
            $table->unsignedInteger('p75_cents');

            // How many live listings the figures above were computed from.
            $table->unsignedInteger('sample_size');

            // created_at only. A point is a fact about one day; there is no
            // such thing as editing it, and an updated_at would invite trying.
            $table->timestamp('created_at')->nullable();

            // The re-run guard. Also the index the chart reads: one part, in
            // date order, is the only query this table will ever serve.
            $table->unique(['part_id', 'captured_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_price_points');
    }
};
