<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The seller's own reference for an item — a shop SKU, a stock number.
 *
 * It exists for exactly one reason: **a bulk import has to be safe to run
 * twice.** Two hundred rows will not all succeed the first time. A photo will
 * be missing, a category will be misspelled, the connection will drop at row
 * 140 — and the natural response is to fix the file and run it again. Without a
 * stable key from the source system, the second run has no way to tell "this is
 * the row I already created" from "this is a new listing", so it creates
 * another two hundred, and the only way back is deleting them by hand.
 *
 * UNIQUE PER USER, not globally. Two different shops can both call something
 * „GPU-0041" and neither is wrong; the reference means something only inside
 * the account that issued it.
 *
 * Partial, because almost every listing on the site will never have one - they
 * come from a person filling in the wizard, who has no stock system to
 * reference. NULLs are excluded from the index rather than colliding with each
 * other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('external_ref', 64)->nullable()->after('custom_part');
        });

        DB::statement('
            CREATE UNIQUE INDEX listings_external_ref_unique
                ON listings (user_id, external_ref)
             WHERE external_ref IS NOT NULL AND deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS listings_external_ref_unique');

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('external_ref');
        });
    }
};
