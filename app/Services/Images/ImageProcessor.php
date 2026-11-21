<?php

namespace App\Services\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
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
    private const WEBP_QUALITY = 80;

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
        return $this->storeFile($file->getRealPath(), $folder);
    }

    /**
     * The same pipeline, from a path already on disk.
     *
     * Extracted for the bulk importer, which reads a folder of photographs next
     * to a CSV and has no HTTP upload to wrap. The alternative was constructing
     * an UploadedFile around a local path in test mode, which works and is a
     * lie about where the bytes came from.
     *
     * @return array{path: string, thumb: string, width: int, height: int, bytes: int, phash: int}
     */
    public function storeFile(string $source, string $folder = 'listings'): array
    {
        $image = $this->manager->decodePath($source)
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

        // Best effort and never fatal: the JPEG pair above is the photograph,
        // the WebP pair is an optimisation that browsers are offered when it
        // exists. A server without WebP support, or an encoder that chokes on
        // one odd file, must not turn into a seller who cannot post.
        $this->writeWebp($full, $path);
        $this->writeWebp($thumbData, $thumb);

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

    /**
     * Whether this server can write WebP and the site is set to.
     *
     * `imagewebp` is the GD function; a PHP build without it would otherwise
     * fail inside the encoder, once per photo, with a message that says
     * nothing about WebP.
     */
    public static function webpEnabled(): bool
    {
        return (bool) config('remarket.images.webp', true)
            && function_exists('imagewebp');
    }

    /**
     * The WebP sibling of a stored JPEG: "<uuid>.jpg" -> "<uuid>.webp" and
     * "<uuid>_t.jpg" -> "<uuid>_t.webp". Derivable, like the thumbnail, so
     * there is no column to keep in step with the files.
     */
    public static function webpPath(string $jpegPath): string
    {
        return preg_replace('/\.jpe?g$/i', '.webp', $jpegPath) ?? $jpegPath;
    }

    /**
     * Write the WebP twin of a JPEG that is already stored, from its bytes.
     *
     * Public so the backfill command can use the same encoder settings as an
     * upload does. Returns false instead of throwing.
     */
    public function writeWebp(string $jpegBytes, string $jpegPath): bool
    {
        if (! self::webpEnabled()) {
            return false;
        }

        try {
            $webp = $this->manager->decodeBinary($jpegBytes)
                ->encode(new WebpEncoder(quality: self::WEBP_QUALITY))
                ->toString();

            return Storage::disk($this->disk)->put(self::webpPath($jpegPath), $webp);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Make the WebP twins of an existing photograph and its thumbnail.
     *
     * For the photographs uploaded before WebP existed. Skips what is there
     * already, so it can be run again after an interruption.
     *
     * @return int how many files were written
     */
    public function backfillWebp(string $jpegPath): int
    {
        $disk    = Storage::disk($this->disk);
        $written = 0;

        $thumb = preg_replace('/\.jpe?g$/i', '_t.jpg', $jpegPath) ?? $jpegPath;

        foreach (array_unique([$jpegPath, $thumb]) as $path) {
            if (! $disk->exists($path) || $disk->exists(self::webpPath($path))) {
                continue;
            }

            if ($this->writeWebp((string) $disk->get($path), $path)) {
                $written++;
            }
        }

        return $written;
    }

    public function delete(string ...$paths): void
    {
        $paths = array_filter($paths);

        // Take the WebP twins with them. Leaving them would be harmless to
        // the site and a slow leak on the disk, and a deleted photograph is
        // exactly the kind of file somebody expects to be gone.
        $twins = array_map(fn (string $p) => self::webpPath($p), $paths);

        Storage::disk($this->disk)->delete(array_values(array_unique([...$paths, ...$twins])));
    }

    public function url(string $path): string
    {
        return Storage::disk($this->disk)->url($path);
    }
}
