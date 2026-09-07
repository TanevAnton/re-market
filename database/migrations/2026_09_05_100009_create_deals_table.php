<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Created when a seller accepts an offer. We never touch the money: the deal
 * record exists to open a chat thread, unlock ratings, and measure the one
 * metric that matters - accepted -> completed.
 *
 * DECISION PENDING (plan section 8.4): agreed_price_cents makes the transaction
 * amount "reasonably knowable", which weakens the DAC7 advertising carve-out.
 * The band columns exist as the safer alternative. Confirm with a lawyer before
 * Phase 3 ships, then drop whichever pair you do not use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('agreed_price_cents')->nullable();
            $table->unsignedInteger('price_band_low_cents')->nullable();
            $table->unsignedInteger('price_band_high_cents')->nullable();

            $table->string('status', 24)->default('open');
            // open | completed | cancelled | abandoned | disputed

            $table->string('courier', 16)->nullable();          // econt | speedy | pickup
            $table->boolean('inspect_test_selected')->default(false);
            $table->string('tracking_number')->nullable();

            $table->timestamp('buyer_confirmed_at')->nullable();
            $table->timestamp('seller_confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');                    // 72h reservation window
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['buyer_id', 'status']);
            $table->index(['seller_id', 'status']);
        });

        DB::statement("ALTER TABLE deals ADD CONSTRAINT deals_status_check
                       CHECK (status IN ('open','completed','cancelled','abandoned','disputed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
