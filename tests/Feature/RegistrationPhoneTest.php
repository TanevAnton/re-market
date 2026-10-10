<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccountIsVerified;
use App\Livewire\Auth\Register;
use App\Models\City;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Registration now asks for a phone number and asks how to prove the account.
 *
 * Two things here are easy to get wrong and expensive to get wrong:
 *
 *   THE SQUATTING HOLE. A required phone with the obvious uniqueness rule -
 *   refuse any number already on file - lets anybody lock a stranger out of the
 *   site permanently. Sign up with their number, never verify it, done. So an
 *   unproven claim blocks nothing, and proving a number wipes everyone else's
 *   claim on it.
 *
 *   THE DEAD END. If SMS is offered as a way to prove the account, then proving
 *   it by SMS has to actually unlock the site. Leaving Laravel's
 *   EnsureEmailIsVerified on the `verified` alias would bounce that person to
 *   „потвърди имейла си" on every page that matters, after they did exactly
 *   what the site asked.
 */
class RegistrationPhoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        // The log channel reports success without sending or spending.
        config(['remarket.verify.channels' => ['log']]);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'username'              => 'ivan_bg',
            'email'                 => 'ivan@example.com',
            'phone'                 => '0888123456',
            'password'              => 'Parola-123456',
            'password_confirmation' => 'Parola-123456',
            'city_id'               => City::first()->id,
            'seller_type'           => 'private',
            'verify_via'            => 'email',
            'terms'                 => true,
        ], $overrides);
    }

    private function submit(array $overrides = [])
    {
        $test = Livewire::test(Register::class);

        foreach ($this->form($overrides) as $field => $value) {
            $test->set($field, $value);
        }

        return $test->call('register');
    }

    // --- the number itself -------------------------------------------------

    public function test_a_phone_is_required(): void
    {
        $this->submit(['phone' => ''])->assertHasErrors('phone');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_number_that_is_not_a_bulgarian_mobile_is_refused(): void
    {
        foreach (['0700123456', '+441234567890', '12345', 'не е номер'] as $bad) {
            $this->submit(['phone' => $bad])->assertHasErrors('phone');
        }

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * Stored hashed and UNVERIFIED. A number on file is a claim, not proof.
     */
    public function test_the_number_is_stored_hashed_and_unverified(): void
    {
        $this->submit();

        $user = User::firstWhere('username', 'ivan_bg');

        $this->assertNotNull($user);
        $this->assertNull($user->phone_verified_at, 'registering does not prove the number');
        $this->assertSame(User::hashPhone('+359888123456'), $user->phone_hash);
        $this->assertSame('3456', $user->phone_last4);

        // Never in clear, in any column. getAttributes() returns raw database
        // values, so a JSON column arrives as a string already - but encode
        // defensively rather than casting, which fatals on an array.
        foreach ($user->getAttributes() as $column => $value) {
            $this->assertStringNotContainsString(
                '888123456',
                is_scalar($value) || $value === null ? (string) $value : json_encode($value),
                "the number is readable in users.{$column}",
            );
        }
    }

    /** The four ways a Bulgarian writes one number all fold into the same hash. */
    public function test_the_same_number_written_four_ways_is_one_number(): void
    {
        $this->submit(['phone' => '+359 88 812 3456']);

        $this->assertSame(
            User::hashPhone('+359888123456'),
            User::firstWhere('username', 'ivan_bg')->phone_hash,
        );
    }

    // --- uniqueness, and the hole in the obvious version -------------------

    public function test_a_number_somebody_has_proven_cannot_be_reused(): void
    {
        $owner = User::factory()->create();
        $owner->setPhone('+359888123456');
        $owner->phone_verified_at = now();
        $owner->save();

        $this->submit()->assertHasErrors('phone');
    }

    /**
     * THE ONE THAT MATTERS. An unproven claim must not block anybody, or
     * registering with a stranger's number locks them out of the site for good.
     */
    public function test_an_unproven_claim_blocks_nobody(): void
    {
        $squatter = User::factory()->create(['phone_verified_at' => null]);
        $squatter->setPhone('+359888123456');
        $squatter->save();

        $this->submit()->assertHasNoErrors('phone');

        $this->assertNotNull(User::firstWhere('username', 'ivan_bg'));
    }

    /**
     * And the claim MOVES rather than duplicating.
     *
     * users.phone_hash is UNIQUE, so "an unproven claim blocks nobody" cannot
     * mean "two rows hold the number". Registration releases the old claim in
     * the same transaction, which is what keeps both the constraint and the
     * rule true at once.
     */
    public function test_registering_takes_the_number_off_an_unproven_claim(): void
    {
        $squatter = User::factory()->create(['phone_verified_at' => null]);
        $squatter->setPhone('+359888123456');
        $squatter->save();

        $this->submit()->assertHasNoErrors('phone');

        $this->assertNull($squatter->fresh()->phone_hash, 'the unproven claim is released');

        $this->assertSame(
            User::hashPhone('+359888123456'),
            User::firstWhere('username', 'ivan_bg')->phone_hash,
        );
    }

    /** Once proven, it stops moving. */
    public function test_a_proven_number_is_not_released_to_a_newcomer(): void
    {
        $owner = User::factory()->create();
        $owner->setPhone('+359888123456');
        $owner->phone_verified_at = now();
        $owner->save();

        $this->submit()->assertHasErrors('phone');

        $this->assertSame(
            User::hashPhone('+359888123456'),
            $owner->fresh()->phone_hash,
            'a proven claim is untouched by a failed registration',
        );
    }

    // --- which proof they chose -------------------------------------------

    public function test_choosing_email_sends_no_code_and_goes_to_the_email_notice(): void
    {
        $this->submit(['verify_via' => 'email'])
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('phone_verifications', 0);
    }

    public function test_choosing_sms_sends_a_code_and_goes_to_the_phone_page(): void
    {
        $this->submit(['verify_via' => 'sms'])
            ->assertRedirect(route('phone.verify'));

        $this->assertDatabaseCount('phone_verifications', 1);
    }

    public function test_an_unknown_channel_is_refused(): void
    {
        $this->submit(['verify_via' => 'carrier-pigeon'])->assertHasErrors('verify_via');
    }

    /**
     * The verification email goes out either way. That address is where every
     * offer, message and deal notification lands, so it needs confirming
     * eventually even for somebody who unlocks the account by SMS today.
     */
    public function test_the_verification_email_is_sent_whichever_proof_was_chosen(): void
    {
        Notification::fake();

        $this->submit(['verify_via' => 'sms']);

        Notification::assertSentTo(User::firstWhere('username', 'ivan_bg'),
            \App\Notifications\VerifyEmailAddress::class);
    }

    // --- the gate ----------------------------------------------------------

    private function passesGate(User $user): bool
    {
        $request = Request::create('/anything');
        $request->setUserResolver(fn () => $user);

        $response = (new EnsureAccountIsVerified())
            ->handle($request, fn () => new Response('through'));

        return $response->getContent() === 'through';
    }

    public function test_a_confirmed_email_opens_the_gate(): void
    {
        $this->assertTrue($this->passesGate(
            User::factory()->create(['email_verified_at' => now(), 'phone_verified_at' => null]),
        ));
    }

    /** The whole reason the gate was replaced. */
    public function test_a_confirmed_phone_opens_the_gate_too(): void
    {
        $this->assertTrue($this->passesGate(
            User::factory()->create(['email_verified_at' => null, 'phone_verified_at' => now()]),
        ));
    }

    public function test_neither_keeps_the_gate_shut(): void
    {
        $this->assertFalse($this->passesGate(
            User::factory()->unverified()->create(),
        ));
    }
}
