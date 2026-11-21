<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Models\City;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Share buttons on a listing page.
 *
 * They are links and a click handler, so what can go wrong is quiet: the wrong
 * address being shared, or a third-party script sneaking onto the page.
 */
class ShareButtonsTest extends TestCase
{
    use RefreshDatabase;

    private function listing(): Listing
    {
        $this->seed(CitySeeder::class);

        return Listing::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status'  => ListingStatus::Active,
            'city_id' => City::first()->id,
            'title'   => 'Test share item',
        ]);
    }

    public function test_the_page_offers_the_share_targets(): void
    {
        $listing = $this->listing();

        $html = $this->get(route('listing', $listing))->assertOk()->getContent();

        $this->assertStringContainsString('Сподели обявата', $html);
        $this->assertStringContainsString('viber://forward?text=', $html);
        $this->assertStringContainsString('https://wa.me/?text=', $html);
        $this->assertStringContainsString('facebook.com/sharer/sharer.php?u=', $html);
        $this->assertStringContainsString('Копирай линка', $html);
    }

    public function test_the_shared_address_is_the_listing_itself(): void
    {
        $listing = $this->listing();

        $html = $this->get(route('listing', $listing))->getContent();

        $this->assertStringContainsString(rawurlencode(route('listing', $listing)), $html);
    }

    public function test_the_seller_can_share_their_own_listing(): void
    {
        $listing = $this->listing();

        $this->actingAs($listing->user)
            ->get(route('listing', $listing))
            ->assertOk()
            ->assertSee('Сподели обявата');
    }
}
