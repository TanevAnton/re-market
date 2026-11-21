<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\SellerType;
use App\Models\Listing;
use App\Models\User;
use App\Support\SellerSignals;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a listing card says about its seller.
 *
 * The rules are all about NOT saying things: a record before it means anything,
 * a rating without the completion rate to back it, a verification that is only
 * a claim. The positive cases are short; the refusals are the point.
 */
class SellerSignalsTest extends TestCase
{
    use RefreshDatabase;

    private function seller(array $attributes = []): User
    {
        return (new User)->forceFill($attributes + [
            'deals_completed' => 0,
            'deals_abandoned' => 0,
            'rating_avg'      => null,
            'seller_type'     => SellerType::Private,
            'trader_status'   => User::TRADER_NONE,
        ]);
    }

    public function test_a_new_seller_has_nothing_to_show_and_nothing_is_implied(): void
    {
        $this->assertSame([], SellerSignals::for($this->seller()));
    }

    public function test_a_record_with_a_rating_reads_as_one_chip(): void
    {
        $signals = SellerSignals::for($this->seller(['deals_completed' => 12, 'rating_avg' => '4.90']));

        $this->assertCount(1, $signals);
        $this->assertSame('record', $signals[0]['kind']);
        $this->assertSame('★ 4.9 · 12 сделки', $signals[0]['label']);
    }

    public function test_the_stars_are_left_off_below_the_floor_but_the_deals_stay(): void
    {
        $signals = SellerSignals::for($this->seller(['deals_completed' => 8, 'rating_avg' => '4.20']));

        $this->assertSame('8 сделки', $signals[0]['label']);
    }

    public function test_a_seller_with_no_rating_yet_still_shows_the_deals(): void
    {
        $signals = SellerSignals::for($this->seller(['deals_completed' => 5, 'rating_avg' => null]));

        $this->assertSame('5 сделки', $signals[0]['label']);
    }

    /** One deal is an anecdote and two is a coincidence. */
    public function test_a_record_is_withheld_until_it_is_three_deals_long(): void
    {
        $this->assertSame([], SellerSignals::for($this->seller(['deals_completed' => 2, 'rating_avg' => '5.00'])));

        $this->assertNotSame([], SellerSignals::for($this->seller(['deals_completed' => 3, 'rating_avg' => '5.00'])));
    }

    /**
     * The guard against the other trick: agree to many deals, walk away from
     * half, and let the other half carry a perfect rating.
     */
    public function test_a_seller_who_abandons_deals_has_no_record_however_good_the_rest(): void
    {
        $this->assertSame([], SellerSignals::for($this->seller([
            'deals_completed' => 10, 'deals_abandoned' => 5, 'rating_avg' => '5.00',
        ])));

        // Exactly on the line is enough: 9 of 10 is 90%.
        $this->assertNotSame([], SellerSignals::for($this->seller([
            'deals_completed' => 9, 'deals_abandoned' => 1,
        ])));
    }

    public function test_the_tooltip_states_the_numbers_behind_the_chip(): void
    {
        $signals = SellerSignals::for($this->seller(['deals_completed' => 19, 'deals_abandoned' => 1]));

        $this->assertStringContainsString('Приключени сделки: 19', $signals[0]['title']);
        $this->assertStringContainsString('95%', $signals[0]['title']);
    }

    public function test_a_verified_company_is_marked_and_first(): void
    {
        $signals = SellerSignals::for($this->seller([
            'seller_type' => SellerType::Trader, 'trader_status' => User::TRADER_VERIFIED,
            'deals_completed' => 4,
        ]));

        $this->assertSame(['verified', 'record'], array_column($signals, 'kind'));
    }

    /** Declaring oneself a trader is a claim; this badge is for the checked ones. */
    public function test_a_declared_but_unchecked_trader_gets_no_badge(): void
    {
        foreach ([User::TRADER_NONE, User::TRADER_PENDING, User::TRADER_REJECTED] as $status) {
            $this->assertSame([], SellerSignals::for($this->seller([
                'seller_type' => SellerType::Trader, 'trader_status' => $status,
            ])), $status);
        }
    }

    /** A verification left standing after the seller went back to „частно лице". */
    public function test_a_stale_verification_on_a_private_seller_gets_no_badge(): void
    {
        $this->assertSame([], SellerSignals::for($this->seller([
            'seller_type' => SellerType::Private, 'trader_status' => User::TRADER_VERIFIED,
        ])));
    }

    // --- on the page -------------------------------------------------------

    private function listingBy(User $user): Listing
    {
        return Listing::factory()->create([
            'user_id'         => $user->id,
            'status'          => ListingStatus::Active,
            'min_offer_cents' => null,
        ]);
    }

    public function test_the_home_page_card_shows_the_record_of_a_trusted_seller(): void
    {
        $this->seed(CitySeeder::class);

        $this->listingBy(User::factory()->create([
            'deals_completed' => 12, 'deals_abandoned' => 0, 'rating_avg' => 4.9,
        ]));

        $this->get(route('home'))->assertOk()->assertSee('★ 4.9 · 12 сделки');
    }

    public function test_a_card_for_a_seller_with_no_record_says_nothing_about_deals(): void
    {
        $this->seed(CitySeeder::class);

        // Said outright: the factory hands every user a random record, and one
        // that happened to be strong enough drew the chip this test forbids.
        $this->listingBy(User::factory()->create([
            'deals_completed' => 0, 'deals_abandoned' => 0, 'rating_avg' => 0,
        ]));

        // „Приключени сделки" is the tooltip of the chip, so it appears only
        // when the chip does. The bare word is on other parts of the page.
        $this->get(route('home'))->assertOk()->assertDontSee('Приключени сделки');
    }
}
