<?php

namespace Tests\Feature;

use App\Enums\BundleStatus;
use App\Enums\ListingStatus;
use App\Enums\RejectionReason;
use App\Livewire\Bundles\ManageBundle;
use App\Livewire\Bundles\ShowBundle;
use App\Models\Bundle;
use App\Models\City;
use App\Models\Listing;
use App\Models\User;
use App\Services\Listings\BundleService;
use App\Services\Moderation\ModerationService;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * „Продавам цялата конфигурация, или на части."
 *
 * The feature exists because that sentence is already in half the descriptions
 * on the site, written by hand, with no way to act on it. What these tests
 * mostly guard is not the happy path but the four refusals and the withdrawal
 * rule — the places where a bundle could advertise something that is not true.
 */
class BundleTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $moderator;
    private BundleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        $this->seller  = $this->sellerWithHistory();
        $this->service = app(BundleService::class);

        $this->moderator = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
            'is_admin'          => true,
        ]);
    }

    /**
     * A seller past the new-account hold.
     *
     * Two published listings is the threshold, and it is the same rule the
     * listing wizard uses — ModerationService::holdsNewSeller(). A test that
     * hard-codes „three listings" here would keep passing after somebody
     * changed the config and the feature stopped matching it.
     */
    private function sellerWithHistory(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        Listing::factory()->count(
            (int) config('remarket.antispam.moderated_listings_for_new_accounts', 2)
        )->create([
            'user_id'  => $user->id,
            'city_id'  => City::first()->id,
            'category' => 'gpu',
            'status'   => ListingStatus::Active,
        ]);

        return $user;
    }

    private function listing(array $attributes = [], ?User $owner = null): Listing
    {
        return Listing::factory()->create([
            'user_id'     => ($owner ?? $this->seller)->id,
            'city_id'     => City::first()->id,
            'category'    => 'gpu',
            'status'      => ListingStatus::Active,
            'price_cents' => 300_00,
            ...$attributes,
        ]);
    }

    /**
     * A live bundle, moderated the way a real one is.
     *
     * A seller's FIRST bundle is held for review, so most of these tests would
     * otherwise be looking at a page nobody can see. It is approved here the
     * way a moderator would rather than by forcing the column — which also
     * means every test below exercises the approve branch, and would have
     * caught it being missing.
     *
     * @param  list<Listing>  $members
     */
    private function bundle(array $members, ?int $priceCents = null): Bundle
    {
        $bundle = $this->service->publish(
            $this->service->create($this->seller, [
                'title'       => 'Цяла машина — RTX 3070 и Ryzen 5',
                'price_cents' => $priceCents,
            ], array_map(fn (Listing $l) => $l->id, $members)),
            $this->seller,
        );

        if ($bundle->status === BundleStatus::PendingReview) {
            app(ModerationService::class)->approve(
                $bundle->moderationItems()->where('status', 'pending')->firstOrFail(),
                $this->moderator,
            );

            $bundle = $bundle->fresh();
        }

        return $bundle;
    }

    // --- the four refusals ------------------------------------------------

    public function test_a_bundle_cannot_contain_somebody_elses_listing(): void
    {
        $mine     = $this->listing();
        $stranger = $this->listing([], User::factory()->create(['city_id' => City::first()->id]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('само свои обяви');

        $this->service->create($this->seller, ['title' => 'Комплект от чужди части'],
            [$mine->id, $stranger->id]);
    }

    /**
     * `listings.bundle_id` is a single column. Without this refusal the second
     * bundle silently steals the listing from the first, which then advertises
     * a part it no longer contains — at a price computed from it.
     */
    public function test_a_listing_cannot_be_in_two_bundles(): void
    {
        $a = $this->listing();
        $b = $this->listing();
        $c = $this->listing();

        $this->bundle([$a, $b]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('вече участва в друг комплект');

        $this->service->create($this->seller, ['title' => 'Втори комплект със същата карта'],
            [$a->id, $c->id]);
    }

    public function test_a_listing_that_is_not_for_sale_cannot_join(): void
    {
        $active = $this->listing();
        $sold   = $this->listing(['status' => ListingStatus::Sold]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('не е активна');

        $this->service->create($this->seller, ['title' => 'Комплект с продадена част'],
            [$active->id, $sold->id]);
    }

    /** One listing in a group is the listing, plus a page competing with it. */
    public function test_a_bundle_of_one_is_refused(): void
    {
        $only = $this->listing();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('поне 2');

        $this->service->create($this->seller, ['title' => 'Комплект от една обява'], [$only->id]);
    }

    // --- the withdrawal rule ----------------------------------------------

    public function test_the_package_price_holds_while_every_member_is_for_sale(): void
    {
        $bundle = $this->bundle([
            $this->listing(['price_cents' => 500_00]),
            $this->listing(['price_cents' => 300_00]),
        ], 700_00);

        $bundle->load('listings');

        $this->assertSame(800_00, $bundle->sumCents());
        $this->assertSame(700_00, $bundle->packagePrice());
        $this->assertSame(100_00, $bundle->savingCents());
        $this->assertSame(13, $bundle->savingPercent());
    }

    /**
     * THE RULE THE WHOLE DESIGN IS BUILT AROUND, and the reason there is no
     * `withdrawn` status: nothing has to remember to apply it. Nobody touches
     * the bundle here at all — a member sells, and the package price is gone
     * the next time anything asks for it.
     */
    public function test_the_package_price_disappears_when_a_member_sells(): void
    {
        $gpu = $this->listing(['price_cents' => 500_00]);
        $cpu = $this->listing(['price_cents' => 300_00]);

        $bundle = $this->bundle([$gpu, $cpu], 700_00);

        $gpu->forceFill(['status' => ListingStatus::Sold])->save();

        $bundle = $bundle->fresh()->load('listings');

        $this->assertNull($bundle->packagePrice());
        $this->assertNull($bundle->savingCents());
        $this->assertFalse($bundle->isComplete());
        $this->assertTrue($bundle->missing()->contains(fn ($l) => $l->id === $gpu->id));

        // And the number itself is still on the row, unchanged. The rule is
        // derived, so restoring the listing restores the price.
        $this->assertSame(700_00, $bundle->price_cents);
    }

    /** Reserved counts as gone: somebody has already agreed to buy that part. */
    public function test_a_reserved_member_also_withdraws_the_price(): void
    {
        $gpu = $this->listing(['price_cents' => 500_00]);
        $cpu = $this->listing(['price_cents' => 300_00]);

        $bundle = $this->bundle([$gpu, $cpu], 700_00);

        $gpu->forceFill(['status' => ListingStatus::Reserved])->save();

        $this->assertNull($bundle->fresh()->load('listings')->packagePrice());
    }

    /** A „discount" at or above the sum of the parts is not a discount. */
    public function test_a_package_price_above_the_sum_advertises_no_saving(): void
    {
        $bundle = $this->bundle([
            $this->listing(['price_cents' => 500_00]),
            $this->listing(['price_cents' => 300_00]),
        ], 900_00);

        $bundle->load('listings');

        $this->assertSame(900_00, $bundle->packagePrice());
        $this->assertNull($bundle->savingCents());
        $this->assertNull($bundle->savingPercent());
    }

    // --- dissolving -------------------------------------------------------

    /**
     * The one that would hurt most if it were wrong. `nullOnDelete` rather
     * than cascade, and this is what that decision means in practice.
     */
    public function test_dissolving_a_bundle_leaves_every_listing_intact(): void
    {
        $a = $this->listing();
        $b = $this->listing();

        $bundle = $this->bundle([$a, $b]);

        $this->service->dissolve($bundle, $this->seller);

        $this->assertNull($a->fresh()->bundle_id);
        $this->assertNull($b->fresh()->bundle_id);
        $this->assertSame(ListingStatus::Active, $a->fresh()->status);
        $this->assertSame(ListingStatus::Active, $b->fresh()->status);
        $this->assertSoftDeleted($bundle);
    }

    public function test_only_the_owner_can_dissolve_a_bundle(): void
    {
        $bundle   = $this->bundle([$this->listing(), $this->listing()]);
        $stranger = User::factory()->create(['city_id' => City::first()->id]);

        /*
         * 404, not 403: whether somebody else's bundle exists is not public.
         *
         * Asserted by exception CLASS, not by expectExceptionCode(404).
         * abort() throws NotFoundHttpException, whose getCode() is 0 — the 404
         * lives in getStatusCode(). The first version of this test asserted
         * the code and passed a wrong assertion for the right behaviour.
         */
        $this->expectException(NotFoundHttpException::class);

        $this->service->dissolve($bundle, $stranger);
    }

    // --- membership changes -----------------------------------------------

    public function test_removing_a_member_frees_its_listing(): void
    {
        $a = $this->listing();
        $b = $this->listing();
        $c = $this->listing();

        $bundle = $this->bundle([$a, $b, $c]);

        $this->service->update($bundle, ['title' => $bundle->title], [$a->id, $b->id], $this->seller);

        $this->assertNull($c->fresh()->bundle_id);
        $this->assertSame($bundle->id, $a->fresh()->bundle_id);
        $this->assertSame(2, $bundle->fresh()->listings()->count());
    }

    // --- moderation -------------------------------------------------------

    /**
     * THE FIRST BUNDLE IS HELD, and the reason is worth writing down because
     * the first version of this test asserted something that could never
     * happen.
     *
     * The listing rule holds a seller until they have two listings. A bundle
     * needs two listings to exist. So „new seller with a bundle" is not a
     * state that occurs, and a gate written only against that rule would have
     * been dead code — the feature would have shipped with moderation that
     * never once fired, and nothing would have complained.
     */
    public function test_a_sellers_first_bundle_is_held_for_review(): void
    {
        $bundle = $this->service->publish(
            $this->service->create($this->seller, ['title' => 'Първи комплект на този продавач'], [
                $this->listing()->id,
                $this->listing()->id,
            ]),
            $this->seller,
        );

        $this->assertSame(BundleStatus::PendingReview, $bundle->status);

        /*
         * The queue entry is the half that is easy to forget: a PendingReview
         * row with nothing in the queue is invisible to the public AND to
         * every moderator, and the seller waits for a review nobody scheduled.
         */
        $this->assertSame(1, $bundle->moderationItems()->where('status', 'pending')->count());
    }

    /** Once one has been approved, the seller is trusted with the next. */
    public function test_a_second_bundle_goes_live_immediately(): void
    {
        $this->bundle([$this->listing(), $this->listing()]);   // approved inside

        $second = $this->service->publish(
            $this->service->create($this->seller, ['title' => 'Втори комплект на същия продавач'], [
                $this->listing()->id,
                $this->listing()->id,
            ]),
            $this->seller,
        );

        $this->assertSame(BundleStatus::Active, $second->status);
        $this->assertSame(0, $second->moderationItems()->count());
    }

    /**
     * Approving from the queue must actually publish the bundle.
     *
     * A missing branch in ModerationService::approve() does not throw — it
     * marks the item approved and leaves the bundle PendingReview forever,
     * with nothing left in the queue to notice.
     */
    public function test_approving_from_the_queue_publishes_the_bundle(): void
    {
        $bundle = $this->service->publish(
            $this->service->create($this->seller, ['title' => 'Комплект, чакащ преглед'], [
                $this->listing()->id,
                $this->listing()->id,
            ]),
            $this->seller,
        );

        app(ModerationService::class)->approve(
            $bundle->moderationItems()->where('status', 'pending')->firstOrFail(),
            $this->moderator,
        );

        $this->assertSame(BundleStatus::Active, $bundle->fresh()->status);
    }

    /**
     * Rejecting removes the GROUP, not its members. This is the one that would
     * be expensive to get wrong: the parts were each approved on their own and
     * are still perfectly saleable.
     */
    public function test_rejecting_a_bundle_leaves_its_listings_active(): void
    {
        $a = $this->listing();
        $b = $this->listing();

        $bundle = $this->service->publish(
            $this->service->create($this->seller, ['title' => 'Комплект с подвеждащо заглавие'],
                [$a->id, $b->id]),
            $this->seller,
        );

        app(ModerationService::class)->reject(
            $bundle->moderationItems()->where('status', 'pending')->firstOrFail(),
            $this->moderator,
            RejectionReason::cases()[0],
            'Заглавието обещава части, които не са включени в комплекта.',
        );

        $this->assertSame(BundleStatus::Removed, $bundle->fresh()->status);
        $this->assertSame(ListingStatus::Active, $a->fresh()->status);
        $this->assertSame(ListingStatus::Active, $b->fresh()->status);
    }

    // --- the public page --------------------------------------------------

    public function test_the_page_shows_the_package_price_and_the_saving(): void
    {
        $bundle = $this->bundle([
            $this->listing(['price_cents' => 500_00]),
            $this->listing(['price_cents' => 300_00]),
        ], 700_00);

        Livewire::test(ShowBundle::class, ['bundle' => $bundle])
            ->assertSee('700,00 €')
            ->assertSee('800,00 €')          // the parts, added up
            ->assertSee('спестяваш');
    }

    /**
     * What a buyer must NOT see: a price nobody can honour. The page says the
     * package is off rather than quietly showing a smaller number.
     */
    public function test_the_page_says_the_package_price_no_longer_applies(): void
    {
        $gpu = $this->listing(['price_cents' => 500_00]);
        $cpu = $this->listing(['price_cents' => 300_00]);

        $bundle = $this->bundle([$gpu, $cpu], 700_00);

        $gpu->forceFill(['status' => ListingStatus::Sold])->save();

        Livewire::test(ShowBundle::class, ['bundle' => $bundle->fresh()])
            ->assertSee('вече не важи')
            ->assertDontSee('700,00 €');
    }

    public function test_a_bundle_waiting_for_review_is_not_public(): void
    {
        $bundle = $this->service->publish(
            $this->service->create($this->seller, ['title' => 'Комплект за преглед'], [
                $this->listing()->id,
                $this->listing()->id,
            ]),
            $this->seller,
        );

        $this->assertSame(BundleStatus::PendingReview, $bundle->status);

        Livewire::test(ShowBundle::class, ['bundle' => $bundle])->assertStatus(404);

        // Its owner still reaches it, and is told plainly that nobody else can.
        Livewire::actingAs($this->seller)
            ->test(ShowBundle::class, ['bundle' => $bundle])
            ->assertSee('не е публичен');
    }

    // --- the cross-link on a member ---------------------------------------

    /**
     * The link that makes the feature worth having. Somebody who arrived at
     * one card from a search has no other way of learning that the rest of the
     * machine is for sale from the same person.
     */
    public function test_a_member_listing_links_back_to_its_bundle(): void
    {
        $gpu = $this->listing(['price_cents' => 500_00]);
        $cpu = $this->listing(['price_cents' => 300_00]);

        $this->bundle([$gpu, $cpu], 700_00);

        $this->get(route('listing', $gpu->fresh()))
            ->assertOk()
            ->assertSee('Част от комплект')
            ->assertSee(route('bundle', Bundle::first()), escape: false);
    }

    /** A listing in no bundle says nothing at all. */
    public function test_an_ordinary_listing_shows_no_bundle_box(): void
    {
        $this->get(route('listing', $this->listing()))
            ->assertOk()
            ->assertDontSee('Част от комплект');
    }

    // --- the management screen --------------------------------------------

    public function test_the_screen_only_offers_listings_that_can_be_grouped(): void
    {
        $free     = $this->listing(['title' => 'Свободна карта за комплект']);
        $sold     = $this->listing(['title' => 'Продадена карта', 'status' => ListingStatus::Sold]);
        $stranger = $this->listing(['title' => 'Чужда карта'],
            User::factory()->create(['city_id' => City::first()->id]));

        $taken = $this->listing(['title' => 'Вече в друг комплект']);
        $this->bundle([$taken, $this->listing()]);

        Livewire::actingAs($this->seller)
            ->test(ManageBundle::class)
            ->assertSee('Свободна карта за комплект')
            ->assertDontSee('Продадена карта')
            ->assertDontSee('Чужда карта')
            ->assertDontSee('Вече в друг комплект');
    }

    public function test_the_screen_creates_and_publishes_a_bundle(): void
    {
        $a = $this->listing(['price_cents' => 500_00]);
        $b = $this->listing(['price_cents' => 300_00]);

        Livewire::actingAs($this->seller)
            ->test(ManageBundle::class)
            ->set('title', 'Цяла машина за игри, втора употреба')
            ->set('price', '700')
            ->call('toggle', $a->id)
            ->call('toggle', $b->id)
            ->call('save')
            ->assertHasNoErrors();

        $bundle = Bundle::first();

        $this->assertNotNull($bundle);
        $this->assertSame(700_00, $bundle->price_cents);
        $this->assertSame(2, $bundle->listings()->count());

        /*
         * PendingReview, not Draft. The screen saves and publishes in one
         * press on purpose: a draft that needs a second button is a bundle
         * that sits unpublished, and there is nothing else on that screen the
         * draft state is useful for.
         */
        $this->assertSame(BundleStatus::PendingReview, $bundle->status);
    }

    /** The service's refusal reaches the screen as a message, not a 500. */
    public function test_the_screen_refuses_a_bundle_of_one(): void
    {
        $only = $this->listing();

        Livewire::actingAs($this->seller)
            ->test(ManageBundle::class)
            ->set('title', 'Комплект само от една обява')
            ->call('toggle', $only->id)
            ->call('save')
            ->assertHasErrors('selected');

        $this->assertSame(0, Bundle::count());
    }

    public function test_the_screen_404s_on_somebody_elses_bundle(): void
    {
        $bundle   = $this->bundle([$this->listing(), $this->listing()]);
        $stranger = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        Livewire::actingAs($stranger)
            ->test(ManageBundle::class, ['bundle' => $bundle])
            ->assertStatus(404);
    }

    // --- the sitemap ------------------------------------------------------

    public function test_the_sitemap_lists_a_public_bundle_and_skips_an_empty_one(): void
    {
        config(['remarket.seo.indexable' => true]);

        $live = $this->bundle([$this->listing(), $this->listing()], 700_00);

        // Emptied without anybody deleting it: both members detached. This is
        // reachable in production, which is why notEmpty() is in the query.
        $empty = $this->bundle([$this->listing(), $this->listing()]);
        Listing::where('bundle_id', $empty->id)->update(['bundle_id' => null]);

        $body = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString(route('bundle', $live), $body);
        $this->assertStringNotContainsString(route('bundle', $empty), $body);
    }
}
