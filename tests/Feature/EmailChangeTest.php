<?php

namespace Tests\Feature;

use App\Livewire\Profile\EditProfile;
use App\Models\User;
use App\Notifications\ConfirmNewEmail;
use App\Notifications\EmailChangeRequested;
use App\Services\Account\EmailChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Changing the address an account is reachable at.
 *
 * Until this existed, a typo at registration lost the account outright: the one
 * recovery path is the password reset, and that goes to the address the person
 * cannot read. The only fix was editing the row by hand on the server.
 *
 * The property every test below is really defending: users.email does not move
 * until somebody proves they can read the new mailbox. Everything else —
 * passwords, cancel links, the race for a contested address — follows from
 * that one rule.
 */
class EmailChangeTest extends TestCase
{
    use RefreshDatabase;

    private function start(User $user, string $to = 'new@example.com'): string
    {
        $url = null;

        Notification::fake();

        app(EmailChange::class)->request($user, $to);

        // The token exists only in the mails, which is the point of it. Read
        // the confirmation URL back out the way the recipient's browser would.
        Notification::assertSentOnDemand(
            ConfirmNewEmail::class,
            function (ConfirmNewEmail $n) use (&$url) {
                $url = $n->url;

                return true;
            },
        );

        return $url;
    }

    // --- the form ---------------------------------------------------------

    public function test_asking_for_a_change_does_not_touch_the_live_address(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'old@example.com']);

        Livewire::actingAs($user)->test(EditProfile::class)
            ->set('new_email', 'new@example.com')
            ->set('email_password', 'password')
            ->call('requestEmailChange')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('old@example.com', $user->email);
        $this->assertSame('new@example.com', $user->pending_email);
    }

    /**
     * Without this, an unattended logged-in session is a one-click account
     * takeover: change the address, walk away, then „forgot password".
     */
    public function test_the_wrong_password_changes_nothing(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Livewire::actingAs($user)->test(EditProfile::class)
            ->set('new_email', 'new@example.com')
            ->set('email_password', 'not-the-password')
            ->call('requestEmailChange')
            ->assertHasErrors('email_password');

        $this->assertNull($user->fresh()->pending_email);
        Notification::assertNothingSent();
    }

    public function test_an_address_somebody_already_has_is_refused_at_the_form(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(EditProfile::class)
            ->set('new_email', 'taken@example.com')
            ->set('email_password', 'password')
            ->call('requestEmailChange')
            ->assertHasErrors('new_email');

        $this->assertNull($user->fresh()->pending_email);
    }

    // --- the two mails ----------------------------------------------------

    /**
     * The confirmation goes to the NEW address and the warning to the OLD one.
     * Send the proof-of-ownership mail to the address already on file and it
     * proves nothing at all.
     */
    public function test_the_proof_goes_to_the_new_address_and_the_warning_to_the_old(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'old@example.com']);

        app(EmailChange::class)->request($user, 'new@example.com');

        Notification::assertSentOnDemand(
            ConfirmNewEmail::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'new@example.com',
        );

        Notification::assertSentTo($user, EmailChangeRequested::class,
            fn (EmailChangeRequested $n) => $n->newAddress === 'new@example.com');
    }

    /**
     * Account security mail ignores notification preferences. Somebody who
     * switched email off still gets told their account is being taken.
     */
    public function test_the_warning_goes_out_even_with_email_notifications_off(): void
    {
        Notification::fake();

        $user = User::factory()->create(['notify_email' => false]);

        app(EmailChange::class)->request($user, 'new@example.com');

        Notification::assertSentTo($user, EmailChangeRequested::class);
    }

    // --- confirming -------------------------------------------------------

    public function test_opening_the_link_moves_the_address(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $url  = $this->start($user);

        $this->get($url)->assertOk()->assertSee('Новият имейл е потвърден');

        $user->refresh();

        $this->assertSame('new@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->pending_email);
    }

    /** From any device, with no session — the mail is read where it is read. */
    public function test_the_link_works_from_a_browser_nobody_is_logged_into(): void
    {
        $user = User::factory()->create();
        $url  = $this->start($user);

        $this->get($url)->assertOk();

        $this->assertGuest();
        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    /** Same browser: finish the journey rather than showing a page. */
    public function test_the_browser_that_asked_is_sent_back_to_the_settings(): void
    {
        $user = User::factory()->create();
        $url  = $this->start($user);

        $this->actingAs($user)->get($url)->assertRedirect(route('profile.edit'));

        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    /**
     * THE RACE. An hour can pass between asking and confirming, and in it
     * somebody else may take the address. users.email is unique, so without
     * this check the confirmation dies as a constraint violation — a 500 on a
     * link the person was told to trust.
     */
    public function test_an_address_taken_in_the_meantime_fails_as_a_sentence_not_a_500(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $url  = $this->start($user);

        User::factory()->create(['email' => 'new@example.com']);

        $this->get($url)
            ->assertForbidden()
            ->assertSee('Вече има профил с този имейл');

        $user->refresh();

        $this->assertSame('old@example.com', $user->email);
        $this->assertNull($user->pending_email);
    }

    public function test_an_expired_link_explains_itself(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $url = URL::temporarySignedRoute('email.change.confirm', now()->subMinute(), [
            'id' => $user->getKey(), 'token' => 'whatever',
        ]);

        $this->get($url)->assertForbidden()->assertSee('Линкът е изтекъл');

        $this->assertSame('old@example.com', $user->fresh()->email);
    }

    public function test_a_tampered_token_moves_nothing(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $this->start($user);

        $this->get(URL::temporarySignedRoute('email.change.confirm', now()->addHour(), [
            'id' => $user->getKey(), 'token' => str_repeat('a', 40),
        ]))->assertForbidden();

        $this->assertSame('old@example.com', $user->fresh()->email);
        // A wrong guess must not burn the real pending change either.
        $this->assertSame('new@example.com', $user->fresh()->pending_email);
    }

    // --- cancelling -------------------------------------------------------

    /**
     * The cancel link has to work WITHOUT a session. It is for somebody whose
     * password an attacker already knows — telling them to log in first is
     * telling them to rely on the one thing that is no longer theirs alone.
     */
    public function test_the_cancel_link_works_for_somebody_not_logged_in(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $confirmUrl = $this->start($user);

        $token = $this->tokenFrom($confirmUrl);

        $cancel = URL::temporarySignedRoute('email.change.cancel', now()->addHour(), [
            'id' => $user->getKey(), 'token' => $token,
        ]);

        $this->get($cancel)->assertOk()->assertSee('Смяната е спряна');

        $this->assertNull($user->fresh()->pending_email);
        $this->assertSame('old@example.com', $user->fresh()->email);
    }

    /** And afterwards the confirmation link is dead, which is the whole job. */
    public function test_a_cancelled_change_cannot_then_be_confirmed(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $url  = $this->start($user);

        app(EmailChange::class)->cancel($user, $this->tokenFrom($url));

        $this->get($url)->assertForbidden();

        $this->assertSame('old@example.com', $user->fresh()->email);
    }

    /**
     * An attacker can cancel and immediately ask again. Telling the victim
     * „stopped" while a second request runs its course would be the one lie
     * here that costs somebody their account.
     */
    public function test_a_stale_cancel_link_does_not_claim_to_have_stopped_a_newer_change(): void
    {
        $user  = User::factory()->create();
        $first = $this->tokenFrom($this->start($user, 'first@example.com'));

        app(EmailChange::class)->cancel($user, $first);
        $user->refresh();

        // The attacker starts again.
        Notification::fake();
        app(EmailChange::class)->request($user->fresh(), 'second@example.com');

        $this->get(URL::temporarySignedRoute('email.change.cancel', now()->addHour(), [
            'id' => $user->getKey(), 'token' => $first,
        ]))
            ->assertForbidden()
            ->assertSee('по-стара заявка');

        $this->assertSame('second@example.com', $user->fresh()->pending_email);
    }

    /** Clicked twice, or the change lapsed on its own: still reassurance. */
    public function test_cancelling_nothing_is_reported_as_success(): void
    {
        $user = User::factory()->create();

        $this->get(URL::temporarySignedRoute('email.change.cancel', now()->addHour(), [
            'id' => $user->getKey(), 'token' => str_repeat('b', 40),
        ]))->assertOk()->assertSee('Смяната е спряна');
    }

    public function test_the_settings_page_can_cancel_a_pending_change(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        app(EmailChange::class)->request($user, 'new@example.com');

        Livewire::actingAs($user->fresh())->test(EditProfile::class)
            ->call('cancelEmailChange');

        $this->assertNull($user->fresh()->pending_email);
    }

    private function tokenFrom(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return basename($path);
    }
}
