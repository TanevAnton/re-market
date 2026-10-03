<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Livewire\Listings\CreateListing;
use App\Livewire\Listings\EditListing;
use App\Models\City;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The wizard, as used by somebody holding a phone.
 *
 * WHY THIS FILE EXISTS. The first twenty listings on this site will be
 * photographed on a phone and posted from that phone, which makes the four-step
 * wizard the most important untested path there is. Two defects in it were hard
 * blockers and neither would ever have shown up on a desktop:
 *
 *   1. THE PHOTO CONTROLS WERE HOVER-ONLY. `opacity-0 group-hover:opacity-100`
 *      never fires on Android Chrome, and on iOS Safari fires on the first tap
 *      and then needs a second one. So a seller could not delete a blurry photo
 *      and could not choose the cover image — on the wizard, and on the edit
 *      screen, where ALL THREE controls were gated that way.
 *
 *   2. THE PRICE FIELD LOST BULGARIAN NUMBERS. It was `type="number"`, a
 *      Bulgarian keypad offers a comma, and a number input holding an invalid
 *      value reports it as the empty string — so „450,00" vanished with no error.
 *      Worse, the two code paths that read the price disagreed about the comma:
 *      the guidance partial normalised it and `publish()` did not.
 *
 * WHAT THIS FILE CANNOT TEST is whether any of it LOOKS right at 375px — no
 * assertion catches a control that is technically present and visually off the
 * edge of the screen. That still needs somebody with a phone.
 */
class MobileWizardTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(CitySeeder::class);
        $this->seed(PartSeeder::class);

        $this->seller = User::factory()->create([
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ])->refresh();
    }

    // --- the price, which a Bulgarian phone types with a comma -------------

    /**
     * THE ONE THAT WAS SILENTLY LOSING MONEY. „450,50" is how this price is
     * written here, and it has to survive being typed.
     */
    public function test_a_price_typed_with_a_comma_survives(): void
    {
        Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->set('price', '450,50')
            // Normalised on the way in, so the `numeric` rule and the storage
            // path see the same value. They did not before.
            ->assertSet('price', '450.50')
            ->assertHasNoErrors('price');
    }

    /** A space is the thousands separator here, and somebody will type it. */
    public function test_a_thousands_space_survives_too(): void
    {
        Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->set('price', '1 200,99')
            ->assertSet('price', '1200.99')
            ->assertHasNoErrors('price');
    }

    public function test_the_offer_floor_takes_a_comma_as_well(): void
    {
        Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->set('min_offer', '399,00')
            ->assertSet('min_offer', '399.00');
    }

    /** Blank stays blank — „no floor" must not become „a floor of zero". */
    public function test_an_empty_price_stays_empty(): void
    {
        Livewire::actingAs($this->seller)
            ->test(CreateListing::class)
            ->set('min_offer', '   ')
            ->assertSet('min_offer', '');
    }

    /** The edit screen had the same input and its own `cents()` helper. */
    public function test_the_edit_screen_takes_a_comma_too(): void
    {
        $listing = Listing::factory()->create([
            'user_id'     => $this->seller->id,
            'city_id'     => City::first()->id,
            'status'      => ListingStatus::Active,
            'price_cents' => 50000,
        ]);

        Livewire::actingAs($this->seller)
            ->test(EditListing::class, ['listing' => $listing])
            ->set('price', '612,40')
            ->assertSet('price', '612.40')
            ->assertHasNoErrors('price');
    }

    /**
     * NOT `type="number"`, on any money field, on either screen.
     *
     * This is the guard rather than the fix: the fix is one attribute, and the
     * thing that would undo it is somebody tidying a form six months from now and
     * „correcting" a text input that holds a number back to `type="number"`.
     * The comment in the view says why; this fails if the comment is ignored.
     */
    public function test_no_money_field_is_a_native_number_input(): void
    {
        foreach (['create-listing', 'edit-listing'] as $view) {
            $source = (string) file_get_contents(
                resource_path("views/livewire/listings/{$view}.blade.php"),
            );

            foreach (['price', 'min_offer'] as $field) {
                // The id and the type sit in the same tag, so a line-level check
                // is enough and is not fooled by a number input elsewhere.
                foreach (explode('<input', $source) as $tag) {
                    if (! str_contains($tag, 'id="'.$field.'"')) {
                        continue;
                    }

                    $this->assertStringNotContainsString('type="number"', $tag,
                        "{$view}: {$field} is a native number input again — a Bulgarian "
                        .'comma makes its value the empty string. Use inputmode="decimal".');

                    $this->assertStringContainsString('inputmode="decimal"', $tag,
                        "{$view}: {$field} has no decimal keypad hint.");
                }
            }
        }
    }

    // --- the photo controls, on a screen with no hover --------------------

    /**
     * THE BLOCKER, ASSERTED THE ONLY WAY IT CAN BE.
     *
     * There is no way to test „can a thumb tap this" in PHPUnit, so this tests
     * the mechanism that made it impossible: a control whose visibility depends
     * on `group-hover` does not exist on a touch screen. A grep is a weak test in
     * general and exactly the right one here, because the defect was a CSS class
     * and nothing else.
     */
    public function test_no_photo_control_is_hidden_behind_hover(): void
    {
        $offenders = [];

        foreach (['create-listing', 'edit-listing'] as $view) {
            /*
             * Blade comments stripped first, and that is not a detail: the comment
             * explaining this fix NAMES the class it removed, so a grep over the
             * raw file fails on the documentation of its own fix. A guard that
             * cannot tell code from prose reports the wrong thing — caught by
             * running it.
             */
            $source = preg_replace(
                '/\{\{--.*?--\}\}/s',
                '',
                (string) file_get_contents(resource_path("views/livewire/listings/{$view}.blade.php")),
            );

            if (str_contains((string) $source, 'group-hover:opacity')) {
                $offenders[] = $view;
            }
        }

        $this->assertSame([], $offenders,
            'a photo control is behind group-hover, which means it does not exist on a '
            .'phone — and a phone is how the first twenty listings get posted');
    }

    /**
     * And the controls are actually reachable: 44px is the tap target below which
     * people miss, and `min-h-11` is 44px.
     */
    public function test_the_photo_controls_have_a_real_tap_target(): void
    {
        foreach (['create-listing', 'edit-listing'] as $view) {
            $source = (string) file_get_contents(
                resource_path("views/livewire/listings/{$view}.blade.php"),
            );

            foreach (['makePrimary', 'markTimestamp', 'removePhoto'] as $action) {
                $this->assertMatchesRegularExpression(
                    '/wire:click="'.$action.'\([^)]*\)"[^>]*min-h-11|min-h-11[^>]*wire:click="'.$action.'/s',
                    $source,
                    "{$view}: {$action} has no 44px tap target (min-h-11)",
                );
            }
        }
    }

    /**
     * The controls still WORK, which a grep cannot tell you. Driven through the
     * edit screen because the wizard's photos need real uploaded files.
     */
    public function test_the_controls_still_do_what_they_say(): void
    {
        $listing = Listing::factory()->create([
            'user_id' => $this->seller->id,
            'city_id' => City::first()->id,
            'status'  => ListingStatus::Active,
        ]);

        $images = collect(range(0, 2))->map(fn ($position) => ListingImage::create([
            'listing_id' => $listing->id,
            'path'       => "listings/photo-{$position}.jpg",
            'width'      => 1200,
            'height'     => 900,
            'position'   => $position,
        ]));

        $second = $images[1];

        Livewire::actingAs($this->seller)
            ->test(EditListing::class, ['listing' => $listing])
            ->call('makePrimary', $second->id)
            ->assertHasNoErrors();

        $this->assertSame(
            $second->id,
            $listing->fresh()->images()->orderBy('position')->first()->id,
            'making a photo primary did not move it to the front',
        );
    }

    // --- the upload ceiling -----------------------------------------------

    /**
     * THE LIMITS HAVE TO AGREE, and they did not.
     *
     * Livewire sends every file from a `multiple` input in ONE POST. The old
     * pairing was 16M per file against a 24M POST, so two phone photos selected
     * together were already at the ceiling — and a POST over the limit dies at
     * nginx with a bare 413, which means Laravel never runs, nothing reaches the
     * log, and the seller sees no error whatsoever.
     *
     * This asserts the three numbers still line up: the validation rule at or
     * below `upload_max_filesize`, and the POST ceiling matching nginx's.
     */
    public function test_the_upload_limits_agree_with_each_other(): void
    {
        $ini   = (string) file_get_contents(base_path('deploy/php-remarket.ini'));
        $nginx = (string) file_get_contents(base_path('deploy/nginx.conf'));

        preg_match('/^upload_max_filesize\s*=\s*(\d+)M/m', $ini, $perFile);
        preg_match('/^post_max_size\s*=\s*(\d+)M/m', $ini, $post);
        preg_match('/client_max_body_size\s+(\d+)M/', $nginx, $body);

        $this->assertNotEmpty($perFile, 'upload_max_filesize is not set');
        $this->assertNotEmpty($post, 'post_max_size is not set');
        $this->assertNotEmpty($body, 'client_max_body_size is not set');

        $perFileMb = (int) $perFile[1];
        $postMb    = (int) $post[1];
        $bodyMb    = (int) $body[1];

        // nginx answers first, so a smaller value there makes php.ini a fiction.
        $this->assertGreaterThanOrEqual($postMb, $bodyMb,
            'client_max_body_size is below post_max_size, so nginx 413s before PHP is reached');

        // Room for a realistic multi-photo selection, not just one file.
        $this->assertGreaterThanOrEqual($perFileMb * 4, $postMb,
            'post_max_size cannot hold four photos at upload_max_filesize, and Livewire '
            .'sends a multiple-file selection in one request');

        // And the app's own rule must not promise more than PHP will accept: a
        // rule above the ini value is a file rejected before validation runs,
        // with a 413 instead of the sentence the rule carries.
        $source = (string) file_get_contents(app_path('Livewire/Listings/CreateListing.php'));
        preg_match('/\'max:(\d+)\'/', $source, $rule);

        $this->assertNotEmpty($rule, 'the photo size rule has gone');
        $this->assertLessThanOrEqual($perFileMb * 1024, (int) $rule[1],
            'the wizard accepts a bigger file than upload_max_filesize allows, so the '
            .'seller gets a 413 instead of the error message the rule carries');
    }
}
