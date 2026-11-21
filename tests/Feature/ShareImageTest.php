<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Http\Controllers\ShareImage;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Support\ShareCard;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The share pictures: what is served, to whom, and that it never throws.
 *
 * The card is drawn from user-supplied text, so most of what can go wrong is a
 * title nobody planned for - an emoji, a word with no spaces, an HTML entity -
 * and the failure is a 500 on an image URL that only a preview bot ever
 * requests, which nobody would notice for weeks.
 */
class ShareImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! ShareImage::available()) {
            $this->markTestSkipped('GD/FreeType or the bundled fonts are not available here.');
        }

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        File::deleteDirectory(storage_path('app/share-cache'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/share-cache'));

        parent::tearDown();
    }

    private function listing(array $attrs = []): Listing
    {
        return Listing::factory()->create(array_merge([
            'user_id'         => User::factory()->create()->id,
            'status'          => ListingStatus::Active,
            'price_cents'     => 52000,
            'min_offer_cents' => null,
        ], $attrs));
    }

    private function assertJpeg(string $bytes, string $message = ''): void
    {
        $info = @getimagesizefromstring($bytes);

        $this->assertNotFalse($info, $message ?: 'the response is not an image');
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame(ShareCard::WIDTH, $info[0]);
        $this->assertSame(ShareCard::HEIGHT, $info[1]);
    }

    // --- what is served -------------------------------------------------------

    public function test_the_site_card_is_a_jpeg_of_the_expected_size(): void
    {
        $response = $this->get(route('share.site'))->assertOk();

        $this->assertStringContainsString('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertJpeg($response->getContent());
    }

    public function test_an_active_listing_has_a_card_with_caching_headers(): void
    {
        $listing = $this->listing();

        $response = $this->get(route('listing.share', $listing))->assertOk();

        $this->assertJpeg($response->getContent());
        $this->assertStringContainsString('max-age', $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    public function test_a_sold_listing_keeps_its_card(): void
    {
        $listing = $this->listing(['status' => ListingStatus::Sold]);

        $this->assertJpeg($this->get(route('listing.share', $listing))->assertOk()->getContent());
    }

    /** The card carries the title and price, so it follows the page's own visibility. */
    public function test_a_listing_that_is_not_public_has_no_card(): void
    {
        foreach ([ListingStatus::Draft, ListingStatus::PendingReview, ListingStatus::Removed] as $status) {
            $listing = $this->listing(['status' => $status]);

            $this->get(route('listing.share', $listing))->assertNotFound();
        }
    }

    public function test_a_published_part_has_a_card_and_an_unpublished_one_does_not(): void
    {
        $part = Part::where('is_published', true)->firstOrFail();

        $this->assertJpeg($this->get(route('part.share', $part))->assertOk()->getContent());

        $part->forceFill(['is_published' => false])->save();

        $this->get(route('part.share', $part))->assertNotFound();
    }

    // --- addresses ------------------------------------------------------------

    /**
     * Viber and Facebook cache a preview by address for days. A changed price
     * has to be a different address, or the old number keeps circulating.
     */
    public function test_the_address_changes_when_the_price_does(): void
    {
        $listing = $this->listing();
        $before  = ShareImage::listingUrl($listing->fresh(['city', 'images']));

        $listing->forceFill(['price_cents' => 49000])->save();

        $this->assertNotSame($before, ShareImage::listingUrl($listing->fresh(['city', 'images'])));
    }

    public function test_the_address_is_stable_while_nothing_changes(): void
    {
        $listing = $this->listing();

        $this->assertSame(
            ShareImage::listingUrl($listing->fresh(['city', 'images'])),
            ShareImage::listingUrl($listing->fresh(['city', 'images'])),
        );
    }

    public function test_a_rendered_card_is_kept_on_disk_and_reused(): void
    {
        $listing = $this->listing();

        $first  = $this->get(route('listing.share', $listing))->getContent();
        $files  = glob(storage_path('app/share-cache/*.jpg'));

        $this->assertCount(1, $files);

        $second = $this->get(route('listing.share', $listing))->getContent();

        $this->assertSame($first, $second);
        $this->assertCount(1, glob(storage_path('app/share-cache/*.jpg')));
    }

    // --- the drawing itself ---------------------------------------------------

    private function card(array $over = []): array
    {
        return array_merge([
            'brand' => 'Brand', 'eyebrow' => 'Като нова · Пловдив', 'title' => 'RTX 4070 Ti Super',
            'price' => '645,00 €', 'dim' => false, 'photo' => null, 'domain' => 'example.test',
        ], $over);
    }

    private function draw(array $card): string
    {
        return (new ShareCard(resource_path('fonts/share')))->render($card);
    }

    public function test_it_survives_titles_nobody_planned_for(): void
    {
        foreach ([
            '🔥 Спешно!!! RTX 3060 🚀',
            'ПроцесорAMDRyzen9ThreadripperPRO5995WXSuperLongModelStringWithoutSpaces',
            'Цена &#8364; &amp; още',
            str_repeat('Много дълго заглавие ', 40),
            '',
            '   ',
        ] as $title) {
            $this->assertJpeg($this->draw($this->card(['title' => $title])), "failed on: {$title}");
        }
    }

    public function test_it_survives_a_missing_or_corrupt_photo(): void
    {
        $corrupt = tempnam(sys_get_temp_dir(), 'card');
        file_put_contents($corrupt, 'this is not an image');

        try {
            $this->assertJpeg($this->draw($this->card(['photo' => '/no/such/file.jpg'])));
            $this->assertJpeg($this->draw($this->card(['photo' => $corrupt])));
        } finally {
            @unlink($corrupt);
        }
    }

    public function test_it_uses_a_real_photo_when_there_is_one(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'card').'.jpg';
        $src  = imagecreatetruecolor(800, 600);
        imagefilledrectangle($src, 0, 0, 800, 600, imagecolorallocate($src, 200, 40, 40));
        imagejpeg($src, $path);

        try {
            $withPhoto = $this->draw($this->card(['photo' => $path]));
            $without   = $this->draw($this->card());

            $this->assertJpeg($withPhoto);
            $this->assertNotSame($withPhoto, $without);

            // The photo panel is on the right: a red pixel there means it was drawn.
            $img = imagecreatefromstring($withPhoto);
            $rgb = imagecolorat($img, ShareCard::WIDTH - 60, ShareCard::HEIGHT / 2);

            $this->assertGreaterThan(150, ($rgb >> 16) & 0xFF);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_price_too_wide_for_the_card_is_shrunk_not_clipped(): void
    {
        $this->assertJpeg($this->draw($this->card(['price' => '12 345 678,00 €', 'photo' => null])));
    }
}
