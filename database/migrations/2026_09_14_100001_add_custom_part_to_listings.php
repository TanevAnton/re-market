<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the seller typed when the catalogue did not have their model.
 *
 * The wizard has asked „Име на модела" since it was built, validated the
 * answer, and then dropped it: `publish()` wrote `part_id => null` and nothing
 * else. So the single most valuable signal the site produces - a seller telling
 * us, unprompted, exactly which piece of hardware the catalogue is missing -
 * never reached the database at all.
 *
 * That is the difference between a catalogue that grows when somebody edits a
 * seeder and one that grows when somebody posts a listing.
 *
 * Kept as free text on purpose, verbatim, exactly as typed. Normalising on the
 * way in would throw away the thing the admin screen is for: „RTX 4070",
 * „rtx4070" and „РТХ 4070" are three facts about how Bulgarians write this
 * model, and every one of them should end up as a search alias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('custom_part', 120)->nullable()->after('part_id');
        });

        /*
         * The promotion queue's only query: uncatalogued listings that named
         * something. Partial, because the rows it excludes are the overwhelming
         * majority once the catalogue is doing its job - which is the point of
         * the whole feature.
         */
        DB::statement('
            CREATE INDEX listings_custom_part_idx ON listings (custom_part)
             WHERE part_id IS NULL AND custom_part IS NOT NULL AND deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS listings_custom_part_idx');

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('custom_part');
        });
    }
};
