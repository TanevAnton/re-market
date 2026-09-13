<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the people who shortlisted this listing were last told it got cheaper.
 *
 * Without it, a seller feeling out the market - 900, 880, 870, 850 in one
 * afternoon, which is exactly what somebody does when a card is not moving -
 * sends four messages to every person who favourited it. The percentage
 * threshold does not help: those steps are individually small but the fourth
 * message is what gets the whole channel muted.
 *
 * Same shape as the saved-search watermark and the unread-streak check: the
 * site's rule is one notification per episode, not one per event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->timestamp('price_drop_notified_at')->nullable()->after('bumped_at');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('price_drop_notified_at');
        });
    }
};
