<?php

namespace Tests\Feature;

use App\Enums\BoostTier;
use App\Enums\ListingStatus;
use App\Models\Boost;
use App\Models\City;
use App\Models\CreditTransaction;
use App\Models\Listing;
use App\Models\User;
use App\Services\Billing\BoostService;
use App\Services\Billing\CreditService;
use Database\Seeders\CitySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Paid visibility.
 *
 * Most of this file is about money not going missing and about what money
 * CANNOT buy. The happy path is three lines; the refusals are the feature.
 */
class BoostTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private BoostService $boosts;
    private CreditService $credit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        $this->seller = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        $this->boosts = app(BoostService::class);
        $this->credit = app(CreditService::class);
    }

    private function listing(array $attributes = [], ?User $owner = null): Listing
    {
        return Listing::factory()->create([
            'user_id'  => ($owner ?? $this->seller)->id,
            'city_id'  => City::first()->id,
            'category' => 'gpu',
            'status'   => ListingStatus::Active,
            ...$attributes,
        ]);
    }

    // --- the ledger -------------------------------------------------------

    public function test_the_balance_is_the_sum_of_the_rows(): void
    {
        $this->assertSame(0, $this->credit->balance($this->seller));

        $this->credit->topUp($this->seller, 2000, 'card');
        $this->credit->grant($this->seller, 500, 'early seller');
        $this->credit->spend($this->seller, 900, 'pin');

        $this->assertSame(1600, $this->credit->balance($this->seller));
    }

    /**
     * The database refuses to edit history, and refuses LOUDLY.
     *
     * An earlier version of the migration used `ON UPDATE DO INSTEAD NOTHING`,
     * which would have made this test pass while the UPDATE silently did
     * nothing — the worst of both, because the code would look guarded and
     * the next person would trust it.
     */
    public function test_the_ledger_cannot_be_edited(): void
    {
        $row = $this->credit->topUp($this->seller, 1000, 'card');

        $this->expectException(QueryException::class);

        \DB::table('credit_transactions')->where('id', $row->id)->update(['amount_cents' => 999999]);
    }

    public function test_a_spend_must_be_negative_and_a_topup_positive(): void
    {
        foreach ([
            [CreditTransaction::SPEND, 100],
            [CreditTransaction::TOPUP, -100],
            [CreditTransaction::GRANT, 0],
        ] as [$kind, $amount]) {
            try {
                CreditTransaction::create([
                    'user_id' => $this->seller->id, 'amount_cents' => $amount, 'kind' => $kind,
                ]);
                $this->fail("[{$kind} {$amount}] should have been refused");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_you_cannot_spend_what_you_do_not_have(): void
    {
        $this->credit->topUp($this->seller, 500, 'card');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Нямаш достатъчно кредит');

        $this->credit->spend($this->seller, 900, 'pin');
    }

    /**
     * Two spends that each fit but together do not.
     *
     * This is the sequential shadow of the real race — „read, check, write"
     * with two requests in flight. It cannot reproduce the concurrency in a
     * single-threaded test, but it does prove the check reads a balance that
     * the first spend already moved, rather than one cached at the start.
     */
    public function test_the_balance_falls_as_it_is_spent(): void
    {
        $this->credit->topUp($this->seller, 1000, 'card');

        $this->credit->spend($this->seller, 900, 'pin');

        $this->expectException(RuntimeException::class);

        $this->credit->spend($this->seller, 900, 'pin again');
    }

    // --- buying -----------------------------------------------------------

    public function test_a_bump_moves_the_listing_and_costs_its_price(): void
    {
        $listing = $this->listing(['bumped_at' => now()->subDays(5)]);
        $this->credit->topUp($this->seller, 1000, 'card');

        $boost = $this->boosts->buy($listing, $this->seller, BoostTier::Bump);

        $this->assertSame(BoostTier::Bump->priceCents(), $boost->price_cents);
        $this->assertSame(1000 - BoostTier::Bump->priceCents(), $this->credit->balance($this->seller));

        // The product itself: browse sorts on bumped_at.
        $this->assertTrue($listing->fresh()->bumped_at->isAfter(now()->subMinute()));
    }

    /**
     * A bump is an event, so it is never „running" and never wears the badge.
     *
     * Labelling a days-old bump as promoted would be a disclosure that
     * misleads in the exact direction the Omnibus rules exist to prevent.
     */
    public function test_a_bump_is_never_running(): void
    {
        $this->credit->topUp($this->seller, 1000, 'card');

        $boost = $this->boosts->buy($this->listing(), $this->seller, BoostTier::Bump);

        $this->assertNull($boost->ends_at);
        $this->assertFalse($boost->isRunning());
        $this->assertSame(0, Boost::running()->count());
    }

    public function test_a_pin_runs_for_its_window(): void
    {
        $this->credit->topUp($this->seller, 2000, 'card');

        $boost = $this->boosts->buy($this->listing(), $this->seller, BoostTier::Pin);

        $this->assertTrue($boost->isRunning());
        $this->assertSame(BoostTier::Pin->days(), (int) round(now()->diffInDays($boost->ends_at)));
        $this->assertSame(1, Boost::running()->pinned()->count());
    }

    /** Bumps repeat — that is the point of them. Windows do not. */
    public function test_bumps_repeat_but_a_running_window_cannot_be_bought_twice(): void
    {
        $listing = $this->listing();
        $this->credit->topUp($this->seller, 5000, 'card');

        $this->boosts->buy($listing, $this->seller, BoostTier::Bump);
        $this->boosts->buy($listing, $this->seller, BoostTier::Bump);
        $this->assertSame(2, Boost::where('tier', BoostTier::Bump)->count());

        $this->boosts->buy($listing, $this->seller, BoostTier::Highlight);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Вече имаш активно');

        $this->boosts->buy($listing, $this->seller, BoostTier::Highlight);
    }

    // --- what money cannot buy -------------------------------------------

    /**
     * THE ONE THAT MATTERS MOST. The people most willing to pay for instant
     * visibility are the ones with the most to gain from a fast scam, so a
     * boost must never be a way past the moderation queue.
     */
    public function test_a_listing_awaiting_review_cannot_be_boosted(): void
    {
        $listing = $this->listing(['status' => ListingStatus::PendingReview]);
        $this->credit->topUp($this->seller, 5000, 'card');

        try {
            $this->boosts->buy($listing, $this->seller, BoostTier::Pin);
            $this->fail('a pending listing was boosted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('не ускорява проверката', $e->getMessage());
        }

        // And nothing was taken for it.
        $this->assertSame(5000, $this->credit->balance($this->seller));
    }

    /** A reserved listing is spoken for; a top slot on it sells nothing. */
    public function test_a_reserved_listing_cannot_be_boosted(): void
    {
        $listing = $this->listing(['status' => ListingStatus::Reserved]);
        $this->credit->topUp($this->seller, 5000, 'card');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('запазена');

        $this->boosts->buy($listing, $this->seller, BoostTier::Pin);
    }

    public function test_you_cannot_boost_somebody_elses_listing(): void
    {
        $stranger = User::factory()->create(['city_id' => City::first()->id]);
        $listing  = $this->listing([], $stranger);

        $this->credit->topUp($this->seller, 5000, 'card');

        $this->expectException(NotFoundHttpException::class);

        $this->boosts->buy($listing, $this->seller, BoostTier::Pin);
    }

    public function test_a_boost_is_not_bought_when_the_balance_is_short(): void
    {
        $listing = $this->listing();

        try {
            $this->boosts->buy($listing, $this->seller, BoostTier::Pin);
            $this->fail('bought a pin with an empty balance');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        // The transaction rolled the boost back with the spend.
        $this->assertSame(0, Boost::count());
    }

    // --- losing it --------------------------------------------------------

    /**
     * A listing that sells mid-window returns the unused part as credit.
     *
     * Credit rather than cash: the seller lost nothing, and reversing €0.40 to
     * a card costs more in fees than it returns.
     */
    public function test_selling_mid_window_refunds_the_rest(): void
    {
        $listing = $this->listing();
        $this->credit->topUp($this->seller, 2000, 'card');

        $boost = $this->boosts->buy($listing, $this->seller, BoostTier::Pin);
        $spent = $this->credit->balance($this->seller);

        // Four of seven days gone.
        $this->travel(4)->days();

        $refunded = $this->boosts->stopAll($listing, 'sold');

        $this->assertGreaterThan(0, $refunded);
        $this->assertSame($spent + $refunded, $this->credit->balance($this->seller));
        $this->assertFalse($boost->fresh()->isRunning());
        $this->assertNotNull($boost->fresh()->cancelled_at);

        // Roughly three sevenths back, rounded the seller's way.
        $this->assertEqualsWithDelta(BoostTier::Pin->priceCents() * 3 / 7, $refunded, 2);
    }

    /** Stopping something already over is not an error and pays nothing. */
    public function test_stopping_a_finished_boost_is_a_no_op(): void
    {
        $listing = $this->listing();
        $this->credit->topUp($this->seller, 2000, 'card');

        $boost = $this->boosts->buy($listing, $this->seller, BoostTier::Pin);

        $this->travel(BoostTier::Pin->days() + 1)->days();

        $this->assertSame(0, $this->boosts->stop($boost->fresh(), 'expired'));
    }

    // --- the screen's questions ------------------------------------------

    public function test_availability_explains_itself(): void
    {
        $active = $this->boosts->availability($this->listing());

        $this->assertNull($active[BoostTier::Bump->value]);
        $this->assertNull($active[BoostTier::Pin->value]);

        $sold = $this->boosts->availability($this->listing(['status' => ListingStatus::Sold]));

        foreach (BoostTier::ladder() as $tier) {
            $this->assertNotNull($sold[$tier->value], "[{$tier->value}] should refuse on a sold listing");
        }
    }

    /**
     * Only the tiers that move a listing count as ranking, and the labelling
     * rules read this rather than restating it.
     */
    public function test_only_position_changing_tiers_affect_ranking(): void
    {
        $this->assertTrue(BoostTier::Bump->affectsRanking());
        $this->assertTrue(BoostTier::Pin->affectsRanking());
        $this->assertFalse(BoostTier::Highlight->affectsRanking());
    }
}
