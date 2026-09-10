<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('path');                       // Bunny.net storage key
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedInteger('bytes')->nullable();

            // 64-bit perceptual hash. Stored signed because Postgres has no
            // unsigned bigint; compare with Hamming distance, not equality.
            // A collision against another user's image is the single strongest
            // scam signal we have.
            $table->bigInteger('phash')->nullable();

            // The r/hardwareswap convention: item photographed next to a
            // handwritten note with username and date. Optional - see
            // config('remarket.listings.require_timestamp_photo_for_private'),
            // which now defaults to false because requiring it cost more
            // listings than it caught scams. Still the strongest single signal
            // that a seller physically has the item, so it is still marked,
            // badged and weighed in review.
            $table->boolean('is_timestamp_photo')->default(false);

            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->index(['listing_id', 'position']);
            $table->index('phash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_images');
    }
};
