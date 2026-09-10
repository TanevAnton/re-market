<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\ModerationTrigger;
use App\Enums\RejectionReason;
use App\Enums\ReportReason;
use App\Livewire\BrowseListings;
use App\Livewire\Favorites\FavoriteButton;
use App\Livewire\Favorites\MyFavorites;
use App\Livewire\SavedSearches\MySearches;
use App\Livewire\SavedSearches\PartAlert;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\Part;
use App\Models\SavedSearch;
use App\Models\User;
use App\Notifications\ReportOutcome;
use App\Notifications\SavedSearchMatches;
use App\Services\Moderation\ModerationService;
use App\Services\Moderation\ReportService;
use App\Services\Search\SavedSearchService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two things a buyer can do that involve nobody else - keep a shortlist and
 * leave a standing want - plus the outcome message the people who report things
 * were owed and never got.
 */
class FavoritesAndSearchesTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private User $seller;
    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->buyer  = User::factory()->create();
        $this->seller = User::factory()->create();

        $this->listing = Listing::factory()->create([
            'user_id'         => $this->seller->id,
            'status'          => ListingStatus::Active,
            'category'        => 'gpu',
            'price_cents'     => 50000,
            'min_offer_cents' => null,

            // Pinned, not left to the factory, which scatters published_at
            // across 45 days at random. Every alert test here turns on whether
            // this listing is newer or older than a watermark, so a random
            // value makes them pass or fail by luck.
            //
            // Five minutes ago: older than a search saved during the test
            // (so it is correctly treated as backlog) and newer than a
            // watermark set an hour back (so it is correctly a new match).
            'published_at'    => now()->subMinutes(5),
            'bumped_at'       => now()->subMinutes(5),
        ]);
    }

    // --- favorites -------------------------------------------------------

    public function test_a_buyer_can_save_and_unsave_a_listing(): void
    {
        $component = Livewire::actingAs($this->buyer)
            ->test(FavoriteButton::class, ['listing' => $this->listing]);

        $component->call('toggle')->assertSet('saved', true);
        $this->assertDatabaseHas('favorites', [
            'user_id' => $this->buyer->id, 'listing_id' => $this->listing->id,
        ]);

        $component->call('toggle')->assertSet('saved', false);
        $this->assertDatabaseMissing('favorites', [
            'user_id' => $this->buyer->id, 'listing_id' => $this->listing->id,
        ]);
    }

    /**
     * Double-clicked, or open in two tabs. The unique index does its job; the
     * intent was "save it", so the second click must not be an error page.
     */
    public function test_saving_twice_is_not_an_error(): void
    {
        Favorite::create(['user_id' => $this->buyer->id, 'listing_id' => $this->listing->id]);

        Livewire::actingAs($this->buyer)
            ->test(FavoriteButton::class, ['listing' => $this->listing])
            ->set('saved', false)
            ->call('toggle')
            ->assertSet('saved', true)
            ->assertHasNoErrors();

        $this->assertSame(1, Favorite::where('listing_id', $this->listing->id)->count());
    }

    public function test_a_guest_is_sent_to_log_in_rather_than_silently_ignored(): void
    {
        Livewire::test(FavoriteButton::class, ['listing' => $this->listing])
            ->call('toggle')
            ->assertRedirect(route('login'));

        $this->assertSame(0, Favorite::count());
    }

    /**
     * A saved listing that sells is kept and labelled, not removed. A card that
     * silently disappears leaves the buyer wondering whether they imagined
     * saving it; the price it ended at is usually what they wanted to know.
     */
    public function test_a_sold_listing_stays_on_the_shortlist(): void
    {
        Favorite::create(['user_id' => $this->buyer->id, 'listing_id' => $this->listing->id]);
        $this->listing->forceFill(['status' => ListingStatus::Sold])->save();

        Livewire::actingAs($this->buyer)
            ->test(MyFavorites::class)
            ->assertOk()
            ->assertSee($this->listing->title);
    }

    public function test_the_shortlist_is_only_your_own(): void
    {
        Favorite::create(['user_id' => $this->seller->id, 'listing_id' => $this->listing->id]);

        Livewire::actingAs($this->buyer)
            ->test(MyFavorites::class)
            ->assertDontSee($this->listing->title);
    }

    // --- saved searches --------------------------------------------------

    public function test_filters_can_be_saved_from_browse(): void
    {
        Livewire::actingAs($this->buyer)
            ->test(BrowseListings::class)
            ->set('category', 'gpu')
            ->set('priceMax', '600')
            ->call('startSaveSearch')
            ->assertSet('savingSearch', true)
            ->call('saveSearch')
            ->assertHasNoErrors();

        $saved = SavedSearch::where('user_id', $this->buyer->id)->firstOrFail();

        // Stored in the URL's vocabulary, so a saved search re-opens as an
        // ordinary browse page and no translation layer exists to drift.
        $this->assertSame('gpu', $saved->criteria['kat']);
        $this->assertSame('600', $saved->criteria['do']);
        $this->assertTrue($saved->notify);
    }

    public function test_an_empty_search_is_not_offered_for_saving(): void
    {
        $component = Livewire::actingAs($this->buyer)->test(BrowseListings::class);

        // "Everything" alerts on every listing posted and gets muted in a day.
        $this->assertFalse($component->instance()->hasFilters());
    }

    public function test_saving_the_same_search_twice_updates_it(): void
    {
        $searches = app(SavedSearchService::class);

        $searches->save($this->buyer, 'Първо име', ['kat' => 'gpu', 'do' => '600']);
        $searches->save($this->buyer, 'Второ име', ['do' => '600', 'kat' => 'gpu']);

        // Same filters in a different order is the same search. Without this,
        // clicking save again - which people do when unsure it worked -
        // silently doubles their alerts.
        $this->assertSame(1, SavedSearch::where('user_id', $this->buyer->id)->count());
        $this->assertSame('Второ име', SavedSearch::first()->name);
    }

    public function test_empty_filters_are_stripped_before_comparison(): void
    {
        $searches = app(SavedSearchService::class);

        $searches->save($this->buyer, 'A', ['kat' => 'gpu', 'q' => '', 'sast' => []]);
        $searches->save($this->buyer, 'B', ['kat' => 'gpu']);

        $this->assertSame(1, SavedSearch::where('user_id', $this->buyer->id)->count());
    }

    public function test_a_saved_search_can_be_muted_and_deleted(): void
    {
        $search = app(SavedSearchService::class)
            ->save($this->buyer, 'GPU до 600', ['kat' => 'gpu', 'do' => '600']);

        $component = Livewire::actingAs($this->buyer)->test(MySearches::class);

        $component->call('toggleNotify', $search->id);
        $this->assertFalse($search->fresh()->notify);

        $component->call('delete', $search->id);
        $this->assertNull($search->fresh());
    }

    /**
     * The ids travel through the browser, so every action re-establishes
     * ownership. Filtering only in render() would leave delete addressable by
     * id alone.
     */
    public function test_someone_elses_saved_search_cannot_be_touched(): void
    {
        $mine = app(SavedSearchService::class)
            ->save($this->seller, 'Чуждо', ['kat' => 'gpu']);

        Livewire::actingAs($this->buyer)
            ->test(MySearches::class)
            ->call('delete', $mine->id);

        $this->assertNotNull($mine->fresh());
    }

    // --- alerts ----------------------------------------------------------

    public function test_new_matching_listings_notify_the_saver(): void
    {
        $search = app(SavedSearchService::class)
            ->save($this->buyer, 'GPU до 600', ['kat' => 'gpu', 'do' => '600']);

        $search->forceFill(['last_notified_at' => now()->subHour()])->save();

        Notification::fake();
        $this->artisan('remarket:notify-saved-searches')->assertSuccessful();

        Notification::assertSentTo($this->buyer, SavedSearchMatches::class);
        $this->assertTrue($search->fresh()->last_notified_at->isAfter(now()->subMinute()));
    }

    /**
     * Saving a broad search must not immediately mail someone every matching
     * listing already on the site. That reads as spam on first contact, which
     * is the worst possible moment for it.
     */
    public function test_a_new_search_does_not_report_the_existing_backlog(): void
    {
        app(SavedSearchService::class)->save($this->buyer, 'GPU', ['kat' => 'gpu']);

        Notification::fake();
        $this->artisan('remarket:notify-saved-searches');

        Notification::assertNothingSent();
    }

    public function test_a_muted_search_notifies_nobody(): void
    {
        $search = app(SavedSearchService::class)->save($this->buyer, 'GPU', ['kat' => 'gpu']);
        $search->forceFill(['notify' => false, 'last_notified_at' => now()->subDay()])->save();

        Notification::fake();
        $this->artisan('remarket:notify-saved-searches');

        Notification::assertNothingSent();
    }

    public function test_a_search_that_matches_nothing_stays_quiet(): void
    {
        $search = app(SavedSearchService::class)
            ->save($this->buyer, 'Евтини', ['kat' => 'gpu', 'do' => '10']);

        $search->forceFill(['last_notified_at' => now()->subDay()])->save();

        Notification::fake();
        $this->artisan('remarket:notify-saved-searches');

        Notification::assertNothingSent();
    }

    public function test_the_part_page_alert_creates_an_ordinary_saved_search(): void
    {
        $part = Part::query()->where('is_published', true)->firstOrFail();

        $component = Livewire::actingAs($this->buyer)
            ->test(PartAlert::class, ['part' => $part]);

        $component->call('toggle');

        $saved = SavedSearch::where('user_id', $this->buyer->id)->firstOrFail();

        // An ordinary saved search, not a second kind of alert - so it is
        // switched off in the same place as everything else. A mechanism that
        // only unsubscribes from one type of message is how people end up
        // unable to make the emails stop.
        $this->assertSame($part->model, $saved->criteria['q']);
        $this->assertSame($part->category, $saved->criteria['kat']);

        $component->call('toggle');
        $this->assertSame(0, SavedSearch::where('user_id', $this->buyer->id)->count());
    }

    // --- report outcomes -------------------------------------------------

    /**
     * settle() used to be one bulk update returning a row count. A bulk update
     * loads no models, so there was nothing to notify - the method's own
     * docblock said "tell everyone who reported this" and it told no one.
     */
    public function test_a_reporter_hears_what_happened_to_their_report(): void
    {
        $reporter   = User::factory()->create();
        $moderator  = User::factory()->create(['is_admin' => true]);
        $reports    = app(ReportService::class);
        $moderation = app(ModerationService::class);

        $reports->file($this->listing, ReportReason::Scam, 'снимките са от друга обява', $reporter);

        $item = $moderation->enqueue($this->listing, ModerationTrigger::Reported);

        Notification::fake();
        $moderation->reject($item, $moderator, RejectionReason::StockPhotos, 'снимките са чужди');

        Notification::assertSentTo($reporter, ReportOutcome::class);
    }

    public function test_a_reporter_is_told_even_when_the_listing_stays_up(): void
    {
        $reporter   = User::factory()->create();
        $moderator  = User::factory()->create(['is_admin' => true]);

        app(ReportService::class)
            ->file($this->listing, ReportReason::Scam, 'изглежда съмнително', $reporter);

        $item = app(ModerationService::class)->enqueue($this->listing, ModerationTrigger::Reported);

        Notification::fake();
        app(ModerationService::class)->approve($item, $moderator);

        // "We looked and left it up" is still an outcome, and Art. 16(5) owes
        // it to the notifier. Silence is indistinguishable from nobody looking.
        Notification::assertSentTo($reporter, ReportOutcome::class);
    }

    public function test_the_reported_seller_does_not_get_the_reporter_message(): void
    {
        $reporter  = User::factory()->create();
        $moderator = User::factory()->create(['is_admin' => true]);

        app(ReportService::class)
            ->file($this->listing, ReportReason::Scam, 'снимките са от друга обява', $reporter);

        $item = app(ModerationService::class)->enqueue($this->listing, ModerationTrigger::Reported);

        Notification::fake();
        app(ModerationService::class)
            ->reject($item, $moderator, RejectionReason::StockPhotos, 'снимките са чужди');

        Notification::assertNotSentTo($this->seller, ReportOutcome::class);
    }
}
