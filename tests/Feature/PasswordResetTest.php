<?php

namespace Tests\Feature;

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Database\Seeders\CitySeeder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Getting back into an account.
 *
 * Before this existed, forgetting a password lost the account permanently:
 * email verification was the site's only proof of who someone is, and nothing
 * was wired to it that could recover anything.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        $this->user = User::factory()->create([
            'email'    => 'ivan@example.com',
            'password' => 'staro-parola-123',
        ]);

        RateLimiter::clear('*');
    }

    private function tokenFor(User $user): string
    {
        return Password::broker()->createToken($user);
    }

    // --- asking for the link ---------------------------------------------

    public function test_a_link_is_sent_for_a_real_address(): void
    {
        Notification::fake();

        Livewire::test(ForgotPassword::class)
            ->set('email', 'ivan@example.com')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('sent', true);

        Notification::assertSentTo($this->user, ResetPasswordLink::class);
    }

    public function test_the_address_is_normalised_before_it_is_validated(): void
    {
        Notification::fake();

        /*
         * Case and whitespace, together, because the whitespace half is the
         * one that actually bit: a Livewire payload skips the TrimStrings
         * middleware, so a pasted address with a trailing space failed the
         * `email` rule and the person was told their own address was not an
         * address - on the one screen they reached because they were stuck.
         */
        Livewire::test(ForgotPassword::class)
            ->set('email', '  IVAN@Example.com ')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('sent', true)
            // Canonicalised on the component too, so the confirmation screen
            // and the throttle key both read one form of the address.
            ->assertSet('email', 'ivan@example.com');

        Notification::assertSentTo($this->user, ResetPasswordLink::class);
    }

    /**
     * The whole point of the request screen.
     *
     * Saying "no such account" turns the form into a lookup: type an address,
     * learn whether it is registered here. On this site that list is people who
     * own expensive hardware and meet strangers to sell it.
     */
    public function test_an_unknown_address_produces_the_same_screen_and_no_mail(): void
    {
        Notification::fake();

        Livewire::test(ForgotPassword::class)
            ->set('email', 'nikoi@example.com')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('sent', true);

        Notification::assertNothingSent();
    }

    public function test_asking_repeatedly_is_throttled(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $i) {
            Livewire::test(ForgotPassword::class)
                ->set('email', 'ivan@example.com')
                ->call('send');
        }

        Livewire::test(ForgotPassword::class)
            ->set('email', 'ivan@example.com')
            ->call('send')
            ->assertHasErrors('email');
    }

    // --- the notification -------------------------------------------------

    /**
     * A reset link must not obey notification preferences. Someone who turned
     * email off, or never verified their address, is exactly the person who
     * ends up locked out - and RemarketNotification's via() would send nothing
     * at all in both cases.
     */
    public function test_the_link_is_sent_even_with_email_notifications_off_and_unverified(): void
    {
        Notification::fake();

        $this->user->forceFill([
            'notify_email'      => false,
            'email_verified_at' => null,
        ])->save();

        Livewire::test(ForgotPassword::class)
            ->set('email', 'ivan@example.com')
            ->call('send');

        Notification::assertSentTo(
            $this->user->fresh(),
            ResetPasswordLink::class,
            fn (ResetPasswordLink $n, array $channels) => $channels === ['mail'],
        );
    }

    /**
     * A reset link over Telegram would be a link that proves control of a chat
     * rather than of the mailbox it is supposed to verify.
     */
    public function test_the_link_never_goes_to_telegram(): void
    {
        Notification::fake();

        $this->user->forceFill(['telegram_chat_id' => 123456])->save();

        Livewire::test(ForgotPassword::class)
            ->set('email', 'ivan@example.com')
            ->call('send');

        Notification::assertSentTo(
            $this->user->fresh(),
            ResetPasswordLink::class,
            fn (ResetPasswordLink $n, array $channels) => $channels === ['mail'],
        );
    }

    public function test_the_mail_carries_a_working_link(): void
    {
        Notification::fake();

        Livewire::test(ForgotPassword::class)
            ->set('email', 'ivan@example.com')
            ->call('send');

        Notification::assertSentTo($this->user, ResetPasswordLink::class, function (ResetPasswordLink $n) {
            $mail = $n->toMail($this->user);
            $url  = $mail->actionUrl;

            $this->assertStringContainsString(route('password.reset', ['token' => $n->token]), $url);
            // Both halves, or the broker cannot verify the token against a user.
            $this->assertStringContainsString('email=', $url);

            return true;
        });
    }

    // --- using it ---------------------------------------------------------

    public function test_a_valid_token_sets_a_new_password(): void
    {
        Event::fake([PasswordReset::class]);

        $token = $this->tokenFor($this->user);

        Livewire::withQueryParams(['email' => 'ivan@example.com'])
            ->test(ResetPassword::class, ['token' => $token])
            ->set('password', 'nova-parola-456')
            ->set('password_confirmation', 'nova-parola-456')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('nova-parola-456', $this->user->fresh()->password));
        Event::assertDispatched(PasswordReset::class);
    }

    /**
     * Whoever the account was being recovered FROM keeps their "remember me"
     * cookie working unless the token is rotated - so changing the password
     * would not actually remove them.
     */
    public function test_resetting_invalidates_remember_me_elsewhere(): void
    {
        $this->user->forceFill(['remember_token' => 'staro'])->save();

        Livewire::withQueryParams(['email' => 'ivan@example.com'])
            ->test(ResetPassword::class, ['token' => $this->tokenFor($this->user)])
            ->set('password', 'nova-parola-456')
            ->set('password_confirmation', 'nova-parola-456')
            ->call('save');

        $this->assertNotSame('staro', $this->user->fresh()->remember_token);
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        $token = $this->tokenFor($this->user);

        Livewire::withQueryParams(['email' => 'ivan@example.com'])
            ->test(ResetPassword::class, ['token' => $token])
            ->set('password', 'nova-parola-456')
            ->set('password_confirmation', 'nova-parola-456')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::withQueryParams(['email' => 'ivan@example.com'])
            ->test(ResetPassword::class, ['token' => $token])
            ->set('password', 'treta-parola-789')
            ->set('password_confirmation', 'treta-parola-789')
            ->call('save')
            ->assertHasErrors('password');

        // The second attempt changed nothing.
        $this->assertTrue(Hash::check('nova-parola-456', $this->user->fresh()->password));
    }

    public function test_a_forged_token_is_refused(): void
    {
        Livewire::withQueryParams(['email' => 'ivan@example.com'])
            ->test(ResetPassword::class, ['token' => 'izmislen-token'])
            ->set('password', 'nova-parola-456')
            ->set('password_confirmation', 'nova-parola-456')
            ->call('save')
            ->assertHasErrors('password');

        $this->assertTrue(Hash::check('staro-parola-123', $this->user->fresh()->password));
    }

    /**
     * The address is #[Locked] because it is what the token is checked
     * against. A client-editable one would let a valid token be aimed at
     * somebody else's account.
     */
    public function test_the_address_cannot_be_swapped_for_someone_elses(): void
    {
        $victim = User::factory()->create(['email' => 'zhertva@example.com']);

        try {
            Livewire::withQueryParams(['email' => 'ivan@example.com'])
                ->test(ResetPassword::class, ['token' => $this->tokenFor($this->user)])
                ->set('email', 'zhertva@example.com');

            $this->fail('the locked address accepted an update from the client');
        } catch (CannotUpdateLockedPropertyException) {
            // What #[Locked] is there for.
        }

        $this->assertSame($victim->password, $victim->fresh()->password);
    }

    public function test_the_two_passwords_must_match(): void
    {
        Livewire::withQueryParams(['email' => 'ivan@example.com'])
            ->test(ResetPassword::class, ['token' => $this->tokenFor($this->user)])
            ->set('password', 'nova-parola-456')
            ->set('password_confirmation', 'drugo-neshto')
            ->call('save')
            ->assertHasErrors('password');
    }

    // --- the way in and the way out ---------------------------------------

    public function test_the_login_page_offers_the_way_out(): void
    {
        Livewire::test(Login::class)
            ->assertSee(route('password.request'), escape: false);
    }

    public function test_the_new_password_logs_in(): void
    {
        Livewire::withQueryParams(['email' => 'ivan@example.com'])
            ->test(ResetPassword::class, ['token' => $this->tokenFor($this->user)])
            ->set('password', 'nova-parola-456')
            ->set('password_confirmation', 'nova-parola-456')
            ->call('save');

        // The point of the whole feature, asserted end to end rather than by
        // checking a hash.
        Livewire::test(Login::class)
            ->set('login', 'ivan@example.com')
            ->set('password', 'nova-parola-456')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($this->user->fresh());
    }

    public function test_the_old_password_stops_working(): void
    {
        Livewire::withQueryParams(['email' => 'ivan@example.com'])
            ->test(ResetPassword::class, ['token' => $this->tokenFor($this->user)])
            ->set('password', 'nova-parola-456')
            ->set('password_confirmation', 'nova-parola-456')
            ->call('save');

        Livewire::test(Login::class)
            ->set('login', 'ivan@example.com')
            ->set('password', 'staro-parola-123')
            ->call('authenticate')
            ->assertHasErrors('login');

        $this->assertGuest();
    }

    /** Both screens are for people who are not logged in. */
    public function test_the_reset_screens_are_guest_only(): void
    {
        $this->actingAs($this->user);

        $this->get(route('password.request'))->assertRedirect();
    }
}
