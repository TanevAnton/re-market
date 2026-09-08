<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Home;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The front page.
 *
 * The thing worth protecting here is that it tells the truth: the counts are
 * real counts, a listing that is not public does not appear on the most public
 * page on the site, and the sections that have nothing in them say nothing
 * rather than rendering an empty rail.
 */
class HomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::factory()->create($attributes + [
            'user_id' => User::factory()->create()->id,
            'status'  => ListingStatus::Active,
        ]);
    }

    public function test_a_guest_gets_the_home_page(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Категории')
            ->assertSee('Защо не е като другите обяви');
    }

    /** The name did not change when the URL did. */
    public function test_browse_still_answers_on_its_route_name(): void
    {
        $this->get(route('browse'))->assertOk();
        $this->assertSame(url('/obiavi'), route('browse'));
        $this->assertSame(url('/'), route('home'));
    }

    public function test_every_category_is_listed_even_the_empty_ones(): void
    {
        // A marketplace that hides what it has none of tells a first visitor
        // nothing about what it is for.
        $categories = Livewire::test(Home::class)->instance()->categories();

        $this->assertCount(count(config('catalog.categories')), $categories);
        $this->assertContains('gpu', array_column($categories, 'key'));
    }

    public function test_category_counts_are_real_and_busiest_first(): void
    {
        $this->listing(['category' => 'gpu']);
        $this->listing(['category' => 'gpu']);
        $this->listing(['category' => 'cpu']);

        $categories = collect(Livewire::test(Home::class)->instance()->categories());

        $this->assertSame('gpu', $categories->first()['key']);
        $this->assertSame(2, $categories->firstWhere('key', 'gpu')['count']);
        $this->assertSame(1, $categories->firstWhere('key', 'cpu')['count']);
        $this->assertSame(0, $categories->firstWhere('key', 'monitor')['count']);
    }

    public function test_the_newest_listings_are_shown_newest_first(): void
    {
        $old = $this->listing(['published_at' => now()->subDays(3)]);
        $new = $this->listing(['published_at' => now()]);

        $newest = Livewire::test(Home::class)->instance()->newest();

        $this->assertSame($new->id, $newest->first()->id);
        $this->assertTrue($newest->contains('id', $old->id));
    }

    /**
     * The home page is the most public page on the site. A listing held for
     * review appearing on it would defeat the entire moderation gate.
     */
    public function test_a_listing_awaiting_review_never_reaches_the_front_page(): void
    {
        $held = $this->listing(['status' => ListingStatus::PendingReview]);

        $this->get(route('home'))->assertDontSee($held->title);
    }

    public function test_a_removed_listing_never_reaches_the_front_page(): void
    {
        $removed = $this->listing(['status' => ListingStatus::Removed]);

        $this->get(route('home'))->assertDontSee($removed->title);
    }

    /**
     * With no views recorded the rail would be an arbitrary ordering presented
     * as a ranking, so it does not render at all.
     */
    public function test_the_most_viewed_rail_stays_empty_until_something_has_been_viewed(): void
    {
        $this->listing(['view_count' => 0]);

        $this->assertCount(0, Livewire::test(Home::class)->instance()->mostViewed());
    }

    public function test_the_most_viewed_rail_ranks_by_views(): void
    {
        $quiet   = $this->listing(['view_count' => 3]);
        $popular = $this->listing(['view_count' => 900]);

        $viewed = Livewire::test(Home::class)->instance()->mostViewed();

        $this->assertSame($popular->id, $viewed->first()->id);
        $this->assertTrue($viewed->contains('id', $quiet->id));
    }

    public function test_the_stats_count_what_they_say_they_count(): void
    {
        $seller = User::factory()->create();

        Listing::factory()->count(3)->create([
            'user_id' => $seller->id,
            'status'  => ListingStatus::Active,
        ]);
        $this->listing(['status' => ListingStatus::Expired]);

        $stats = Livewire::test(Home::class)->instance()->stats();

        $this->assertSame(3, $stats['listings']);
        // Three listings, one seller - the number is sellers, not listings.
        $this->assertSame(1, $stats['sellers']);
        $this->assertSame(0, $stats['deals']);
    }

    public function test_the_search_box_submits_to_browse(): void
    {
        $this->get(route('home'))
            ->assertSee('action="'.route('browse').'"', false)
            ->assertSee('name="q"', false);
    }

    public function test_popular_models_link_to_a_search(): void
    {
        $listing = $this->listing();

        $parts = Livewire::test(Home::class)->instance()->popularParts();

        $this->assertNotEmpty($parts);
        $this->assertSame(1, $parts[0]['count']);
        $this->assertSame($listing->part->fullName(), $parts[0]['name']);
    }
}
