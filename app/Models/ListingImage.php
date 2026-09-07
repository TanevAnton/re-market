<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ListingImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'listing_id', 'path', 'width', 'height', 'bytes',
        'phash', 'is_timestamp_photo', 'position',
    ];

    protected function casts(): array
    {
        return ['is_timestamp_photo' => 'boolean'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /**
     * ImageProcessor writes the pair "<uuid>.jpg" and "<uuid>_t.jpg" side by
     * side, so the thumbnail is derivable rather than a second column. Falls
     * back to the full image if the thumb was never written.
     */
    public function thumbUrl(): string
    {
        return Storage::disk('public')->url(
            preg_replace('/\.jpg$/', '_t.jpg', $this->path) ?? $this->path
        );
    }

    /**
     * Hamming distance between two 64-bit perceptual hashes. Under ~8 means
     * the same photograph, which on a marketplace means someone has taken
     * another seller's picture. It is the strongest scam signal we get.
     */
    public static function hammingDistance(int $a, int $b): int
    {
        return substr_count(decbin($a ^ $b), '1');
    }

    public function looksLike(int $otherPhash): bool
    {
        return $this->phash !== null
            && self::hammingDistance($this->phash, $otherPhash)
               <= config('remarket.phash_distance_threshold', 8);
    }
}
