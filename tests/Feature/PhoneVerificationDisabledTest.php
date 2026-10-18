<?php

namespace Tests\Feature;

use App\Livewire\Auth\Register;
use App\Livewire\Auth\VerifyPhone;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The OFF side of `remarket.verify.phone_enabled`, switched off 18 Oct.
 *
 * The other four phone tests turn the flag ON, because a test that passes
 * because its feature is switched off is not coverage. This class is the
 * mirror: it is the only place that runs with the flag in its PRODUCTION
 * state, and what it defends is that „off" means off rather than merely
 * hidden.
 *
 * The expensive failure here is not a visible error. It is a path that still
 * reaches PhoneVerifier and quietly bills EUR 0.076 a message for a feature
 * nobody is being offered — so most of these assert that nothing was sent,
 * rather than that something looked right.
 */
class PhoneVerificationDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        // Explicit, not inherited from the config default. A test that depends
        // on an env file is a test that passes differently on two machines.
        config(['remarket.verify.phone_enabled' => false]);
    }

    /** @return array<string, mixed> */
    private function validForm(array $overrides = []): array
    {
        return $overrides + [
            'username'              => 'ivanko',
            'email'                 => 'ivan@example.com',
            'phone'                 => '0888123456',
            'password'              => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'terms'                 => true,
        ];
    }

    private function register(array $overrides = []): \Livewire\Features\SupportTesting\Testable
    {
        $test = Livewire::test(Register::class);

        foreach ($this->validForm($overrides) as $field => $value) {
            $test->set($field, $value);
        }

        return $test->call('register');
    }

    /**
     * THE POINT OF THE WHOLE CHANGE: the number is still required and still
     * stored, it is simply no longer proven.
     */
    public function test_the_phone_is_still_required(): void
    {
        $this->register(['phone' => ''])->assertHasErrors('phone');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_number_is_still_stored_as_a_hash(): void
    {
        $this->register()->assertHasNoErrors();

        $user = User::firstOrFail();

        $this->assertNotNull($user->phone_hash);
        $this->assertSame('3456', $user->phone_last4);

        // On file, not proven. That distinction is the entire change.
        $this->assertNull($user->phone_verified_at);
    }

    public function test_registration_sends_no_code(): void
    {
        $this->register()->assertHasNoErrors();

        $this->assertDatabaseCount('phone_verifications', 0);
    }

    public function test_signup_lands_on_the_email_notice(): void
    {
        $this->register()
            ->assertHasNoErrors()
            ->assertRedirect(route('verification.notice'));
    }

    /**
     * A hand-crafted POST is the whole reason the allowed set shrinks rather
     * than the rule being dropped. Without this, somebody could set the field
     * directly and walk into a gateway that bills per message.
     */
    public function test_choosing_sms_by_hand_is_refused_and_sends_nothing(): void
    {
        $this->register(['verify_via' => 'sms'])->assertHasErrors('verify_via');

        $this->assertDatabaseCount('phone_verifications', 0);
        $this->assertDatabaseCount('users', 0);
    }

    /** The choice is not a choice, so it is not shown. */
    public function test_the_register_page_does_not_offer_sms(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertDontSee('Как да потвърдим профила')
            ->assertDontSee('Пращаме код на телефона ти');
    }

    /**
     * The one that actually protects the bill: a stale bookmark or a link in an
     * old email must not reach a live „send me a code" button.
     */
    public function test_the_verify_phone_page_is_gone(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('phone.verify'))->assertNotFound();
    }

    public function test_the_verify_phone_component_refuses_directly(): void
    {
        $this->actingAs(User::factory()->unverified()->create());

        // Livewire turns abort() into a 404 RESPONSE rather than an exception —
        // assertNotFound, never expectException. Standing entry in the gotchas.
        Livewire::test(VerifyPhone::class)->assertNotFound();
    }

    /**
     * The account still opens, by the one proof that is still offered. If this
     * breaks, switching SMS off has locked every new signup out of the site.
     */
    public function test_an_account_still_unlocks_by_email(): void
    {
        Notification::fake();

        $this->register()->assertHasNoErrors();

        $user = User::firstOrFail();
        $this->assertNull($user->email_verified_at);

        $user->markEmailAsVerified();

        $this->actingAs($user->fresh())
            ->get(route('listing.create'))
            ->assertOk();
    }

    /**
     * An account that proved its number BEFORE the flag went off keeps that
     * proof, and keeps the access it bought. Switching a feature off must not
     * retroactively demote the people who used it.
     */
    public function test_an_already_proven_number_still_counts(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'phone_verified_at' => now(),
        ]);

        $this->actingAs($user)->get(route('listing.create'))->assertOk();
    }
}
