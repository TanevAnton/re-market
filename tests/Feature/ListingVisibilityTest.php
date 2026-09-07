<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);
        $this->owner = User::factory()->create();
    }

    private function listing(ListingStatus $status): Listing
    {
        return Listing::factory()->create([
            'user_id' => $this->owner->id,
            'status'  => $status,
        ]);
    }

    public function test_an_active_listing_is_public(): void
    {
        $this->get(route('listing', $this->listing(ListingStatus::Active)))->assertOk();
    }

    /**
     * Regression: publishing sent the seller straight to a 404, because a new
     * account's first listings go to review and ShowListing aborted on any
     * status that was not publicly visible - including for the owner.
     */
    public function test_the_owner_can_see_their_own_listing_awaiting_review(): void
    {
        $listing = $this->listing(ListingStatus::PendingReview);

        $this->actingAs($this->owner)
            ->get(route('listing', $listing))
            ->assertOk()
            ->assertSee('чака преглед');
    }

    public function test_a_stranger_cannot_see_a_listing_awaiting_review(): void
    {
        $listing = $this->listing(ListingStatus::PendingReview);

        $this->get(route('listing', $listing))->assertNotFound();
        $this->actingAs(User::factory()->create())
            ->get(route('listing', $listing))
            ->assertNotFound();
    }

    public function test_a_draft_is_not_public_either(): void
    {
        $this->get(route('listing', $this->listing(ListingStatus::Draft)))->assertNotFound();
    }

    public function test_the_owners_own_view_does_not_inflate_the_counter(): void
    {
        $listing = $this->listing(ListingStatus::Active);

        // The factory seeds a plausible view_count, so assert the DELTA.
        // An absolute 0 would only ever pass by accident.
        $before = $listing->view_count;

        $this->actingAs($this->owner)->get(route('listing', $listing));
        $this->assertSame($before, $listing->fresh()->view_count);

        $this->actingAs(User::factory()->create())->get(route('listing', $listing));
        $this->assertSame($before + 1, $listing->fresh()->view_count);
    }
}
