<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // --- identity -------------------------------------------------
            $table->uuid('uuid')->unique()->after('id');
            $table->string('username')->after('uuid');
            $table->string('avatar_path')->nullable();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('locale', 5)->default('bg');

            // --- phone verification --------------------------------------
            // We store an HMAC of the E.164 number, never the number itself.
            // The hash survives account deletion so a banned phone cannot
            // simply re-register. last4 exists only for display.
            $table->string('phone_hash', 64)->nullable()->unique();
            $table->string('phone_last4', 4)->nullable();
            $table->string('phone_country', 2)->nullable();
            $table->timestamp('phone_verified_at')->nullable();

            // --- seller classification (ЗЗП / Omnibus Art. 6a) ------------
            // Legally required: every listing must show whether the seller
            // declared themselves a trader or a private individual.
            $table->string('seller_type')->default('private');   // private | trader
            $table->jsonb('trader_details')->nullable();         // company, ЕИК, VAT no., address

            // --- reputation ----------------------------------------------
            $table->unsignedInteger('deals_completed')->default(0);
            $table->unsignedInteger('deals_abandoned')->default(0);   // accepted then ghosted
            $table->unsignedSmallInteger('rating_count')->default(0);
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('median_response_seconds')->nullable();
            $table->integer('trust_score')->default(0);

            // --- enforcement ---------------------------------------------
            $table->unsignedSmallInteger('daily_listing_limit')->default(2);
            $table->boolean('offers_suspended')->default(false);
            $table->timestamp('suspended_until')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->text('ban_reason')->nullable();
            $table->boolean('is_admin')->default(false);

            $table->timestamp('last_seen_at')->nullable();
            $table->softDeletes();

            $table->index('seller_type');
            $table->index('trust_score');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_seller_type_check
                       CHECK (seller_type IN ('private','trader'))");

        // Case-INSENSITIVE uniqueness. A plain unique index would happily allow
        // both "Ivan" and "ivan" to exist, which is an impersonation vector on
        // a marketplace where the username is the identity buyers trust.
        DB::statement('CREATE UNIQUE INDEX users_username_lower_unique ON users (lower(username))');
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique    ON users (lower(email))');

        // Fast partial-match lookup for the "find a seller" box.
        DB::statement('CREATE INDEX users_username_trgm_idx ON users USING GIN (username gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
            $table->dropColumn([
                'uuid','username','avatar_path','locale','phone_hash','phone_last4',
                'phone_country','phone_verified_at','seller_type','trader_details',
                'deals_completed','deals_abandoned','rating_count','rating_avg',
                'median_response_seconds','trust_score','daily_listing_limit',
                'offers_suspended','suspended_until','suspension_reason','banned_at',
                'ban_reason','is_admin','last_seen_at','deleted_at',
            ]);
        });
    }
};
