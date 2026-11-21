<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The photo gallery on a listing: swipe on the page, swipe and pinch-zoom in
 * the viewer.
 *
 * The gestures themselves are JavaScript and are exercised in a real browser
 * rather than here. What this file guards is what the SERVER decides: that the
 * viewer exists only when there is something to view, that the controls for
 * moving between photographs exist only when there is more than one, and that
 * the script the markup depends on is on the page - because `x-data` naming a
 * function that is not defined is a gallery that silently does nothing.
 */
class PhotoGalleryTest extends TestCase
{
    use RefreshDatabase;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->listing = Listing::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status'  => ListingStatus::Active,
        ]);
    }

    private function photos(int $count): void
    {
        foreach (range(0, $count - 1) as $position) {
            ListingImage::create([
                'listing_id' => $this->listing->id,
                'path'       => "listings/gallery-{$position}.jpg",
                'position'   => $position,
            ]);
        }
    }

    private function page(): string
    {
        return $this->get(route('listing', $this->listing))->assertOk()->getContent();
    }

    public function test_the_page_defines_the_function_its_markup_calls(): void
    {
        $this->photos(2);

        $html = $this->page();

        $this->assertStringContainsString('x-data="photoGallery(', $html);
        $this->assertStringContainsString('window.photoGallery', $html);
        // Defined BEFORE the markup that uses it, or Alpine reads it first.
        $this->assertLessThan(
            strpos($html, 'x-data="photoGallery('),
            strpos($html, 'window.photoGallery'),
        );
    }

    public function test_several_photos_get_thumbnails_and_viewer_controls(): void
    {
        $this->photos(3);

        $html = $this->page();

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertSame(3, substr_count($html, 'aria-label="Снимка '));
        $this->assertStringContainsString('aria-label="Следваща"', $html);
        $this->assertStringContainsString('aria-label="Предишна"', $html);
        $this->assertStringContainsString('aria-label="Увеличи"', $html);
        $this->assertStringContainsString('gallery-2.jpg', $html);
    }

    public function test_one_photo_has_a_viewer_but_nothing_to_page_through(): void
    {
        $this->photos(1);

        $html = $this->page();

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-label="Увеличи"', $html);
        $this->assertStringNotContainsString('aria-label="Следваща"', $html);
        $this->assertStringNotContainsString('aria-label="Снимка ', $html);
    }

    public function test_no_photos_means_no_viewer_at_all(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('Няма снимки', $html);
        $this->assertStringNotContainsString('role="dialog"', $html);
    }

    public function test_a_page_vertical_drag_is_left_to_the_browser(): void
    {
        $this->photos(2);

        // touch-pan-y is what lets the page still scroll when a finger starts
        // on the photograph; without it the swipe would eat every scroll.
        $this->assertStringContainsString('touch-pan-y', $this->page());
    }

    public function test_the_viewer_takes_every_gesture_itself(): void
    {
        $this->photos(2);

        $this->assertStringContainsString('touch-none', $this->page());
    }

    public function test_the_photo_list_the_script_reads_carries_each_photograph(): void
    {
        $this->photos(2);

        $html = $this->page();

        $this->assertStringContainsString('gallery-0.jpg', $html);
        $this->assertStringContainsString('gallery-1.jpg', $html);
    }

    public function test_a_webp_twin_is_offered_to_the_viewer_when_it_exists(): void
    {
        $this->photos(1);

        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('This PHP has no imagewebp().');
        }

        Storage::disk('public')->put('listings/gallery-0.webp', 'w');

        $html = $this->page();

        $this->assertStringContainsString('gallery-0.webp', $html);
        $this->assertStringContainsString('type="image/webp"', $html);
    }

    public function test_the_first_photo_is_in_the_page_before_any_script_runs(): void
    {
        $this->photos(2);

        // For crawlers, for the first paint, and for the visitor whose
        // JavaScript has not started yet.
        $this->assertMatchesRegularExpression(
            '#<img src="[^"]*gallery-0\.jpg"#',
            $this->page(),
        );
    }
}
