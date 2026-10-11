<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The verification link, and the one property that matters about it: it works
 * from wherever the mail was opened.
 *
 * This route sat behind `auth` for a month, which meant it only ever worked in
 * the browser that registered. Somebody who opened the mail on their phone got
 * the login screen and was asked to sign in to the account they were in the
 * middle of proving they owned. These tests exist so that does not come back by
 * somebody tidying the route into the authenticated group.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function linkFor(User $user, ?string $email = null): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id'   => $user->getKey(),
                'hash' => sha1($email ?? $user->getEmailForVerification()),
            ],
        );
    }

    /** The whole point. No session, different device, link still works. */
    public function test_the_link_verifies_from_a_browser_nobody_is_logged_into(): void
    {
        Event::fake([Verified::class]);

        $user = User::factory()->unverified()->create();

        $this->get($this->linkFor($user))
            ->assertOk()
            ->assertSee('Имейлът е потвърден');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest();

        Event::assertDispatched(Verified::class);
    }

    /**
     * The link proves an address. It is not a password that lives in a mailbox,
     * and a forwarded mail must not hand anybody the account.
     */
    public function test_the_link_does_not_sign_anybody_in(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->linkFor($user))->assertOk();

        $this->assertGuest();
    }

    /** Clicked in the tab that registered: finish the journey, do not stop. */
    public function test_the_same_browser_is_sent_on_to_the_listings(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get($this->linkFor($user))
            ->assertRedirect(route('browse'));

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    /**
     * A shared machine. Verifying one address is no reason to touch whoever is
     * signed in here.
     */
    public function test_another_users_session_is_left_alone(): void
    {
        $owner    = User::factory()->unverified()->create();
        $bystander = User::factory()->create();

        $this->actingAs($bystander)
            ->get($this->linkFor($owner))
            ->assertOk();

        $this->assertNotNull($owner->fresh()->email_verified_at);
        $this->assertAuthenticatedAs($bystander);
    }

    public function test_a_second_click_says_so_rather_than_failing(): void
    {
        Event::fake([Verified::class]);

        $user = User::factory()->create();   // already verified

        $this->get($this->linkFor($user))
            ->assertOk()
            ->assertSee('вече е потвърден');

        // Verified fires once in an account's life, not once per click.
        Event::assertNotDispatched(Verified::class);
    }

    public function test_an_unsigned_link_verifies_nothing(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get(route('verification.verify', [
            'id'   => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]))->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    /** Tampering with the id while keeping somebody else's signature. */
    public function test_a_signature_for_one_account_does_not_verify_another(): void
    {
        $mine    = User::factory()->unverified()->create();
        $someone = User::factory()->unverified()->create();

        $link = str_replace(
            "/{$mine->getKey()}/",
            "/{$someone->getKey()}/",
            $this->linkFor($mine),
        );

        $this->get($link)->assertForbidden();

        $this->assertNull($someone->fresh()->email_verified_at);
        $this->assertNull($mine->fresh()->email_verified_at);
    }

    /**
     * The hash is of the address the mail went to, so changing the address
     * retires every link that was sent to the old one.
     */
    public function test_a_link_stops_working_once_the_address_changes(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'old@example.com']);
        $link = $this->linkFor($user);

        $user->forceFill(['email' => 'new@example.com'])->save();

        $this->get($link)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    /**
     * An expired link gets a page that explains itself. Sixty minutes is easy
     * to miss, and „Access denied" at that moment reads as a broken account
     * rather than as „ask for another one".
     */
    public function test_an_expired_link_explains_itself_instead_of_a_bare_403(): void
    {
        $user = User::factory()->unverified()->create();

        $link = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id'   => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->get($link)
            ->assertForbidden()
            ->assertSee('Линкът е изтекъл')
            ->assertSee(route('login'), escape: false);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    /** A deleted account's old link must not quietly revive it. */
    public function test_a_deleted_account_cannot_be_verified(): void
    {
        $user = User::factory()->unverified()->create();
        $link = $this->linkFor($user);

        $user->delete();

        $this->get($link)->assertForbidden();
    }
}
