<?php

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Livewire\Listings\EditListing;
use App\Livewire\Listings\MyListings;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\Offer;
use App\Models\User;
use App\Services\Listings\ListingService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * What a seller can do to their own listing, and - mostly - what they cannot.
 *
 * Every action here is a bait-and-switch if the guard is wrong: raise the price
 * after an offer, mark a live deal sold to dodge the completion rate, edit a
 * listing a moderator took down. The guards are the feature, so they are what
 * these tests are about.
 */
class SellerListingControlTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $stranger;
    private ListingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller   = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->service  = app(ListingService::class);
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::factory()->create($attributes + [
            'user_id'     => $this->seller->id,
            'status'      => ListingStatus::Active,
            'price_cents' => 100000,
            // Pinned to null on purpose. The factory derives a floor of
            // 75-95% of the price, so a test that lowers the price could land
            // under its own floor and fail the min_offer <= price rule for a
            // reason that has nothing to do with what it is testing.
            'min_offer_cents' => null,
        ]);
    }

    private function pendingOffer(Listing $listing): Offer
    {
        return Offer::create([
            'listing_id'   => $listing->id,
            'buyer_id'     => $this->stranger->id,
            'seller_id'    => $listing->user_id,
            'amount_cents' => 90000,
            'status'       => OfferStatus::Pending,
            'expires_at'   => now()->addDay(),
        ]);
    }

    // --- ownership --------------------------------------------------------

    public function test_a_stranger_cannot_open_the_edit_screen(): void
    {
        $listing = $this->listing();

        $this->actingAs($this->stranger)
            ->get(route('listing.edit', $listing))
            ->assertNotFound();
    }

    public function test_a_stranger_cannot_act_on_someone_elses_listing(): void
    {
        $listing = $this->listing();

        $this->expectException(RuntimeException::class);

        $this->service->markSold($listing, $this->stranger);
    }

    public function test_the_owner_sees_their_listings(): void
    {
        $mine    = $this->listing();
        $theirs  = Listing::factory()->create(['user_id' => $this->stranger->id]);

        $this->actingAs($this->seller)
            ->get(route('listings.mine'))
            ->assertOk()
            ->assertSee($mine->title)
            ->assertDontSee($theirs->title);
    }

    // --- editing ----------------------------------------------------------

    public function test_the_owner_can_fix_the_price(): void
    {
        $listing = $this->listing();

        Livewire::actingAs($this->seller)
            ->test(EditListing::class, ['listing' => $listing])
            ->set('price', '850')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(85000, $listing->fresh()->price_cents);
    }

    /**
     * The consequence a seller could not guess. A pending offer was made
     * against a number that no longer exists, so leaving it open would let the
     * seller accept 900 on a listing now advertised at 1200, or the reverse.
     */
    public function test_changing_the_price_releases_pending_offers(): void
    {
        $listing = $this->listing();
        $offer   = $this->pendingOffer($listing);

        $this->service->update($listing, ['price_cents' => 120000], $this->seller);

        $this->assertSame(OfferStatus::Withdrawn, $offer->fresh()->status);
    }

    /**
     * Withdrawn, not Declined: a decline puts the buyer on a cooldown, and the
     * buyer did nothing wrong here.
     */
    public function test_a_released_offer_does_not_cool_the_buyer_down(): void
    {
        $listing = $this->listing();
        $offer   = $this->pendingOffer($listing);

        $this->service->update($listing, ['price_cents' => 120000], $this->seller);

        $this->assertFalse($offer->fresh()->status->triggersCooldown());
    }

    public function test_editing_anything_else_leaves_offers_alone(): void
    {
        $listing = $this->listing();
        $offer   = $this->pendingOffer($listing);

        $this->service->update($listing, ['description' => str_repeat('нов текст ', 5)], $this->seller);

        $this->assertSame(OfferStatus::Pending, $offer->fresh()->status);
    }

    /**
     * Changing which catalogue part a listing points at changes what is being
     * sold. Everyone who already looked at it saw something else.
     */
    public function test_the_catalogue_part_cannot_be_changed(): void
    {
        $listing  = $this->listing();
        $original = $listing->part_id;

        $this->service->update($listing, ['part_id' => $original + 1, 'title' => 'Друго заглавие'], $this->seller);

        $this->assertSame($original, $listing->fresh()->part_id);
    }

    /**
     * A moderator took this down. Editing it back into shape would be a way to
     * walk around that decision.
     */
    public function test_a_removed_listing_cannot_be_edited(): void
    {
        $listing = $this->listing(['status' => ListingStatus::Removed]);

        $this->expectException(RuntimeException::class);

        $this->service->update($listing, ['price_cents' => 1], $this->seller);
    }

    public function test_a_reserved_listing_cannot_be_edited(): void
    {
        $listing = $this->listing(['status' => ListingStatus::Reserved]);

        $this->expectException(RuntimeException::class);

        $this->service->update($listing, ['price_cents' => 1], $this->seller);
    }

    // --- marking sold -----------------------------------------------------

    public function test_marking_sold_delists_and_releases_offers(): void
    {
        $listing = $this->listing();
        $offer   = $this->pendingOffer($listing);

        $this->service->markSold($listing, $this->seller);

        $this->assertSame(ListingStatus::Sold, $listing->fresh()->status);
        $this->assertNotNull($listing->fresh()->sold_at);
        $this->assertSame(OfferStatus::Withdrawn, $offer->fresh()->status);
    }

    /**
     * THE test on this screen. Marking sold is a delisting, not a transaction -
     * it must never touch the completion rate, which is the one number on a
     * profile that cannot currently be faked.
     */
    public function test_marking_sold_is_not_a_completed_deal(): void
    {
        $before = $this->seller->deals_completed;

        $this->service->markSold($this->listing(), $this->seller);

        $this->assertSame(0, Deal::count());
        $this->assertSame($before, $this->seller->fresh()->deals_completed);
    }

    /**
     * A deal is open on these terms. Marking it sold here would skip the mutual
     * confirmation the buyer is waiting on, and with it the abandonment signal.
     */
    public function test_a_reserved_listing_cannot_be_marked_sold(): void
    {
        $listing = $this->listing(['status' => ListingStatus::Reserved]);

        $this->expectException(RuntimeException::class);

        $this->service->markSold($listing, $this->seller);
    }

    // --- relisting --------------------------------------------------------

    public function test_a_sold_listing_can_go_back_on_the_market(): void
    {
        $listing = $this->listing(['status' => ListingStatus::Sold, 'sold_at' => now()]);

        $this->service->relist($listing, $this->seller);

        $listing = $listing->fresh();

        $this->assertSame(ListingStatus::Active, $listing->status);
        $this->assertNull($listing->sold_at);
        $this->assertTrue($listing->expires_at->isAfter(now()->addDays(50)));
    }

    public function test_an_active_listing_cannot_be_relisted(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->relist($this->listing(), $this->seller);
    }

    // --- deleting ---------------------------------------------------------

    public function test_deleting_hides_the_listing_but_keeps_the_row(): void
    {
        $listing = $this->listing();

        $this->service->delete($listing, $this->seller);

        // Soft, because deals and ratings point at this row.
        $this->assertSoftDeleted('listings', ['id' => $listing->id]);
        $this->get(route('listing', $listing))->assertNotFound();
    }

    public function test_deleting_releases_pending_offers(): void
    {
        $listing = $this->listing();
        $offer   = $this->pendingOffer($listing);

        $this->service->delete($listing, $this->seller);

        $this->assertSame(OfferStatus::Withdrawn, $offer->fresh()->status);
    }

    /** Deleting under an open deal would strand the buyer mid-handover. */
    public function test_a_listing_with_an_open_deal_cannot_be_deleted(): void
    {
        $listing = $this->listing(['status' => ListingStatus::Reserved]);

        Deal::create([
            'listing_id'          => $listing->id,
            'buyer_id'            => $this->stranger->id,
            'seller_id'           => $this->seller->id,
            'agreed_price_cents'  => 90000,
            'status'              => DealStatus::Open,
            'expires_at'          => now()->addDays(3),
        ]);

        $this->expectException(RuntimeException::class);

        $this->service->delete($listing, $this->seller);
    }

    // --- bumping ----------------------------------------------------------

    public function test_bumping_moves_the_listing_up(): void
    {
        $listing = $this->listing(['bumped_at' => now()->subDays(2)]);

        $this->service->bump($listing, $this->seller);

        $this->assertTrue($listing->fresh()->bumped_at->isAfter(now()->subMinute()));
    }

    /**
     * Without the cooldown, bumping is free, everybody bumps constantly and the
     * ordering stops meaning anything.
     */
    public function test_bumping_twice_in_a_day_is_refused(): void
    {
        $listing = $this->listing(['bumped_at' => now()->subMinutes(5)]);

        $this->expectException(RuntimeException::class);

        $this->service->bump($listing, $this->seller);
    }

    public function test_the_screen_reports_a_refusal_instead_of_throwing(): void
    {
        $listing = $this->listing(['bumped_at' => now()]);

        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->call('bump', $listing->id)
            ->assertHasErrors('listing');
    }

    public function test_delete_from_the_screen_asks_first(): void
    {
        $listing = $this->listing();

        Livewire::actingAs($this->seller)
            ->test(MyListings::class)
            ->call('confirmDelete', $listing->id)
            ->assertSet('confirmingDelete', $listing->id);

        // Nothing has happened yet.
        $this->assertNotSoftDeleted('listings', ['id' => $listing->id]);
    }
}
