<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name_bg');            // Горна Оряховица
            $table->string('name_en');            // Gorna Oryahovitsa
            $table->string('slug')->unique();     // gorna-oryahovitsa
            $table->string('region_bg');          // Велико Търново
            $table->string('region_en');
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->unsignedInteger('population')->default(0);
            $table->unsignedInteger('listings_count')->default(0);

            $table->index('region_bg');
            $table->index('population');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
