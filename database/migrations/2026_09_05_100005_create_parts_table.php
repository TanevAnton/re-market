<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical hardware catalogue. This is the difference between us and OLX:
 * a listing points at a Part, so "GPUs under 300 mm with 12 GB" is a query
 * rather than a wish. Everything filterable lives in specs (jsonb) under a
 * schema defined per category in config/catalog.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->string('category', 32);           // gpu | cpu | motherboard | ...
            $table->string('manufacturer');           // NVIDIA, AMD, ASUS, ...
            $table->string('model');                  // GeForce RTX 4070 Ti SUPER
            $table->string('variant')->nullable();    // ASUS TUF Gaming OC
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('launch_year')->nullable();
            $table->unsignedInteger('msrp_cents')->nullable();

            // Typed, filterable attributes. Shape depends on category.
            $table->jsonb('specs')->default('{}');

            // Every way a Bulgarian might type this: Cyrillic, Latin,
            // shlyokavitsa, slang, abbreviations. This table is the search UX.
            $table->jsonb('aliases')->default('[]');

            $table->string('image_path')->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('listings_count')->default(0);
            $table->unsignedInteger('active_listings_count')->default(0);

            // Rolling market data, recomputed nightly. Powers the part landing
            // pages that are our whole SEO strategy.
            $table->unsignedInteger('price_p25_cents')->nullable();
            $table->unsignedInteger('price_median_cents')->nullable();
            $table->unsignedInteger('price_p75_cents')->nullable();
            $table->timestamp('price_stats_at')->nullable();

            $table->timestamps();

            $table->index(['category', 'manufacturer']);
            $table->index('active_listings_count');
            $table->unique(['manufacturer', 'model', 'variant'], 'parts_identity_unique');
        });

        DB::statement('ALTER TABLE parts ADD COLUMN search_vector tsvector');
        DB::statement('CREATE INDEX parts_search_idx   ON parts USING GIN (search_vector)');
        DB::statement('CREATE INDEX parts_specs_idx    ON parts USING GIN (specs jsonb_path_ops)');
        DB::statement('CREATE INDEX parts_aliases_idx  ON parts USING GIN (aliases jsonb_path_ops)');
        DB::statement("CREATE INDEX parts_model_trgm_idx ON parts USING GIN (model gin_trgm_ops)");
    }

    public function down(): void
    {
        Schema::dropIfExists('parts');
    }
};
