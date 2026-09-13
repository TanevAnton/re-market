<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Listings\CreateListing;
use App\Models\City;
use App\Models\Listing;
use App\Models\ListingDraft;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Two ways of not filling the wizard in from scratch.
 *
 * Supply is the bottleneck on this site and step 3 is where it leaks, so both
 * of these are supply features wearing convenience clothes: one recovers the
 * seller who walked away, the other spares the seller who is posting the same
 * model for the fourth time.
 */
class ListingDraftTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);
    }

    // --- drafts -----------------------------------------------------------

    public function test_moving_between_steps_writes_a_draft(): void
    {
        Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->set('category', 'gpu')
            ->call('next');

        $draft = ListingDraft::where('user_id', $this->seller->id)->first();

        $this->assertNotNull($draft);
        $this->assertSame('gpu', $draft->payload['category']);
        $this->assertSame(2, $draft->step);
    }

    /**
     * Opening the page is not starting a listing. A draft row created by
     * merely arriving would prompt on the next visit about a form nobody
     * filled in, and the prompt would stop meaning anything.
     */
    public function test_simply_opening_the_wizard_writes_nothing(): void
    {
        Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->call('saveDraft');

        $this->assertDatabaseCount('listing_drafts', 0);
    }

    public function test_a_returning_seller_is_offered_the_draft_rather_than_given_it(): void
    {
        $this->draftFor($this->seller, ['title' => 'RTX 4070 на сервиз', 'description' => str_repeat('а', 30)]);

        Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            // Offered…
            ->assertSet('draftAvailable', true)
            // …and NOT silently poured into the form.
            ->assertSet('title', '')
            ->call('resumeDraft')
            ->assertSet('title', 'RTX 4070 на сервиз')
            ->assertSet('step', 3)
            ->assertSet('draftAvailable', false);
    }

    public function test_an_empty_draft_is_not_offered_back(): void
    {
        $this->draftFor($this->seller, ['category' => 'gpu']);   // nothing substantial

        Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->assertSet('draftAvailable', false);

        // And it is cleared rather than left to prompt forever.
        $this->assertDatabaseCount('listing_drafts', 0);
    }

    public function test_one_sellers_draft_is_never_offered_to_another(): void
    {
        $this->draftFor($this->seller, ['title' => 'Не твоята обява']);

        Livewire::actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->test(CreateListing::class)
            ->assertSet('draftAvailable', false)
            ->assertDontSee('Не твоята обява');
    }

    /**
     * Discarding is the one destructive button here, and it has to take the
     * photos with it — those files are on disk from the moment they were
     * uploaded and nothing else references them.
     */
    public function test_discarding_a_draft_deletes_its_photos(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('listings/abandoned.jpg', 'x');
        Storage::disk('public')->put('listings/abandoned-thumb.jpg', 'x');

        $this->draftFor($this->seller, [
            'title'  => 'Изоставена обява',
            'stored' => [['path' => 'listings/abandoned.jpg', 'thumb' => 'listings/abandoned-thumb.jpg']],
        ]);

        Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->call('discardDraft')
            ->assertSet('draftAvailable', false);

        $this->assertDatabaseCount('listing_drafts', 0);
        Storage::disk('public')->assertMissing('listings/abandoned.jpg');
        Storage::disk('public')->assertMissing('listings/abandoned-thumb.jpg');
    }

    /**
     * The two ways of ending a draft are not interchangeable, and picking the
     * wrong one at the publish step is the expensive mistake here.
     *
     * discard() takes the photos with it, which is right for an abandoned
     * wizard. delete() leaves them, which is the only correct choice once they
     * have become a published listing's photographs — publish() calls that one
     * on purpose, one line after creating the ad those files belong to.
     */
    public function test_ending_a_draft_by_deleting_it_leaves_the_photos_alone(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('listings/keep.jpg', 'x');

        $draft = $this->draftFor($this->seller, [
            'title'  => 'Публикувана',
            'stored' => [['path' => 'listings/keep.jpg', 'thumb' => null]],
        ]);

        $draft->delete();

        $this->assertDatabaseCount('listing_drafts', 0);
        Storage::disk('public')->assertExists('listings/keep.jpg');
    }

    /** A missing thumb is null in the payload, and delete() takes only strings. */
    public function test_discarding_survives_a_photo_with_no_thumbnail(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('listings/nothumb.jpg', 'x');

        $draft = $this->draftFor($this->seller, [
            'title'  => 'Без миниатюра',
            'stored' => [['path' => 'listings/nothumb.jpg', 'thumb' => null]],
        ]);

        $draft->discard();

        Storage::disk('public')->assertMissing('listings/nothumb.jpg');
    }

    /**
     * The reason drafts are not Listing rows.
     *
     * New accounts have their first listings held for review, gated on
     * $user->listings()->count(). If a draft were a listing, abandoning two
     * wizards would push a spammer past that gate before they posted anything.
     */
    public function test_a_draft_does_not_count_as_a_listing(): void
    {
        $this->draftFor($this->seller, ['title' => 'Чернова']);

        $this->assertSame(0, $this->seller->listings()->count());
    }

    // --- pruning ----------------------------------------------------------

    public function test_pruning_removes_stale_drafts_and_their_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('listings/old.jpg', 'x');

        $draft = $this->draftFor($this->seller, [
            'title'  => 'Стара чернова',
            'stored' => [['path' => 'listings/old.jpg', 'thumb' => null]],
        ]);
        $draft->forceFill(['updated_at' => now()->subDays(45)])->save();

        $this->artisan('remarket:prune-drafts')->assertSuccessful();

        $this->assertDatabaseCount('listing_drafts', 0);
        Storage::disk('public')->assertMissing('listings/old.jpg');
    }

    public function test_a_recent_draft_survives_pruning(): void
    {
        $this->draftFor($this->seller, ['title' => 'Скорошна']);

        $this->artisan('remarket:prune-drafts')->assertSuccessful();

        $this->assertDatabaseCount('listing_drafts', 1);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $draft = $this->draftFor($this->seller, ['title' => 'Стара']);
        $draft->forceFill(['updated_at' => now()->subDays(45)])->save();

        $this->artisan('remarket:prune-drafts', ['--dry' => true])->assertSuccessful();

        $this->assertDatabaseCount('listing_drafts', 1);
    }

    // --- duplicating ------------------------------------------------------

    public function test_duplicating_prefills_the_wizard_and_lands_on_photos(): void
    {
        $source = Listing::factory()->create([
            'user_id'        => $this->seller->id,
            'status'         => ListingStatus::Active,
            'category'       => 'gpu',
            'title'          => 'RTX 4070 Super, гаранция',
            'price_cents'    => 54900,
            'offers_enabled' => true,
            'city_id'        => City::first()->id,
        ]);

        Livewire::actingAs($this->seller)
            ->test(CreateListing::class, ['from' => $source])
            ->assertSet('category', 'gpu')
            ->assertSet('title', 'RTX 4070 Super, гаранция')
            ->assertSet('price', '549')
            // Step 3 is where the photos are, and photos are the only thing
            // that has to be redone.
            ->assertSet('step', 3)
            ->assertSet('copiedFrom', true);
    }

    /**
     * Photos are not carried over, and not merely for tidiness: the screener
     * matches perceptual hashes across every listing on the site and queues
     * anything close, same seller or not. Copying them would send every
     * duplicate to moderation.
     */
    public function test_duplicating_carries_no_photos(): void
    {
        $source = Listing::factory()->create(['user_id' => $this->seller->id]);

        Livewire::actingAs($this->seller)
            ->test(CreateListing::class, ['from' => $source])
            ->assertSet('stored', [])
            ->assertSet('timestampIndex', null);
    }

    /**
     * A warranty date belongs to one physical item. Carried over into a
     * prefilled form nobody re-reads, it becomes a promise about a different
     * card that the seller cannot honour.
     */
    public function test_duplicating_does_not_carry_the_warranty_date(): void
    {
        $source = Listing::factory()->create([
            'user_id'        => $this->seller->id,
            'warranty_until' => now()->addMonths(6)->toDateString(),
        ]);

        Livewire::actingAs($this->seller)
            ->test(CreateListing::class, ['from' => $source])
            ->assertSet('warranty_until', null);
    }

    public function test_a_stranger_cannot_duplicate_someone_elses_listing(): void
    {
        $source = Listing::factory()->create(['user_id' => $this->seller->id]);

        Livewire::actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->test(CreateListing::class, ['from' => $source])
            ->assertNotFound();
    }

    /** A listing a moderator took down is not a one-click template for reposting it. */
    public function test_a_removed_listing_cannot_be_duplicated(): void
    {
        $source = Listing::factory()->create([
            'user_id' => $this->seller->id,
            'status'  => ListingStatus::Removed,
        ]);

        Livewire::actingAs($this->seller)
            ->test(CreateListing::class, ['from' => $source])
            ->assertNotFound();
    }

    // --- what the client may not set --------------------------------------

    /**
     * Livewire lets the browser update any public property. None of these are
     * bound in the view, and all three decide something: which validation has
     * run, which files the listing points at, and which photo carries the
     * handwritten-note badge.
     */
    public function test_the_client_cannot_set_the_step_or_the_stored_photos(): void
    {
        foreach (['step' => 4, 'stored' => [['path' => 'listings/someone-else.jpg']], 'timestampIndex' => 0] as $property => $value) {
            try {
                Livewire::actingAs($this->seller)
                    ->test(CreateListing::class)
                    ->set($property, $value);

                $this->fail("[{$property}] accepted an update from the client");
            } catch (\Throwable $e) {
                /*
                 * Caught broadly and asserted on, rather than type-hinted in
                 * the catch clause.
                 *
                 * A catch on a class that does not exist silently never
                 * matches - PHP says nothing and php -l cannot see it - so a
                 * mistyped namespace turns this test into one that always
                 * errors for a reason unrelated to what it checks. It has
                 * cost time twice now. assertInstanceOf refuses an unknown
                 * class name out loud.
                 */
                $this->assertInstanceOf(CannotUpdateLockedPropertyException::class, $e);
            }
        }
    }

    // --- helpers ----------------------------------------------------------

    private function draftFor(User $user, array $payload): ListingDraft
    {
        return ListingDraft::create([
            'user_id'  => $user->id,
            'payload'  => $payload + ['category' => 'gpu'],
            'step'     => 3,
            'title'    => $payload['title'] ?? null,
            'category' => 'gpu',
        ]);
    }
}
