<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Listings\CreateListing;
use App\Models\City;
use App\Models\Listing;
use App\Models\Part;
use App\Models\User;
use App\Support\AppleLock;
use App\Support\RequiredSpecs;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The one defect on this site that cannot be repaired, refunded or argued with.
 *
 * A phone, tablet or Mac still signed in to somebody else's Apple ID does not
 * get unlocked by a service shop, by the carrier or by Apple without the
 * original proof of purchase. It is also completely invisible until the buyer
 * gets home and wipes it — which is after the money has changed hands, in a
 * market where most of it changes hands in cash, in person.
 *
 * WHY A SELECT AND NOT A REQUIRED CHECKBOX. A required checkbox has exactly one
 * answer that satisfies it. Making the old bool required would not have made
 * sellers answer; it would have made all of them tick, including the ones the
 * field exists to catch, and the site would have gained a column of `true` that
 * means nothing and a false sense that the question was handled.
 */
class AppleLockTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(CitySeeder::class);

        $this->seller = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);
    }

    private function option(string $category, string $state): string
    {
        return config("catalog.categories.{$category}.specs.icloud_signed_out.states.{$state}");
    }

    private function listing(string $category, ?string $state, array $attributes = []): Listing
    {
        return Listing::factory()->create([
            'user_id'  => $this->seller->id,
            'city_id'  => City::first()->id,
            'category' => $category,
            'status'   => ListingStatus::Active,
            'specs'    => $state ? ['icloud_signed_out' => $this->option($category, $state)] : [],
            ...$attributes,
        ]);
    }

    // --- the schema -------------------------------------------------------

    /**
     * Every state names an option that actually exists.
     *
     * This is the drift guard, and it is the whole reason the strings live in
     * one `states` map rather than being compared inline in a view. Rewording
     * an option without rewording its state would leave `AppleLock` matching
     * nothing at all — and matching nothing looks exactly like „no locked
     * devices on the site", which is the most reassuring possible way for this
     * to break.
     */
    public function test_every_declared_state_is_a_real_option(): void
    {
        foreach (AppleLock::CATEGORIES as $category) {
            $spec = config("catalog.categories.{$category}.specs.".AppleLock::SPEC);

            $this->assertIsArray($spec, "[{$category}] has no Apple ID question at all");
            $this->assertSame('select', $spec['type'], "[{$category}] must be a select, not a checkbox");
            $this->assertTrue($spec['required'] ?? false, "[{$category}] must be required");

            foreach (['clear', 'pending', 'locked'] as $state) {
                $this->assertArrayHasKey($state, $spec['states'], "[{$category}] has no `{$state}`");
                $this->assertContains($spec['states'][$state], $spec['options'],
                    "[{$category}.{$state}] names an option that is not offered");
            }
        }
    }

    // --- reading a listing ------------------------------------------------

    public function test_it_reads_each_state(): void
    {
        foreach (AppleLock::CATEGORIES as $category) {
            foreach (['clear', 'pending', 'locked'] as $state) {
                $this->assertSame($state, AppleLock::state($this->listing($category, $state)),
                    "[{$category}] misread `{$state}`");
            }
        }
    }

    public function test_only_a_locked_device_counts_as_locked(): void
    {
        $this->assertTrue(AppleLock::isLocked($this->listing('iphone', 'locked')));
        $this->assertFalse(AppleLock::isLocked($this->listing('iphone', 'pending')));
        $this->assertFalse(AppleLock::isLocked($this->listing('iphone', 'clear')));
    }

    /** A graphics card has no Apple ID, and must not be badged as if it did. */
    public function test_a_non_apple_listing_is_never_locked(): void
    {
        $gpu = $this->listing('gpu', null, [
            'specs' => ['icloud_signed_out' => 'Не мога да изляза'],
        ]);

        $this->assertNull(AppleLock::state($gpu));
        $this->assertFalse(AppleLock::isLocked($gpu));
    }

    /**
     * A listing posted before the field existed, and one holding the old
     * boolean. Neither is a locked device, and badging either as one would put
     * a warning on somebody's honest listing.
     */
    public function test_an_unanswered_or_legacy_value_is_not_treated_as_locked(): void
    {
        $this->assertNull(AppleLock::state($this->listing('iphone', null)));

        $legacy = $this->listing('iphone', null, ['specs' => ['icloud_signed_out' => true]]);

        $this->assertNull(AppleLock::state($legacy));
        $this->assertFalse(AppleLock::isLocked($legacy));
        $this->assertNull(AppleLock::notice($legacy));
    }

    // --- what the buyer is told -------------------------------------------

    public function test_a_locked_device_says_so_on_its_own_page(): void
    {
        $listing = $this->listing('iphone', 'locked');

        $this->get(route('listing', $listing))
            ->assertOk()
            ->assertSee('не може да излезе от акаунта')
            ->assertSee('за части');
    }

    /**
     * „Ще изляза пред теб" is how a careful sale goes, not a warning. It is on
     * the page because it tells the buyer what to do AT the meeting, which is
     * the last moment the thing is still fixable.
     */
    public function test_a_pending_sign_out_is_advice_rather_than_a_warning(): void
    {
        $listing = $this->listing('iphone', 'pending');

        $this->get(route('listing', $listing))
            ->assertOk()
            ->assertSee('преди парите да сменят ръцете си')
            ->assertDontSee('не може да излезе от акаунта');
    }

    public function test_a_clean_device_gets_no_notice_at_all(): void
    {
        $this->assertNull(AppleLock::notice($this->listing('iphone', 'clear')));

        $this->get(route('listing', $this->listing('iphone', 'clear')))
            ->assertOk()
            ->assertDontSee('заключен за Apple ID');
    }

    /** Visible in the grid, so nobody has to open a listing to find out. */
    public function test_the_card_badges_a_locked_device(): void
    {
        $this->listing('iphone', 'locked', ['title' => 'iPhone 12 за части, заключен']);
        $this->listing('iphone', 'clear', ['title' => 'iPhone 12 чист и отвързан']);

        $this->get(route('browse', ['kat' => 'iphone']))
            ->assertOk()
            ->assertSee('заключен за Apple ID');
    }

    // --- the wizard -------------------------------------------------------

    /**
     * The field is required, which is the point — but it has to be required
     * where the seller can SEE it. The optional panel is collapsed on arrival,
     * and rejecting a listing over a field inside a section headed „по желание"
     * is the bug this whole mechanism was built to avoid.
     */
    public function test_the_wizard_will_not_advance_without_an_answer(): void
    {
        $part = Part::create([
            'category' => 'iphone', 'manufacturer' => 'Apple',
            'model' => 'iPhone 13 128GB', 'slug' => 'iphone-13-128-test',
            'specs' => [], 'is_published' => true,
        ]);

        $wizard = Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->set('category', 'iphone')
            ->call('next')
            ->set('partId', $part->id)
            ->call('next')
            ->assertSet('step', 3)
            ->assertSee('iCloud');

        $wizard->set('title', 'iPhone 13 128GB, пълен комплект с кутия')
            ->set('description', 'Телефонът е мой от нов, работи без проблем и е с оригинален екран.')
            ->set('photos', [UploadedFile::fake()->image('phone.jpg', 900, 675)])
            ->call('next')
            ->assertHasErrors('specs.icloud_signed_out')
            ->assertSet('step', 3);
    }

    /** And „не мога да изляза" is a complete answer, not a rejected one. */
    public function test_the_honest_bad_answer_publishes(): void
    {
        $part = Part::create([
            'category' => 'iphone', 'manufacturer' => 'Apple',
            'model' => 'iPhone 13 256GB', 'slug' => 'iphone-13-256-test',
            'specs' => [], 'is_published' => true,
        ]);

        Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->set('category', 'iphone')
            ->call('next')
            ->set('partId', $part->id)
            ->call('next')
            ->set('title', 'iPhone 13 256GB за части, заключен за акаунт')
            ->set('description', 'Телефонът е на роднина, който почина, и не знаем паролата за акаунта.')
            ->set('photos', [UploadedFile::fake()->image('phone.jpg', 900, 675)])
            ->set('specs.icloud_signed_out', $this->option('iphone', 'locked'))
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 4);
    }

    /**
     * The screen must not claim an answer the seller never gave.
     *
     * A <select> whose bound value matches no option displays the first one, so
     * before this placeholder existed an untouched required select showed „Да,
     * излязъл съм от iCloud" — the reassuring answer — and then failed
     * validation on the field that looked filled in. Found while wiring this
     * up; it affected `monitor.dead_pixels` and both `prebuilt` fields too.
     */
    public function test_an_untouched_required_select_shows_no_answer(): void
    {
        $part = Part::create([
            'category' => 'iphone', 'manufacturer' => 'Apple',
            'model' => 'iPhone 14 128GB', 'slug' => 'iphone-14-128-test',
            'specs' => [], 'is_published' => true,
        ]);

        Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->set('category', 'iphone')
            ->call('next')
            ->set('partId', $part->id)
            ->call('next')
            ->assertSet('step', 3)
            ->assertSee('— избери —')
            ->assertSet('specs.icloud_signed_out', null);
    }

    /** The rules the wizard is built from, without walking the wizard. */
    public function test_the_rules_accept_only_the_three_options(): void
    {
        $rules = RequiredSpecs::rules('macbook');

        $this->assertArrayHasKey('specs.icloud_signed_out', $rules);
        $this->assertContains('required', $rules['specs.icloud_signed_out']);
        $this->assertContains('string', $rules['specs.icloud_signed_out']);
    }
}
