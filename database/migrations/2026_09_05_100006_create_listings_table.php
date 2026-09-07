<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();          // public identifier in URLs
            $table->string('slug');                  // SEO segment, not unique alone
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 32);          // denormalised: uncatalogued items still filter
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->text('description');
            $table->string('condition', 24);         // new | like_new | used | for_parts
            $table->unsignedSmallInteger('quantity')->default(1);

            // MONEY IS ALWAYS INTEGER CENTS. Never float, never decimal-as-string.
            $table->unsignedInteger('price_cents');
            $table->boolean('offers_enabled')->default(true);
            // The anti-lowball mechanism: offers below this are auto-declined
            // and the seller never sees them. Private to the seller.
            $table->unsignedInteger('min_offer_cents')->nullable();

            // --- category trust signals -----------------------------------
            $table->date('warranty_until')->nullable();
            $table->boolean('has_receipt')->default(false);
            $table->string('mining_use', 12)->default('unknown');  // no | yes | unknown
            // Only shown when mining_use = 'yes'. Conditional, so the ~95%
            // answering "no" never see the question.
            $table->unsignedSmallInteger('mining_months')->nullable();
            $table->string('validation_url')->nullable();          // 3DMark / CPU-Z validation
            $table->string('serial_hash', 64)->nullable();         // stolen-hardware registry
            $table->boolean('accepts_inspect_test')->default(true);// Еконт "преглед и тест"

            $table->jsonb('specs')->default('{}');           // actual unit's attributes
            $table->jsonb('delivery_options')->default('[]');// econt | speedy | pickup

            $table->string('status', 24)->default('draft');
            // draft | pending_review | active | reserved | sold | expired | removed

            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('favorite_count')->default(0);
            $table->unsignedInteger('offer_count')->default(0);
            $table->unsignedInteger('contact_count')->default(0);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('bumped_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'bumped_at']);
            $table->index(['category', 'status', 'price_cents']);
            $table->index(['part_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_condition_check
                       CHECK (condition IN ('new','like_new','used','for_parts'))");
        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_status_check
                       CHECK (status IN ('draft','pending_review','active','reserved','sold','expired','removed'))");
        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_mining_check
                       CHECK (mining_use IN ('no','yes','unknown'))");
        // Mining duration is meaningless unless mining is declared.
        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_mining_months_check
                       CHECK (mining_months IS NULL OR mining_use = 'yes')");
        // A floor above the asking price is nonsense; catch it in the database.
        DB::statement('ALTER TABLE listings ADD CONSTRAINT listings_min_offer_check
                       CHECK (min_offer_cents IS NULL OR min_offer_cents <= price_cents)');

        DB::statement('ALTER TABLE listings ADD COLUMN search_vector tsvector');
        DB::statement('CREATE INDEX listings_search_idx ON listings USING GIN (search_vector)');
        DB::statement('CREATE INDEX listings_specs_idx  ON listings USING GIN (specs jsonb_path_ops)');
        // Only active rows are ever browsed - keep the hot index small.
        DB::statement("CREATE INDEX listings_active_idx ON listings (bumped_at DESC)
                       WHERE status = 'active' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
