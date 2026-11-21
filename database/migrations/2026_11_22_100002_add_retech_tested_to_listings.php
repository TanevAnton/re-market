<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Тествано от RE-Tech": a person at RE-Tech has switched this unit on and
 * tried it, on this date.
 *
 * Three columns and no table, because it is one fact about one listing:
 *
 *  - `retech_tested_at`  when. Null means not tested, and is the only test of
 *                        that the code makes.
 *  - `retech_test_note`  what was tried, in the tester's words ("3DMark 20
 *                        мин, 68 °C"). Optional, plain text, shown escaped.
 *  - `retech_tested_by`  which account set it. Not a foreign key on purpose: an
 *                        audit trail must outlive the account it names.
 *
 * None of these is fillable on the model. A seller who could set their own
 * „тествано от RE-Tech" would be forging the one claim on the site that is made
 * by somebody other than the seller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->timestamp('retech_tested_at')->nullable();
            $table->string('retech_test_note', 200)->nullable();
            $table->unsignedBigInteger('retech_tested_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['retech_tested_at', 'retech_test_note', 'retech_tested_by']);
        });
    }
};
