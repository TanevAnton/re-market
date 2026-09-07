<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The offer system is the product. The listing price is the only public number;
 * the ONLY way to propose a different one is a row in this table.
 *
 * Legal note (see plan section 8.4): offers are non-binding expressions of
 * interest. The T&Cs must say so, and no UI here may imply a concluded
 * contract - that is what keeps us outside DSA Art. 30 and DAC7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('amount_cents');
            $table->string('note', 200)->nullable();

            $table->string('status', 24)->default('pending');
            // pending | accepted | declined | auto_declined | countered | expired | withdrawn

            // Counter-offers form a chain rather than a new negotiation.
            $table->foreignId('parent_offer_id')->nullable()
                  ->constrained('offers')->nullOnDelete();
            $table->boolean('is_counter')->default(false);

            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['listing_id', 'status']);
            $table->index(['buyer_id', 'status']);
            $table->index(['seller_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_status_check
                       CHECK (status IN ('pending','accepted','declined','auto_declined','countered','expired','withdrawn'))");
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_amount_positive
                       CHECK (amount_cents > 0)');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_no_self_offer
                       CHECK (buyer_id <> seller_id)');

        // One live offer per buyer per listing. Enforced in the database, not
        // in application code, because this is where spam actually gets stopped.
        DB::statement("CREATE UNIQUE INDEX offers_one_active_per_buyer
                       ON offers (listing_id, buyer_id)
                       WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
