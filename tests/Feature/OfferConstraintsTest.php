<?php

namespace Tests\Feature;

use App\Enums\OfferStatus;
use App\Models\Listing;
use App\Models\Offer;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The anti-spam rules live in the DATABASE, not in application code, precisely
 * so that a forgotten validation call cannot bypass them. These tests assert
 * that the constraints are really there - if someone later "cleans up" a
 * migration, this fails rather than quietly opening the door.
 */
class OfferConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $buyer;
    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller  = User::factory()->create();
        $this->buyer   = User::factory()->create();
        $this->listing = Listing::factory()->create([
            'user_id'         => $this->seller->id,
            'price_cents'     => 50000,
            'min_offer_cents' => 40000,
        ]);
    }

    private function makeOffer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'listing_id'   => $this->listing->id,
            'buyer_id'     => $this->buyer->id,
            'seller_id'    => $this->seller->id,
            'amount_cents' => 45000,
            'expires_at'   => now()->addHours(48),
        ], $overrides));
    }

    public function test_a_legitimate_offer_is_accepted(): void
    {
        $offer = $this->makeOffer();

        $this->assertNotNull($offer->uuid);
        $this->assertSame(OfferStatus::Pending, $offer->status);
    }

    public function test_you_cannot_bid_on_your_own_listing(): void
    {
        $this->expectException(QueryException::class);

        $this->makeOffer(['buyer_id' => $this->seller->id]);
    }

    public function test_a_buyer_cannot_hold_two_live_offers_on_one_listing(): void
    {
        $this->makeOffer();

        // The partial unique index is what stops a bot flooding one seller.
        $this->expectException(QueryException::class);
        $this->makeOffer(['amount_cents' => 46000]);
    }

    public function test_a_buyer_may_offer_again_after_being_declined(): void
    {
        $first = $this->makeOffer();
        $first->update(['status' => OfferStatus::Declined]);

        $second = $this->makeOffer(['amount_cents' => 47000]);

        $this->assertSame(OfferStatus::Pending, $second->status);
        $this->assertSame(2, Offer::where('listing_id', $this->listing->id)->count());
    }

    public function test_offer_amount_must_be_positive(): void
    {
        $this->expectException(QueryException::class);

        $this->makeOffer(['amount_cents' => 0]);
    }

    public function test_a_price_floor_above_the_asking_price_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        Listing::factory()->create([
            'user_id'         => $this->seller->id,
            'price_cents'     => 10000,
            'min_offer_cents' => 15000,
        ]);
    }

    public function test_the_floor_auto_declines_lowballs_without_touching_the_seller(): void
    {
        // The rule the whole product rests on: below the floor never arrives.
        $this->assertTrue($this->listing->isBelowFloor(39999));
        $this->assertFalse($this->listing->isBelowFloor(40000));
    }

    public function test_mining_duration_cannot_be_claimed_on_a_non_mined_card(): void
    {
        $this->expectException(QueryException::class);

        Listing::factory()->create([
            'user_id'       => $this->seller->id,
            'mining_use'    => \App\Enums\MiningUse::No,
            'mining_months' => 6,
        ]);
    }
}
