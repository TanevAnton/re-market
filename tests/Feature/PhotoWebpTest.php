<?php

namespace Tests\Feature;

use App\Models\ListingImage;
use App\Services\Images\ImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WebP copies of listing photographs.
 *
 * The JPEG stays the photograph of record - it is the fallback, the og:image,
 * and what every older record points at. What is worth guarding is that the
 * WebP copy never becomes a way to show somebody a broken image: pages offer it
 * only where the file exists, and it goes when the photograph goes.
 *
 * Skipped, not failed, on a PHP whose GD cannot write WebP: the site then
 * serves JPEG exactly as it did, which is the designed behaviour.
 */
class PhotoWebpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function needsWebp(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('This PHP has no imagewebp().');
        }
    }

    private function jpegBytes(): string
    {
        $im = imagecreatetruecolor(64, 48);

        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }

    public function test_the_twin_path_is_the_same_name_with_a_webp_extension(): void
    {
        $this->assertSame('listings/abc.webp', ImageProcessor::webpPath('listings/abc.jpg'));
        $this->assertSame('listings/abc_t.webp', ImageProcessor::webpPath('listings/abc_t.jpg'));
        $this->assertSame('listings/abc.webp', ImageProcessor::webpPath('listings/abc.JPEG'));
    }

    public function test_an_upload_writes_a_webp_copy_of_the_photo_and_of_the_thumbnail(): void
    {
        $this->needsWebp();

        $stored = (new ImageProcessor())->store(UploadedFile::fake()->image('a.jpg', 900, 700));

        $disk = Storage::disk('public');

        $disk->assertExists($stored['path']);
        $disk->assertExists($stored['thumb']);
        $disk->assertExists(ImageProcessor::webpPath($stored['path']));
        $disk->assertExists(ImageProcessor::webpPath($stored['thumb']));

        // A real WebP file, not a renamed JPEG.
        $this->assertStringStartsWith('RIFF', (string) $disk->get(ImageProcessor::webpPath($stored['path'])));
    }

    public function test_switching_it_off_writes_only_the_jpeg(): void
    {
        $this->needsWebp();

        config(['remarket.images.webp' => false]);

        $stored = (new ImageProcessor())->store(UploadedFile::fake()->image('a.jpg', 400, 300));

        Storage::disk('public')->assertExists($stored['path']);
        Storage::disk('public')->assertMissing(ImageProcessor::webpPath($stored['path']));
    }

    public function test_deleting_a_photo_deletes_its_webp_copies_too(): void
    {
        Storage::disk('public')->put('listings/x.jpg', 'j');
        Storage::disk('public')->put('listings/x_t.jpg', 'j');
        Storage::disk('public')->put('listings/x.webp', 'w');
        Storage::disk('public')->put('listings/x_t.webp', 'w');

        (new ImageProcessor())->delete('listings/x.jpg', 'listings/x_t.jpg');

        Storage::disk('public')->assertMissing([
            'listings/x.jpg', 'listings/x_t.jpg', 'listings/x.webp', 'listings/x_t.webp',
        ]);
    }

    // --- the backfill -----------------------------------------------------

    public function test_the_backfill_writes_what_is_missing_and_is_safe_to_repeat(): void
    {
        $this->needsWebp();

        Storage::disk('public')->put('listings/old.jpg', $this->jpegBytes());
        Storage::disk('public')->put('listings/old_t.jpg', $this->jpegBytes());

        $processor = new ImageProcessor();

        $this->assertSame(2, $processor->backfillWebp('listings/old.jpg'));
        Storage::disk('public')->assertExists(['listings/old.webp', 'listings/old_t.webp']);

        $this->assertSame(0, $processor->backfillWebp('listings/old.jpg'));
    }

    public function test_the_backfill_skips_a_photo_whose_file_is_gone(): void
    {
        $this->needsWebp();

        $this->assertSame(0, (new ImageProcessor())->backfillWebp('listings/never-existed.jpg'));
    }

    // --- what a page is offered -------------------------------------------

    public function test_a_photo_with_a_twin_is_offered_as_picture_with_the_jpeg_as_fallback(): void
    {
        $this->needsWebp();

        $image = new ListingImage(['path' => 'listings/x.jpg']);

        Storage::disk('public')->put('listings/x.jpg', 'j');
        Storage::disk('public')->put('listings/x.webp', 'w');

        $html = view('partials.photo', ['image' => $image, 'thumb' => false, 'alt' => 'Карта'])->render();

        $this->assertStringContainsString('<picture', $html);
        $this->assertStringContainsString('type="image/webp"', $html);
        $this->assertStringContainsString('srcset="'.$image->webpUrl().'"', $html);
        // The <img> keeps the JPEG, so anything that does not understand
        // <picture> sees exactly what it always saw.
        $this->assertStringContainsString('src="'.$image->url().'"', $html);
    }

    public function test_a_photo_without_a_twin_is_a_plain_image(): void
    {
        $image = new ListingImage(['path' => 'listings/old.jpg']);

        Storage::disk('public')->put('listings/old.jpg', 'j');

        $html = view('partials.photo', ['image' => $image, 'thumb' => false, 'alt' => 'Карта'])->render();

        $this->assertStringNotContainsString('<picture', $html);
        $this->assertStringContainsString('src="'.$image->url().'"', $html);
        $this->assertNull($image->webpUrl());
    }

    public function test_the_thumbnail_looks_for_the_thumbnails_twin_not_the_photos(): void
    {
        $this->needsWebp();

        $image = new ListingImage(['path' => 'listings/x.jpg']);

        // Only the FULL-size twin exists. The grid card must not borrow it.
        Storage::disk('public')->put('listings/x.webp', 'w');

        $this->assertNull($image->thumbWebpUrl());
        $this->assertNotNull($image->webpUrl());

        Storage::disk('public')->put('listings/x_t.webp', 'w');

        $this->assertNotNull($image->thumbWebpUrl());
    }

    public function test_the_first_photo_on_a_page_can_opt_out_of_lazy_loading(): void
    {
        $image = new ListingImage(['path' => 'listings/x.jpg']);

        $lazy  = view('partials.photo', ['image' => $image, 'thumb' => false])->render();
        $eager = view('partials.photo', ['image' => $image, 'thumb' => false, 'lazy' => false])->render();

        $this->assertStringContainsString('loading="lazy"', $lazy);
        $this->assertStringNotContainsString('loading="lazy"', $eager);
    }

    public function test_the_backfill_command_reports_and_succeeds(): void
    {
        $this->needsWebp();

        $this->artisan('images:webp')->assertSuccessful();
    }

    public function test_the_backfill_command_refuses_politely_when_webp_is_off(): void
    {
        config(['remarket.images.webp' => false]);

        $this->artisan('images:webp')->assertFailed();
    }
}
