<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Моят компютър": what the user already owns, for the fit check.
 *
 * A small JSON object - socket, memory type, PSU wattage and so on - written
 * only through App\Support\MyPc, which drops anything it does not recognise.
 * Null is „not said".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('my_pc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('my_pc');
        });
    }
};
