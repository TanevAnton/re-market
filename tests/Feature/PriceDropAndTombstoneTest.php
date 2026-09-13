<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\ShowListing;
use App\Models\City;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Notifications\PriceDropped;
use App\Services\Listings\ListingService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Two ways of not wasting a buyer who was already interested.
 *
 * One tells the people who shortlisted a card that it got cheaper; the other
 * stops a link pasted into a Viber group becoming a dead end the moment that
 * card sells. Both are about the same person: somebody who has already decided
 * they want this exact thing.
 */
class PriceDropAndTombstoneTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $watcher;
    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller  = User::factory()->create();
        $this->watcher = User::factory()->create(['email_verified_at' => now()]);

        $this->listing = Listing::factory()->create([
            'user_id'     => $this->seller->id,
            'status'      => ListingStatus::Active,
            'category'    => 'gpu',
            'price_cents' => 100000,
            // No floor: these tests are about the price moving, and a factory
            // floor of 86% turns "cut to 800" into a CHECK violation rather
            // than a price cut.
            'min_offer_cents' => null,
            'city_id'     => City::first()->id,
        ]);
    }

    private function favourite(User $user, ?Listing $listing = null): void
    {
        Favorite::create([
            'user_id'    => $user->id,
            'listing_id' => ($listing ?? $this->listing)->id,
        ]);
    }

    private function setPrice(int $cents): void
    {
        app(ListingService::class)->update(
            $this->listing->fresh(),
            ['price_cents' => $cents],
            $this->seller,
        );
    }

    // --- price drops ------------------------------------------------------

    public function test_a_price_drop_reaches_the_people_who_shortlisted_it(): void
    {
        Notification::fake();
        $this->favourite($this->watcher);

        $this->setPrice(85000);

        Notification::assertSentTo($this->watcher, PriceDropped::class);
    }

    /** "The thing you wanted costs more now" is a message with no upside. */
    public function test_a_price_rise_tells_nobody(): void
    {
        Notification::fake();
        $this->favourite($this->watcher);

        $this->setPrice(120000);

        Notification::assertNothingSent();
    }

    /**
     * A 2 € cut on a 900 € card is not a reason to interrupt anyone, and a
     * channel that interrupts for nothing gets muted before it ever carries
     * something worth reading.
     */
    public function test_a_trivial_cut_is_not_news(): void
    {
        Notification::fake();
        $this->favourite($this->watcher);

        $this->setPrice(99000);      // 1%, under the 3% floor

        Notification::assertNothingSent();
    }

    /**
     * A seller feeling out the market moves the price several times in an
     * afternoon. Each step clears the threshold; the fourth message is what
     * gets the whole channel turned off.
     */
    public function test_a_second_drop_inside_the_cooldown_is_silent(): void
    {
        Notification::fake();
        $this->favourite($this->watcher);

        $this->setPrice(90000);
        $this->setPrice(80000);

        Notification::assertSentToTimes($this->watcher, PriceDropped::class, 1);
    }

    public function test_a_drop_after_the_cooldown_is_announced_again(): void
    {
        Notification::fake();
        $this->favourite($this->watcher);

        $this->setPrice(90000);
        $this->travel(25)->hours();
        $this->setPrice(80000);

        Notification::assertSentToTimes($this->watcher, PriceDropped::class, 2);
    }

    /**
     * Nobody was told, so nothing should be suppressed. Moving the watermark
     * on an empty audience would swallow the next drop - possibly the one
     * somebody was waiting for - behind a cooldown that protected no one.
     */
    public function test_a_drop_with_no_watchers_does_not_start_a_cooldown(): void
    {
        Notification::fake();

        $this->setPrice(90000);
        $this->assertNull($this->listing->fresh()->price_drop_notified_at);

        $this->favourite($this->watcher);
        $this->setPrice(80000);

        Notification::assertSentTo($this->watcher, PriceDropped::class);
    }

    public function test_the_seller_is_not_told_about_their_own_price_cut(): void
    {
        Notification::fake();
        $this->favourite($this->seller);

        $this->setPrice(85000);

        Notification::assertNotSentTo($this->seller, PriceDropped::class);
    }

    /** A price change on something nobody can act on is not an offer. */
    public function test_a_listing_awaiting_review_announces_nothing(): void
    {
        Notification::fake();
        $this->favourite($this->watcher);

        $this->listing->forceFill(['status' => ListingStatus::PendingReview])->save();
        $this->setPrice(85000);

        Notification::assertNothingSent();
    }

    public function test_the_message_names_both_numbers(): void
    {
        Notification::fake();
        $this->favourite($this->watcher);

        $this->setPrice(85000);

        Notification::assertSentTo($this->watcher, PriceDropped::class,
            function (PriceDropped $n) {
                $lines = implode(' ', $n->lines($this->watcher));

                $this->assertStringContainsString('1 000,00 €', $lines);
                $this->assertStringContainsString('850,00 €', $lines);
                // And how to make it stop, because nobody asked for this one.
                $this->assertStringContainsString('харесан', $lines);

                return true;
            });
    }

    /**
     * Found by the cooldown test rather than written for it: a partial update
     * that drops the price under an existing floor used to reach the database
     * and come back as a QueryException. EditListing never does that - it
     * always sends both fields - but the service is where the guards live.
     */
    public function test_dropping_the_price_under_the_offer_floor_is_refused_not_500(): void
    {
        $this->listing->forceFill(['min_offer_cents' => 90000])->save();

        $this->expectException(\RuntimeException::class);

        $this->setPrice(80000);
    }

    public function test_lowering_both_together_is_fine(): void
    {
        Notification::fake();
        $this->favourite($this->watcher);
        $this->listing->forceFill(['min_offer_cents' => 90000])->save();

        app(ListingService::class)->update(
            $this->listing->fresh(),
            ['price_cents' => 80000, 'min_offer_cents' => 70000],
            $this->seller,
        );

        Notification::assertSentTo($this->watcher, PriceDropped::class);
    }

    // --- the tombstone ----------------------------------------------------

    public function test_a_sold_listing_is_reachable_instead_of_404(): void
    {
        $this->listing->forceFill(['status' => ListingStatus::Sold])->save();

        Livewire::test(ShowListing::class, ['listing' => $this->listing])
            ->assertSee('Продадено');
    }

    public function test_an_expired_listing_says_so_rather_than_saying_sold(): void
    {
        $this->listing->forceFill(['status' => ListingStatus::Expired])->save();

        Livewire::test(ShowListing::class, ['listing' => $this->listing])
            ->assertSee('Обявата изтече')
            ->assertDontSee('Продадено');
    }

    /**
     * The one that must NOT change. A moderator took that listing down, and
     * serving it again - banner or no banner - publishes the content the
     * decision was about.
     */
    public function test_a_removed_listing_still_404s_for_the_public(): void
    {
        $this->listing->forceFill(['status' => ListingStatus::Removed])->save();

        Livewire::test(ShowListing::class, ['listing' => $this->listing])
            ->assertNotFound();
    }

    public function test_a_tombstone_offers_nothing_to_click(): void
    {
        $this->listing->forceFill(['status' => ListingStatus::Sold])->save();

        Livewire::actingAs($this->watcher)
            ->test(ShowListing::class, ['listing' => $this->listing])
            ->assertDontSee('Съобщение до продавача')
            ->assertSee('Последна обявена цена');
    }

    /**
     * noindex so a site full of sold pages does not compete with its own live
     * listings, canonical to the catalogue page so whatever authority the
     * shared link earned goes somewhere that will still exist next year.
     *
     * Asserted through a real request, because the meta is rendered by the
     * layout and a component test never reaches it. SEO_INDEXABLE has to be on
     * or the global noindex masks the per-page one.
     */
    public function test_a_tombstone_is_noindex_and_canonical_to_the_catalogue_page(): void
    {
        config(['remarket.seo.indexable' => true]);

        $part = Part::where('category', 'gpu')->firstOrFail();

        $this->listing->forceFill([
            'status'  => ListingStatus::Sold,
            'part_id' => $part->id,
        ])->save();

        $html = $this->get(route('listing', $this->listing))->assertOk()->getContent();

        $this->assertStringContainsString('name="robots" content="noindex, follow"', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('part', $part).'"', $html);
    }

    /** A live listing keeps its own canonical and stays indexable. */
    public function test_a_live_listing_is_still_indexable(): void
    {
        config(['remarket.seo.indexable' => true]);

        $html = $this->get(route('listing', $this->listing))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="robots" content="noindex', $html);
        $this->assertStringContainsString('rel="canonical" href="'.route('listing', $this->listing).'"', $html);
    }

    public function test_a_public_tombstone_is_not_treated_as_an_owner_only_view(): void
    {
        $this->listing->forceFill(['status' => ListingStatus::Sold])->save();

        $component = Livewire::test(ShowListing::class, ['listing' => $this->listing->fresh()])
            ->instance();

        $this->assertTrue($component->isTombstone());
        $this->assertFalse($component->isPrivateView());
    }

    public function test_a_tombstone_shows_what_is_still_for_sale(): void
    {
        $part = Part::where('category', 'gpu')->firstOrFail();

        $this->listing->forceFill(['status' => ListingStatus::Sold, 'part_id' => $part->id])->save();

        $alive = Listing::factory()->create([
            'user_id'  => User::factory()->create()->id,
            'status'   => ListingStatus::Active,
            'category' => 'gpu',
            'part_id'  => $part->id,
            'title'    => 'Още една такава карта',
            'city_id'  => City::first()->id,
        ]);

        Livewire::test(ShowListing::class, ['listing' => $this->listing->fresh()])
            ->assertSee('Подобни активни обяви')
            ->assertSee($alive->title);
    }

    /** The sold listing must never appear in its own "similar" block. */
    public function test_a_tombstone_does_not_recommend_itself(): void
    {
        $this->listing->forceFill(['status' => ListingStatus::Sold])->save();

        $similar = Livewire::test(ShowListing::class, ['listing' => $this->listing->fresh()])
            ->viewData('similar');

        $this->assertFalse(
            $similar->contains(fn (Listing $l) => $l->id === $this->listing->id),
        );
    }

    public function test_an_empty_similar_block_offers_an_alert_rather_than_a_dead_end(): void
    {
        $this->listing->forceFill(['status' => ListingStatus::Sold])->save();

        Livewire::test(ShowListing::class, ['listing' => $this->listing->fresh()])
            ->assertSee('Няма активни обяви за този модел');
    }

    /** An active listing is untouched by any of this. */
    public function test_a_live_listing_still_sells(): void
    {
        Livewire::test(ShowListing::class, ['listing' => $this->listing])
            ->assertDontSee('Продадено')
            ->assertDontSee('Подобни активни обяви');
    }
}
