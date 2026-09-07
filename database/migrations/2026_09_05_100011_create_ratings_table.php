<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rating requires a deal. There is no other way to create one, which makes
 * rating farming cost a real completed transaction rather than a click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rater_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ratee_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');            // 1..5
            $table->string('role', 8);                       // buyer | seller (of the ratee)
            $table->text('comment')->nullable();
            $table->text('reply')->nullable();               // one public reply allowed
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();

            $table->unique(['deal_id', 'rater_id']);
            $table->index(['ratee_id', 'created_at']);
        });

        DB::statement('ALTER TABLE ratings ADD CONSTRAINT ratings_score_range
                       CHECK (score BETWEEN 1 AND 5)');
        DB::statement('ALTER TABLE ratings ADD CONSTRAINT ratings_no_self_rating
                       CHECK (rater_id <> ratee_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
