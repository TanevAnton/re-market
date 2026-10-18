<?php

namespace Tests\Feature;

use App\Livewire\Auth\CompleteGoogleSignup;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * „Влез с Google".
 *
 * Two halves with different risks. The CALLBACK decides who you are, so its
 * tests are about refusing: an unverified Google address, a suspended account,
 * a deleted one. The COMPLETION SCREEN creates the row, so its tests are about
 * the three things Google cannot supply and this site cannot do without - a
 * username, a phone and an agreement to the terms.
 *
 * The expensive failure here is not an error. It is a second door into the site
 * that asks fewer questions than the first one, which is the ordinary way a
 * banned account walks back in.
 */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        // Without these the button is hidden and the routes bounce, which is
        // its own test further down.
        config([
            'services.google.client_id'     => 'test-client-id',
            'services.google.client_secret' => 'test-secret',
            'services.google.redirect'      => 'http://localhost/vhod/google/callback',
        ]);
    }

    /** Stand in for what Google hands back. */
    private function fakeGoogleUser(
        string $id = 'google-abc-123',
        string $email = 'ivan@gmail.com',
        string $name = 'Ivan Yorkishev',
        bool $verified = true,
    ): void {
        $user = new SocialiteUser;
        $user->map(['id' => $id, 'email' => $email, 'name' => $name]);

        // email_verified lives in the raw payload, not in the mapped fields,
        // and it is the single most important thing in this response.
        $user->user = ['email_verified' => $verified];

        Socialite::shouldReceive('driver->user')->andReturn($user);
    }

    // --- the callback ------------------------------------------------------

    public function test_a_known_google_account_is_signed_straight_in(): void
    {
        $existing = User::factory()->create(['google_id' => 'google-abc-123']);

        $this->fakeGoogleUser();

        $this->get(route('google.callback'))->assertRedirect(route('browse'));

        $this->assertAuthenticatedAs($existing);
    }

    /**
     * Google has proved they read that mailbox, which is stronger evidence
     * than the password on the account. Refusing would strand everybody who
     * has forgotten which way they signed up.
     */
    public function test_a_matching_address_links_the_existing_account(): void
    {
        $existing = User::factory()->create([
            'email'     => 'ivan@gmail.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser();

        $this->get(route('google.callback'))->assertRedirect(route('browse'));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame('google-abc-123', $existing->fresh()->google_id);

        // No second account was invented for the same person.
        $this->assertSame(1, User::count());
    }

    public function test_linking_confirms_an_address_that_was_never_confirmed(): void
    {
        $existing = User::factory()->unverified()->create(['email' => 'ivan@gmail.com']);

        $this->fakeGoogleUser();
        $this->get(route('google.callback'));

        $this->assertNotNull($existing->fresh()->email_verified_at);
    }

    /**
     * THE ONE THAT MAKES LINKING SAFE AT ALL. An unverified address on a
     * Google account proves only that somebody typed it; honouring one would
     * turn this button into „type any email, get that account".
     */
    public function test_an_unverified_google_address_is_refused(): void
    {
        $existing = User::factory()->create(['email' => 'ivan@gmail.com']);

        $this->fakeGoogleUser(verified: false);

        $this->get(route('google.callback'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($existing->fresh()->google_id);
    }

    /** A second door that asks fewer questions than the first is not a door. */
    public function test_a_suspended_account_cannot_come_in_this_way(): void
    {
        User::factory()->create([
            'google_id' => 'google-abc-123',
            'banned_at' => now(),
        ]);

        $this->fakeGoogleUser();

        $this->get(route('google.callback'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /** Signing in must not resurrect what deletion removed. */
    public function test_a_deleted_account_is_not_revived_by_a_matching_address(): void
    {
        $gone = User::factory()->create(['email' => 'ivan@gmail.com']);
        $gone->delete();

        $this->fakeGoogleUser();

        // Not signed in, and sent to finish a NEW signup instead.
        $this->get(route('google.callback'))->assertRedirect(route('register.complete'));

        $this->assertGuest();
    }

    public function test_a_new_person_gets_no_row_until_they_finish(): void
    {
        $this->fakeGoogleUser();

        $this->get(route('google.callback'))->assertRedirect(route('register.complete'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_the_routes_bounce_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->get(route('google.redirect'))->assertRedirect(route('login'));
    }

    public function test_the_button_is_hidden_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->get(route('login'))->assertDontSee('Продължи с Google');
    }

    public function test_the_button_is_on_the_login_and_register_pages(): void
    {
        $this->get(route('login'))->assertSee('Продължи с Google');
        $this->get(route('register'))->assertSee('Продължи с Google');
    }

    // --- the completion screen ---------------------------------------------

    private function arriveFromGoogle(): void
    {
        $this->fakeGoogleUser();
        $this->get(route('google.callback'));
    }

    public function test_the_screen_sends_you_back_when_nothing_was_parked(): void
    {
        Livewire::test(CompleteGoogleSignup::class)->assertRedirect(route('register'));
    }

    public function test_finishing_creates_a_verified_account_with_a_phone(): void
    {
        $this->arriveFromGoogle();

        Livewire::test(CompleteGoogleSignup::class)
            ->set('username', 'ivanko')
            ->set('phone', '0888123456')
            ->set('terms', true)
            ->call('complete')
            ->assertHasNoErrors()
            ->assertRedirect(route('browse'));

        $user = User::firstOrFail();

        $this->assertSame('ivan@gmail.com', $user->email);
        $this->assertSame('google-abc-123', $user->google_id);
        $this->assertNotNull($user->email_verified_at);

        // On file as a hash, and not proven - phone verification is off.
        $this->assertNotNull($user->phone_hash);
        $this->assertSame('3456', $user->phone_last4);
        $this->assertNull($user->phone_verified_at);

        $this->assertAuthenticatedAs($user);
    }

    /**
     * A click on a Google button is consent to share a profile. It is not
     * agreement to a contract, and treating it as such would make the
     * acceptance unprovable exactly when it mattered.
     */
    public function test_the_terms_must_be_accepted_explicitly(): void
    {
        $this->arriveFromGoogle();

        Livewire::test(CompleteGoogleSignup::class)
            ->set('username', 'ivanko')
            ->set('phone', '0888123456')
            ->set('terms', false)
            ->call('complete')
            ->assertHasErrors('terms');

        $this->assertSame(0, User::count());
    }

    /** The field this site just decided to require does not get skipped here. */
    public function test_the_phone_is_required(): void
    {
        $this->arriveFromGoogle();

        Livewire::test(CompleteGoogleSignup::class)
            ->set('username', 'ivanko')
            ->set('phone', '')
            ->set('terms', true)
            ->call('complete')
            ->assertHasErrors('phone');

        $this->assertSame(0, User::count());
    }

    /** Two signup paths, one set of username rules. */
    public function test_a_taken_username_is_refused_here_too(): void
    {
        User::factory()->create(['username' => 'Ivanko']);

        $this->arriveFromGoogle();

        Livewire::test(CompleteGoogleSignup::class)
            ->set('username', 'ivanko')
            ->set('phone', '0888123456')
            ->set('terms', true)
            ->call('complete')
            ->assertHasErrors('username');
    }

    public function test_the_address_cannot_be_changed_on_the_way_through(): void
    {
        $this->arriveFromGoogle();

        Livewire::test(CompleteGoogleSignup::class)
            ->set('email', 'someone-else@example.com')   // the property is display-only
            ->set('username', 'ivanko')
            ->set('phone', '0888123456')
            ->set('terms', true)
            ->call('complete')
            ->assertHasNoErrors();

        // The row is written from the session payload, never from the form.
        $this->assertSame('ivan@gmail.com', User::firstOrFail()->email);
    }

    /**
     * Minutes pass while this form is open. users.email and users.google_id are
     * both unique, so without the re-check the submit would die as a constraint
     * violation on the last screen of a signup.
     */
    public function test_an_address_taken_while_the_form_was_open_does_not_500(): void
    {
        $this->arriveFromGoogle();

        User::factory()->create(['email' => 'ivan@gmail.com']);

        Livewire::test(CompleteGoogleSignup::class)
            ->set('username', 'ivanko')
            ->set('phone', '0888123456')
            ->set('terms', true)
            ->call('complete')
            ->assertRedirect(route('login'));

        $this->assertSame(1, User::count());
    }

    public function test_the_account_has_no_usable_password(): void
    {
        $this->arriveFromGoogle();

        Livewire::test(CompleteGoogleSignup::class)
            ->set('username', 'ivanko')
            ->set('phone', '0888123456')
            ->set('terms', true)
            ->call('complete');

        $user = User::firstOrFail();

        // Set, so every query that assumes a hash keeps working — and matching
        // nothing anybody could type.
        $this->assertNotNull($user->password);
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('password', $user->password));
    }
}
