<?php

namespace Tests\Feature;

use App\Livewire\Auth\VerifyPhone;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\Verification\PhoneVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The resend cooldown, which exists to stop the SMS bill rather than an attacker.
 *
 * The five-an-hour cap is the abuse limit. This is the ordinary-person limit:
 * a carrier takes eight seconds, the user presses „изпрати отново" three times,
 * and three more messages go out at EUR 0.076 each for a code that was already
 * on its way. On OTP systems that retap is routinely the largest single source
 * of avoidable spend, and nothing in the cap touches it — five sends inside ten
 * seconds were perfectly within the rules.
 *
 * Everything here pins config explicitly rather than trusting the environment.
 * The verify block is NOT pinned in phpunit.xml, so a test that relied on it
 * would pass or fail according to whatever is in the developer's own .env —
 * which is the trap recorded in the status doc and has now fired seven times.
 */
class PhoneResendCooldownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The log channel reports success without sending anything, which is
        // exactly what a test of „how many times did we send" wants.
        config([
            'remarket.verify.channels'        => ['log'],
            'remarket.verify.resend_cooldown' => 90,
            'remarket.verify.code_ttl'        => 10,
        ]);

        RateLimiter::clear('verify:ip:127.0.0.1');
    }

    private function sender(): PhoneVerifier
    {
        return new PhoneVerifier('127.0.0.1');
    }

    public function test_a_second_request_inside_the_window_sends_nothing(): void
    {
        $user = User::factory()->create();

        $first = $this->sender()->send($user, '0888123456');
        $this->assertTrue($first['sent']);

        $second = $this->sender()->send($user, '0888123456');

        $this->assertFalse($second['sent']);
        $this->assertSame('cooldown', $second['reason']);
        $this->assertSame('log', $second['channel'], 'the caller needs to know how the live code was sent');
        $this->assertGreaterThan(0, $second['retry_after']);

        // The money question: exactly one message left the building.
        $this->assertDatabaseCount('phone_verifications', 1);
    }

    public function test_the_window_expires(): void
    {
        $user = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00'));
        $this->assertTrue($this->sender()->send($user, '0888123456')['sent']);

        Carbon::setTestNow(Carbon::parse('2026-10-10 12:01:31'));   // 91s
        $this->assertTrue($this->sender()->send($user, '0888123456')['sent']);

        $this->assertDatabaseCount('phone_verifications', 2);

        Carbon::setTestNow();
    }

    /**
     * A code with no guesses left is dead, and refusing to replace it would
     * strand the person for the rest of the cooldown with no way forward.
     */
    public function test_a_spent_code_is_replaced_rather_than_withheld(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->sender()->send($user, '0888123456')['sent']);

        PhoneVerification::latest('id')->first()
            ->forceFill(['attempts' => config('remarket.verify_max_attempts', 5)])->save();

        $again = $this->sender()->send($user, '0888123456');

        $this->assertTrue($again['sent'], 'a used-up code must not block a new one');
        $this->assertDatabaseCount('phone_verifications', 2);
    }

    /** An expired code is not a live one either. */
    public function test_an_expired_code_is_replaced(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->sender()->send($user, '0888123456')['sent']);

        PhoneVerification::latest('id')->first()
            ->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertTrue($this->sender()->send($user, '0888123456')['sent']);
        $this->assertDatabaseCount('phone_verifications', 2);
    }

    /** Zero switches the behaviour off, for anyone who wants the old way back. */
    public function test_a_zero_cooldown_disables_it(): void
    {
        config(['remarket.verify.resend_cooldown' => 0]);

        $user = User::factory()->create();

        $this->assertTrue($this->sender()->send($user, '0888123456')['sent']);
        $this->assertTrue($this->sender()->send($user, '0888123456')['sent']);

        $this->assertDatabaseCount('phone_verifications', 2);
    }

    // --- what the person actually sees ------------------------------------

    /**
     * THE POINT OF THE WHOLE THING.
     *
     * A cooldown that surfaces as „опитай по-късно" is worse than no cooldown:
     * the code is already in their hand and the site has just told them to go
     * away. The form must stay open and the message must be reassuring.
     *
     * The live code is created through the service rather than by calling
     * sendCode twice, because a second Livewire call in the same chain blows
     * up inside Livewire's own snapshot handling (see the note below) and that
     * has nothing to do with what this test is about.
     */
    public function test_the_page_keeps_the_code_box_open_during_the_cooldown(): void
    {
        /*
         * phone_verified_at => null MATTERS, and the failure it causes looks
         * like nothing to do with phones.
         *
         * UserFactory sets phone_verified_at to now() by default, and
         * VerifyPhone::mount() rightly bounces an already-verified user to
         * browse. The component then never renders, so the first round trip in
         * this chain posts a redirect where Livewire expects a snapshot and
         * blows up with „Invalid Livewire snapshot structure" - an error that
         * names neither this factory default nor that redirect.
         */
        $user = User::factory()->create(['phone_verified_at' => null]);

        // A code is already out, sent moments ago.
        $this->assertTrue($this->sender()->send($user, '0888123456')['sent']);

        $this->actingAs($user);

        Livewire::test(VerifyPhone::class)
            ->set('phone', '0888123456')
            ->call('sendCode')
            ->assertHasNoErrors('phone')
            ->assertSet('sent', true)
            ->assertSee('Вече ти изпратихме код');

        // And no second message was bought to say so.
        $this->assertDatabaseCount('phone_verifications', 1);
    }
}
