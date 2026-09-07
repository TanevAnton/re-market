<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DSA Art. 16 (notice and action) and Art. 17 (statement of reasons).
 * These are legal obligations that apply regardless of our size, so the
 * statement_of_reasons field is required on every restrictive decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                  // reference given to the reporter
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_email')->nullable();    // anonymous notices are allowed
            $table->morphs('reportable');                    // listing | user | message
            $table->string('reason', 32);
            // scam | stolen | counterfeit | wrong_category | prohibited |
            // offensive | spam | contact_in_listing | other
            $table->text('detail')->nullable();
            $table->string('evidence_url')->nullable();

            $table->string('status', 16)->default('open');   // open | actioned | rejected
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 32)->nullable();
            $table->text('statement_of_reasons')->nullable();// DSA Art. 17 - sent to the user
            $table->timestamp('acknowledged_at')->nullable();// DSA Art. 16 - receipt confirmation
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('moderation_items', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');                       // usually a listing
            $table->string('trigger', 32);
            // new_account | phash_collision | price_outlier | reported |
            // missing_timestamp_photo | contact_info | manual
            $table->unsignedTinyInteger('priority')->default(5);
            $table->jsonb('context')->default('{}');         // matched listing id, z-score, etc.

            $table->string('status', 16)->default('pending'); // pending | approved | rejected
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_items');
        Schema::dropIfExists('reports');
    }
};
