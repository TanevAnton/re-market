<?php

namespace App\Services\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

/**
 * Listing photos: normalise, strip metadata, store, and fingerprint.
 *
 * Storage goes through the 'public' disk, so moving to Bunny.net later is a
 * config change rather than a rewrite - nothing here knows where the bytes
 * physically land.
 *
 * API NOTE: written against the Intervention Image version actually installed
 * in this project, whose manager exposes decodePath()/decodeBinary() rather
 * than the read() of older releases, grayscale() rather than greyscale(), and
 * colorAt() rather than pickColor(). Check these names before upgrading.
 */
class ImageProcessor
{
    private const MAX_EDGE = 1600;   // full view
    private const THUMB    = 480;    // grid card
    private const QUALITY  = 82;

    private ImageManager $manager;

    public function __construct(
        private readonly string $disk = 'public',
    ) {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * @return array{path: string, thumb: string, width: int, height: int, bytes: int, phash: int}
     */
    public function store(UploadedFile $file, string $folder = 'listings'): array
    {
        $image = $this->manager->decodePath($file->getRealPath())
            // EXIF orientation must be applied before metadata is dropped, or
            // phone photos land sideways.
            ->orient()
            ->scaleDown(width: self::MAX_EDGE, height: self::MAX_EDGE);

        // strip: true removes EXIF - including the GPS tag that would otherwise
        // publish a seller's home address with every photo of a graphics card.
        $encoded = $image->encode(new JpegEncoder(quality: self::QUALITY, strip: true));
        $full    = $encoded->toString();

        $name  = Str::uuid()->toString();
        $path  = "{$folder}/{$name}.jpg";
        $thumb = "{$folder}/{$name}_t.jpg";

        Storage::disk($this->disk)->put($path, $full);

        $thumbData = $this->manager->decodeBinary($full)
            ->cover(self::THUMB, (int) round(self::THUMB * 0.75))
            ->encode(new JpegEncoder(quality: self::QUALITY, strip: true))
            ->toString();

        Storage::disk($this->disk)->put($thumb, $thumbData);

        return [
            'path'   => $path,
            'thumb'  => $thumb,
            'width'  => $image->width(),
            'height' => $image->height(),
            'bytes'  => strlen($full),
            'phash'  => $this->differenceHash($full),
        ];
    }

    /**
     * 64-bit difference hash.
     *
     * Reduce to 9x8 greyscale and record, per row, whether each pixel is
     * brighter than the one to its right. That survives resizing, recompression
     * and mild colour shifts - which is exactly what happens when someone lifts
     * another seller's photo and reposts it.
     *
     * Compare with Hamming distance; under ~8 bits means the same photograph.
     */
    public function differenceHash(string $binary): int
    {
        $img = $this->manager->decodeBinary($binary)->grayscale()->resize(9, 8);

        $bits = 0;
        $i    = 0;

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 9; $x++) {
                if ($x === 8) {
                    continue;   // 9 columns give 8 comparisons per row
                }

                $left  = $img->colorAt($x, $y)->channels()[0]->value();
                $right = $img->colorAt($x + 1, $y)->channels()[0]->value();

                if ($left > $right) {
                    // Bit 63 overflows into the sign bit. That is fine: the
                    // column is a signed bigint, and XOR + popcount are
                    // unaffected by which end the sign lives at.
                    $bits |= (1 << $i);
                }

                $i++;
            }
        }

        return $bits;
    }

    public static function hammingDistance(int $a, int $b): int
    {
        return substr_count(decbin($a ^ $b), '1');
    }

    public function delete(string ...$paths): void
    {
        Storage::disk($this->disk)->delete(array_filter($paths));
    }

    public function url(string $path): string
    {
        return Storage::disk($this->disk)->url($path);
    }
}
