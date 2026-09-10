<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Enums\ModerationTrigger;
use App\Livewire\Listings\EditListing;
use App\Livewire\ShowListing;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\ModerationItem;
use App\Models\User;
use App\Notifications\ModerationDecision;
use App\Services\Moderation\ModerationService;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Three changes that all touch the same screens: who may take a listing down
 * from the page it lives on, which photo leads it, and whether the handwritten
 * note is still a wall in front of publishing.
 */
class ListingPhotosAndModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $admin;
    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller = User::factory()->create();
        $this->admin  = User::factory()->create(['is_admin' => true]);

        $this->listing = Listing::factory()->create([
            'user_id' => $this->seller->id,
            'status'  => ListingStatus::Active,
        ]);
    }

    /** @return list<ListingImage> */
    private function images(int $count = 3): array
    {
        $made = [];

        foreach (range(0, $count - 1) as $position) {
            $made[] = ListingImage::create([
                'listing_id' => $this->listing->id,
                'path'       => "listings/photo-{$position}.jpg",
                'width'      => 1200,
                'height'     => 900,
                'bytes'      => 100000,
                'position'   => $position,
            ]);
        }

        return $made;
    }

    // --- admin takedown --------------------------------------------------

    public function test_a_moderator_can_take_a_listing_down_from_its_own_page(): void
    {
        Notification::fake();

        Livewire::actingAs($this->admin)
            ->test(ShowListing::class, ['listing' => $this->listing])
            ->call('startRemove')
            ->set('reason', 'misleading')
            ->set('facts', 'снимките показват друг модел, а не описания RTX 4070')
            ->call('remove')
            ->assertHasNoErrors();

        $this->assertSame(ListingStatus::Removed, $this->listing->fresh()->status);

        // The same door as the queue, so the same record and the same message.
        $item = ModerationItem::where('subject_id', $this->listing->id)->firstOrFail();

        $this->assertSame('rejected', $item->status);
        $this->assertSame($this->admin->id, $item->decided_by);
        $this->assertStringContainsString('друг модел', $item->statement_of_reasons);

        Notification::assertSentTo($this->seller, ModerationDecision::class);
    }

    /**
     * The length floor is the whole point of the field. Without it the
     * statement of reasons is the category typed out again, which tells the
     * seller nothing they can act on or dispute.
     */
    public function test_a_takedown_needs_the_facts_it_relies_on(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ShowListing::class, ['listing' => $this->listing])
            ->call('startRemove')
            ->set('reason', 'misleading')
            ->set('facts', 'лошо')
            ->call('remove')
            ->assertHasErrors('facts');

        $this->assertSame(ListingStatus::Active, $this->listing->fresh()->status);
    }

    public function test_an_ordinary_user_gets_no_takedown_controls(): void
    {
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(ShowListing::class, ['listing' => $this->listing])
            ->assertSet('removing', false)
            ->assertDontSee('Премахни обявата');
    }

    /**
     * The button is hidden for a moderator's own listing, but hiding a button
     * is not a permission check - anything reachable from the browser can be
     * called directly.
     */
    public function test_a_moderator_cannot_take_down_their_own_listing(): void
    {
        $own = Listing::factory()->create([
            'user_id' => $this->admin->id,
            'status'  => ListingStatus::Active,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ShowListing::class, ['listing' => $own])
            ->set('reason', 'misleading')
            ->set('facts', 'нещо съвсем нередно в тази обява')
            ->call('remove')
            ->assertForbidden();

        $this->assertSame(ListingStatus::Active, $own->fresh()->status);
    }

    /**
     * A listing already waiting in the queue must not end up with a second
     * pending item. Deciding it from the page has to close the one that is
     * there, or a moderator opens the queue later and is asked to review
     * something that is already gone.
     */
    public function test_taking_down_a_queued_listing_settles_the_pending_item(): void
    {
        $queued = app(ModerationService::class)
            ->enqueue($this->listing, ModerationTrigger::NewAccount);

        Livewire::actingAs($this->admin)
            ->test(ShowListing::class, ['listing' => $this->listing])
            ->set('reason', 'stock_photos')
            ->set('facts', 'снимките са свалени от сайта на производителя')
            ->call('remove')
            ->assertHasNoErrors();

        $this->assertSame('rejected', $queued->fresh()->status);
        $this->assertSame(0, ModerationItem::where('subject_id', $this->listing->id)
            ->where('status', 'pending')->count());
    }

    // --- the main photo --------------------------------------------------

    public function test_the_cover_image_is_the_first_by_position(): void
    {
        [$first, $second] = $this->images();

        $this->assertSame($first->id, $this->listing->fresh()->coverImage()->id);

        Livewire::actingAs($this->seller)
            ->test(EditListing::class, ['listing' => $this->listing])
            ->call('makePrimary', $second->id);

        // Browse, search and the home page all read coverImage(), so promoting
        // a photo has to move it everywhere at once rather than only on the
        // listing page.
        $this->assertSame($second->id, $this->listing->fresh()->coverImage()->id);
    }

    public function test_promoting_a_photo_leaves_no_duplicate_positions(): void
    {
        $images = $this->images(4);

        Livewire::actingAs($this->seller)
            ->test(EditListing::class, ['listing' => $this->listing])
            ->call('makePrimary', $images[2]->id);

        $positions = $this->listing->fresh()->images->pluck('position')->all();

        // Two rows sharing a position makes "first" depend on insertion order,
        // which is how a cover photo changes on its own after an unrelated edit.
        $this->assertSame([0, 1, 2, 3], $positions);
        $this->assertSame($images[2]->id, $this->listing->fresh()->images->first()->id);
    }

    public function test_a_stranger_cannot_open_someone_elses_photo_editor(): void
    {
        $this->images();
        $stranger = User::factory()->create();

        // The guard is on mount, so a stranger never reaches makePrimary at
        // all - which is the right place for it: every action on this screen
        // is protected by one check rather than each remembering its own.
        //
        // assertNotFound, not expectException: Livewire catches the abort and
        // turns it into a response status, the same way assertForbidden works
        // for the moderator check above.
        Livewire::actingAs($stranger)
            ->test(EditListing::class, ['listing' => $this->listing])
            ->assertNotFound();
    }

    /**
     * A photo belonging to a different listing must not move just because its
     * id was typed into a request - the editor is scoped to one listing and the
     * lookup has to be too.
     */
    public function test_promoting_a_photo_from_another_listing_does_nothing(): void
    {
        [$first] = $this->images();

        $other = Listing::factory()->create(['user_id' => $this->seller->id]);
        $stray = ListingImage::create([
            'listing_id' => $other->id,
            'path'       => 'listings/stray.jpg',
            'position'   => 0,
        ]);

        Livewire::actingAs($this->seller)
            ->test(EditListing::class, ['listing' => $this->listing])
            ->call('makePrimary', $stray->id);

        $this->assertSame($first->id, $this->listing->fresh()->coverImage()->id);
        $this->assertSame($other->id, $stray->fresh()->listing_id);
    }

    // --- the handwritten note --------------------------------------------

    public function test_the_note_photo_is_no_longer_required_to_publish(): void
    {
        $this->assertFalse(config('remarket.listings.require_timestamp_photo_for_private'));
    }

    public function test_the_mark_still_exists_and_now_toggles_off(): void
    {
        [$image] = $this->images();

        $component = Livewire::actingAs($this->seller)
            ->test(EditListing::class, ['listing' => $this->listing]);

        $component->call('markTimestamp', $image->id);
        $this->assertTrue($image->fresh()->is_timestamp_photo);

        // Unmarking matters now that the note is optional: a seller who marked
        // the wrong photo would otherwise be stuck with a badge on the listing
        // claiming a note that is not in the picture.
        $component->call('markTimestamp', $image->id);
        $this->assertFalse($image->fresh()->is_timestamp_photo);
    }

    public function test_only_one_photo_can_carry_the_note_mark(): void
    {
        [$first, $second] = $this->images();

        $component = Livewire::actingAs($this->seller)
            ->test(EditListing::class, ['listing' => $this->listing]);

        $component->call('markTimestamp', $first->id);
        $component->call('markTimestamp', $second->id);

        $this->assertFalse($first->fresh()->is_timestamp_photo);
        $this->assertTrue($second->fresh()->is_timestamp_photo);
    }
}
