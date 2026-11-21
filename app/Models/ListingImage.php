<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\Images\ImageProcessor;
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
     * The WebP twin of the full photograph, or null where there is not one.
     *
     * Checked against the disk rather than a column: photographs uploaded
     * before WebP existed have no twin until `images:webp` has run, and a
     * `<picture>` that points at a file that is not there shows a broken
     * image to every browser that prefers WebP - which is nearly all of them.
     * A stat on the local disk costs nothing next to the page it is on; on a
     * remote disk this is the line to turn into a column.
     */
    public function webpUrl(): ?string
    {
        return $this->twinUrl($this->path);
    }

    public function thumbWebpUrl(): ?string
    {
        return $this->twinUrl(preg_replace('/\.jpg$/', '_t.jpg', $this->path) ?? $this->path);
    }

    private function twinUrl(string $jpegPath): ?string
    {
        if (! ImageProcessor::webpEnabled()) {
            return null;
        }

        $twin = ImageProcessor::webpPath($jpegPath);

        // No memo on purpose. A static one would outlive the request in a
        // queue worker or a test run, and answer for a file that has since
        // been written or deleted.
        return Storage::disk('public')->exists($twin)
            ? Storage::disk('public')->url($twin)
            : null;
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
