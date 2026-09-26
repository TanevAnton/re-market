<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the parcel goes, and the number that says it is moving.
 *
 * WHAT WAS ALREADY HERE AND DEAD. `deals.courier`, `deals.inspect_test_selected`
 * and `deals.tracking_number` were created on 5 Sep with the table. Nothing has
 * ever written them and nothing has ever read them — three columns holding the
 * shape of a feature that was never built. Same family as the wizard field that
 * asked „Име на модела", validated it and dropped it. They are wired up now
 * rather than replaced, because the shape was right.
 *
 * WHY RIGO STORES AN ADDRESS AT ALL, having gone out of its way not to. The
 * seller cannot write a waybill without a recipient, an address or office, and a
 * phone. Until now those were exchanged in the deal chat, where contact scrubbing
 * lifts once a deal exists — which works, and produces a seller copying five
 * fields out of a conversation into a courier form at eleven at night. The whole
 * feature is those five fields in one block, in the order the courier asks for
 * them, and that needs them stored.
 *
 * `delivery_phone` IS THE UNCOMFORTABLE ONE, and it is a deliberate exception to
 * a rule this codebase otherwise keeps strictly. `users.phone_hash` exists
 * precisely so the site never holds a plaintext number: verification stores an
 * HMAC and `phone_last4` for display, and there is nothing to hand the seller
 * even if we wanted to. So the buyer types a delivery number HERE, for THIS
 * parcel, and it is:
 *
 *   - visible to the seller of this deal and to nobody else;
 *   - never copied onto the user record, so it does not become the account's
 *     phone number by the back door;
 *   - erased by `remarket:purge-delivery-details` once the deal has been finished
 *     long enough that nobody needs to reprint a label.
 *
 * A delivery address kept forever is a list of where everyone who ever bought a
 * graphics card lives. The purge is the feature, not the tidying-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            /*
             * office | address — and it is an explicit choice rather than „is
             * delivery_office null?", because the two produce different waybills
             * and a buyer who typed both should not have the shape of their
             * delivery decided by which column happened to be filled.
             */
            $table->string('delivery_kind', 16)->nullable();

            // Typed by the buyer, not picked from a list: RIGO holds no copy of
            // either courier's office register, and an office list that is six
            // months stale sends a parcel to a counter that has moved.
            $table->string('delivery_office')->nullable();

            // The city is ours and canonical, so the one part of the address that
            // can be structured, is.
            $table->foreignId('delivery_city_id')->nullable()
                ->constrained('cities')->nullOnDelete();

            $table->string('delivery_address')->nullable();
            $table->string('delivery_name')->nullable();
            $table->string('delivery_phone', 32)->nullable();

            // „Звънни преди да пратиш", „офисът е до аптеката". Free text,
            // because the useful version of this is always the sentence the
            // buyer would have typed in the chat anyway.
            $table->string('delivery_note', 255)->nullable();

            $table->timestamp('delivery_set_at')->nullable();
            $table->timestamp('tracking_set_at')->nullable();

            /*
             * Stamped when the personal fields are wiped, so the sweep is
             * idempotent and a deal can still SAY „данните за доставка са
             * изтрити" rather than silently rendering blanks that look like the
             * buyer never filled them in.
             */
            $table->timestamp('delivery_purged_at')->nullable();

            // The purge sweep's only query: finished deals that still hold
            // personal data. Partial, because the rows it wants are a shrinking
            // minority of the table and the index should not carry the rest.
            $table->index(['status', 'delivery_set_at'], 'deals_delivery_purge_index');
        });

        DB::statement("ALTER TABLE deals ADD CONSTRAINT deals_delivery_kind_check
                       CHECK (delivery_kind IS NULL OR delivery_kind IN ('office','address'))");

        /*
         * The courier column has been unconstrained and unwritten since day one.
         * Now that something writes it, the database gets an opinion about it —
         * same shape as `deals_status_check` above it and
         * `users_trader_status_check`.
         */
        DB::statement("ALTER TABLE deals ADD CONSTRAINT deals_courier_check
                       CHECK (courier IS NULL OR courier IN ('econt','speedy','pickup'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_delivery_kind_check');
        DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_courier_check');

        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex('deals_delivery_purge_index');
            $table->dropConstrainedForeignId('delivery_city_id');
            $table->dropColumn([
                'delivery_kind', 'delivery_office', 'delivery_address',
                'delivery_name', 'delivery_phone', 'delivery_note',
                'delivery_set_at', 'tracking_set_at', 'delivery_purged_at',
            ]);
        });
    }
};
