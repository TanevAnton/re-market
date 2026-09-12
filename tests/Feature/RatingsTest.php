<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Profile\ShowProfile;
use App\Livewire\Ratings\RateDeal;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\Rating;
use App\Models\User;
use App\Services\Deals\DealService;
use App\Services\Offers\OfferService;
use App\Services\Ratings\RatingException;
use App\Services\Ratings\RatingService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A rating cannot exist without a completed deal that both sides confirmed.
 * That is what makes a five-star average expensive to fake: it costs real
 * transactions rather than an afternoon with sock puppets.
 */
class RatingsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $buyer;
    private Listing $listing;
    private RatingService $ratings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller = User::factory()->create();
        $this->buyer  = User::factory()->create();

        $this->listing = Listing::factory()->create([
            'user_id'         => $this->seller->id,
            'status'          => ListingStatus::Active,
            'offers_enabled'  => true,
            'price_cents'     => 100000,
            'min_offer_cents' => null,
        ]);

        $this->ratings = app(RatingService::class);

        // The factory seeds plausible reputation numbers so demo profiles do
        // not all read zero. Start these two clean so the assertions below are
        // about what the service did.
        foreach ([$this->seller, $this->buyer] as $u) {
            $u->forceFill(['rating_count' => 0, 'rating_avg' => null])->save();
        }
    }

    private function openDeal(): Deal
    {
        $offers = app(OfferService::class);
        $offer  = $offers->place($this->listing, $this->buyer, 90000);

        return $offers->accept($offer, $this->seller);
    }

    private function completedDeal(): Deal
    {
        $deals = app(DealService::class);
        $deal  = $this->openDeal();

        $deals->confirm($deal, $this->buyer);
        $deals->confirm($deal->fresh(), $this->seller);

        return $deal->fresh();
    }

    // --- the gate --------------------------------------------------------

    public function test_an_open_deal_cannot_be_rated_yet(): void
    {
        $deal = $this->openDeal();

        $this->assertFalse($this->ratings->canRate($deal, $this->buyer));

        $this->expectException(RatingException::class);
        $this->ratings->rate($deal, $this->buyer, 5);
    }

    public function test_a_stranger_cannot_rate_a_deal_they_were_not_part_of(): void
    {
        $deal = $this->completedDeal();

        $this->expectException(RatingException::class);

        $this->ratings->rate($deal, User::factory()->create(), 5);
    }

    public function test_one_rating_per_person_per_deal(): void
    {
        $deal = $this->completedDeal();

        $this->ratings->rate($deal, $this->buyer, 5);

        $this->assertFalse($this->ratings->canRate($deal->fresh(), $this->buyer));

        $this->expectException(RatingException::class);
        $this->ratings->rate($deal->fresh(), $this->buyer, 1);
    }

    public function test_the_unique_index_stops_a_second_rating_even_if_the_service_is_bypassed(): void
    {
        $deal = $this->completedDeal();
        $this->ratings->rate($deal, $this->buyer, 5);

        // The rule lives in the database too, so a forgotten check cannot
        // quietly open the door.
        $this->expectException(QueryException::class);

        Rating::create([
            'deal_id' => $deal->id, 'rater_id' => $this->buyer->id,
            'ratee_id' => $this->seller->id, 'role' => 'seller', 'score' => 1,
        ]);
    }

    public function test_a_score_outside_one_to_five_is_refused(): void
    {
        $deal = $this->completedDeal();

        $this->expectException(RatingException::class);
        $this->ratings->rate($deal, $this->buyer, 6);
    }

    public function test_rating_closes_after_the_window(): void
    {
        $deal = $this->completedDeal();

        $this->travel(61)->days();

        // Months later nobody remembers the handover well enough for the
        // rating to carry information.
        $this->assertFalse($this->ratings->canRate($deal->fresh(), $this->buyer));
    }

    // --- what it records -------------------------------------------------

    public function test_both_sides_can_rate_and_the_role_records_who_was_which(): void
    {
        $deal = $this->completedDeal();

        $fromBuyer  = $this->ratings->rate($deal, $this->buyer, 5, 'Всичко точно.');
        $fromSeller = $this->ratings->rate($deal->fresh(), $this->seller, 4);

        $this->assertSame($this->seller->id, $fromBuyer->ratee_id);
        $this->assertSame('seller', $fromBuyer->role);

        $this->assertSame($this->buyer->id, $fromSeller->ratee_id);
        $this->assertSame('buyer', $fromSeller->role);
    }

    public function test_the_average_is_cached_on_the_user_and_recomputed(): void
    {
        $deal = $this->completedDeal();
        $this->ratings->rate($deal, $this->buyer, 5);

        $seller = $this->seller->fresh();
        $this->assertSame(1, (int) $seller->rating_count);
        $this->assertSame('5.00', (string) $seller->rating_avg);

        // A second deal, rated lower.
        $second = Listing::factory()->create([
            'user_id' => $this->seller->id,
            'status'  => ListingStatus::Active,
            'offers_enabled' => true,
            'price_cents' => 50000,
            'min_offer_cents' => null,
        ]);

        $offers = app(OfferService::class);
        $deals  = app(DealService::class);
        $offer  = $offers->place($second, $this->buyer, 45000);
        $d2     = $offers->accept($offer, $this->seller);
        $deals->confirm($d2, $this->buyer);
        $deals->confirm($d2->fresh(), $this->seller);

        $this->ratings->rate($d2->fresh(), $this->buyer, 2);

        $seller = $this->seller->fresh();
        $this->assertSame(2, (int) $seller->rating_count);
        $this->assertSame('3.50', (string) $seller->rating_avg);
    }

    public function test_a_hidden_rating_leaves_the_average(): void
    {
        $deal = $this->completedDeal();
        $rating = $this->ratings->rate($deal, $this->buyer, 1);

        $this->assertSame('1.00', (string) $this->seller->fresh()->rating_avg);

        // Moderating an unfair rating has to actually remove it from the
        // number, or hiding it is theatre.
        $rating->forceFill(['is_hidden' => true])->save();
        $this->ratings->recompute($this->seller->id);

        $seller = $this->seller->fresh();
        $this->assertSame(0, (int) $seller->rating_count);
        $this->assertNull($seller->rating_avg);
    }

    // --- replies ---------------------------------------------------------

    public function test_the_rated_person_gets_exactly_one_public_reply(): void
    {
        $deal   = $this->completedDeal();
        $rating = $this->ratings->rate($deal, $this->buyer, 2, 'Закъсня с два дни.');

        $this->ratings->reply($rating, $this->seller, 'Извинявам се, куриерът обърка адреса.');

        $this->assertStringContainsString('куриерът', $rating->fresh()->reply);

        $this->expectException(RatingException::class);
        $this->expectExceptionMessage('Вече отговори');

        $this->ratings->reply($rating->fresh(), $this->seller, 'И още нещо...');
    }

    public function test_only_the_rated_person_can_reply(): void
    {
        $deal   = $this->completedDeal();
        $rating = $this->ratings->rate($deal, $this->buyer, 3);

        $this->expectException(RatingException::class);

        $this->ratings->reply($rating, $this->buyer, 'сам си отговарям');
    }

    // --- the screens -----------------------------------------------------

    public function test_a_party_can_rate_from_the_deals_screen(): void
    {
        $deal = $this->completedDeal();

        $this->actingAs($this->buyer);

        Livewire::test(RateDeal::class, ['deal' => $deal])
            ->set('score', 5)
            ->set('comment', 'Бърза сделка.')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('ratings', 1);
        $this->assertSame('5.00', (string) $this->seller->fresh()->rating_avg);
    }

    public function test_the_profile_shows_ratings_and_replies(): void
    {
        $deal = $this->completedDeal();
        $this->ratings->rate($deal, $this->buyer, 4, 'Картата беше както е описана.');

        Livewire::test(ShowProfile::class, ['username' => $this->seller->username])
            ->assertSee('Картата беше както е описана.')
            ->assertSee('като продавач');
    }

    public function test_a_hidden_rating_is_not_shown_on_the_profile(): void
    {
        $deal   = $this->completedDeal();
        $rating = $this->ratings->rate($deal, $this->buyer, 1, 'Несправедлив коментар.');
        $rating->forceFill(['is_hidden' => true])->save();

        Livewire::test(ShowProfile::class, ['username' => $this->seller->username])
            ->assertDontSee('Несправедлив коментар.');
    }

    /**
     * The service has always known the window closes after 60 days. The screen
     * did not: it drew five stars and a comment box for anyone on a completed
     * deal, took the whole submission, and answered with a generic error.
     */
    public function test_the_rating_form_is_not_offered_after_the_window(): void
    {
        $deal = $this->completedDeal();

        $this->actingAs($this->buyer);

        Livewire::test(RateDeal::class, ['deal' => $deal])
            ->assertSee('Как мина с');

        $this->travel(61)->days();

        Livewire::test(RateDeal::class, ['deal' => $deal->fresh()])
            ->assertDontSee('Как мина с')
            // Said, rather than the form silently vanishing - which reads as
            // the page being broken.
            ->assertSee('Срокът за оценка');
    }

    /**
     * A brand-new seller and a bad one produced the same row of dashes, and a
     * buyer reads that row as the second one.
     */
    public function test_a_profile_with_no_history_says_it_is_new(): void
    {
        $fresh = User::factory()->create();
        $fresh->forceFill(['deals_completed' => 0, 'rating_count' => 0, 'rating_avg' => null])->save();

        Livewire::test(ShowProfile::class, ['username' => $fresh->username])
            ->assertSee('Нов профил');
    }

    public function test_a_profile_with_history_is_not_called_new(): void
    {
        $deal = $this->completedDeal();
        $this->ratings->rate($deal, $this->buyer, 5);

        Livewire::test(ShowProfile::class, ['username' => $this->seller->username])
            ->assertDontSee('Нов профил');
    }
}
