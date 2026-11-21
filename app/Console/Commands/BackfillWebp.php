<?php

namespace App\Console\Commands;

use App\Models\ListingImage;
use App\Services\Images\ImageProcessor;
use Illuminate\Console\Command;

/**
 * Make WebP copies of the photographs uploaded before WebP existed.
 *
 *   php artisan images:webp
 *
 * New uploads get their WebP twin at upload. This is for the rest, once, after
 * the deploy that introduced it. It is safe to run again - a twin that exists
 * is left alone - and safe to interrupt.
 */
class BackfillWebp extends Command
{
    protected $signature = 'images:webp {--chunk=200 : Photographs per batch}';

    protected $description = 'Write WebP copies of listing photographs that do not have one yet';

    public function handle(): int
    {
        if (! ImageProcessor::webpEnabled()) {
            $this->error('WebP is off (IMAGES_WEBP=false) or this PHP has no imagewebp(). Nothing to do.');

            return self::FAILURE;
        }

        $processor = new ImageProcessor();
        $files     = 0;
        $photos    = 0;

        ListingImage::query()
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($chunk) use ($processor, &$files, &$photos) {
                foreach ($chunk as $image) {
                    $photos++;
                    $files += $processor->backfillWebp($image->path);
                }
            });

        $this->info("{$photos} photographs checked, {$files} WebP files written.");

        return self::SUCCESS;
    }
}
