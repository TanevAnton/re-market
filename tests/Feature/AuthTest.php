<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\VerifyPhone;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\Verification\PhoneVerifier;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
    }

    // --- registration ----------------------------------------------------

    public function test_a_visitor_can_register(): void
    {
        Livewire::test(Register::class)
            ->set('username', 'ivanko')
            ->set('email', 'ivan@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('password_confirmation', 'correct-horse-battery')
            ->set('seller_type', 'private')
            ->set('terms', true)
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('phone.verify'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['username' => 'ivanko']);
    }

    public function test_username_uniqueness_ignores_case(): void
    {
        User::factory()->create(['username' => 'Ivan']);

        Livewire::test(Register::class)
            ->set('username', 'ivan')
            ->set('email', 'other@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('password_confirmation', 'correct-horse-battery')
            ->set('terms', true)
            ->call('register')
            ->assertHasErrors('username');
    }

    public function test_registration_does_not_blow_up_sending_the_verification_mail(): void
    {
        // Regression: User implements MustVerifyEmail, so Laravel's notification
        // builds a link from route('verification.verify'). Without that route
        // defined, registration died with RouteNotFoundException after the user
        // row had already been inserted.
        Livewire::test(Register::class)
            ->set('username', 'mailcheck')
            ->set('email', 'mailcheck@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('password_confirmation', 'correct-horse-battery')
            ->set('terms', true)
            ->call('register')
            ->assertHasNoErrors();

        $this->assertNotNull(route('verification.verify', ['id' => 1, 'hash' => 'x']));
    }

    public function test_username_taken_in_another_case_fails_validation_not_the_database(): void
    {
        User::factory()->create(['username' => 'MiXeD']);

        // Must surface as a form error. Previously Rule::unique()->where() ANDed
        // its own exact-match check with ours, so this slipped through
        // validation and hit the lower(username) unique index as a 500.
        Livewire::test(Register::class)
            ->set('username', 'mixed')
            ->set('email', 'fresh@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('password_confirmation', 'correct-horse-battery')
            ->set('terms', true)
            ->call('register')
            ->assertHasErrors('username');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_terms_must_be_accepted(): void
    {
        Livewire::test(Register::class)
            ->set('username', 'someone')
            ->set('email', 'someone@example.com')
            ->set('password', 'correct-horse-battery')
            ->set('password_confirmation', 'correct-horse-battery')
            ->set('terms', false)
            ->call('register')
            ->assertHasErrors('terms');
    }

    // --- login -----------------------------------------------------------

    public function test_login_works_with_email_or_username(): void
    {
        User::factory()->create([
            'username' => 'petar',
            'email'    => 'petar@example.com',
            'password' => Hash::make('secret-passphrase'),
        ]);

        Livewire::test(Login::class)
            ->set('login', 'petar@example.com')
            ->set('password', 'secret-passphrase')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticated();
        auth()->logout();

        Livewire::test(Login::class)
            ->set('login', 'petar')
            ->set('password', 'secret-passphrase')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticated();
    }

    public function test_a_suspended_account_cannot_log_in(): void
    {
        User::factory()->create([
            'username'  => 'banned',
            'password'  => Hash::make('secret-passphrase'),
            'banned_at' => now(),
            'ban_reason' => 'scam',
        ]);

        Livewire::test(Login::class)
            ->set('login', 'banned')
            ->set('password', 'secret-passphrase')
            ->call('authenticate')
            ->assertHasErrors('login');

        $this->assertGuest();
    }

    // --- phone verification ----------------------------------------------

    public function test_sending_a_code_records_an_attempt(): void
    {
        $user = User::factory()->unverified()->create(['phone_hash' => null]);
        $this->actingAs($user);

        Livewire::test(VerifyPhone::class)
            ->set('phone', '0888123456')
            ->call('sendCode')
            ->assertHasNoErrors()
            ->assertSet('sent', true);

        $this->assertDatabaseCount('phone_verifications', 1);
    }

    public function test_an_invalid_number_is_refused_before_any_message_is_sent(): void
    {
        $user = User::factory()->unverified()->create(['phone_hash' => null]);
        $this->actingAs($user);

        Livewire::test(VerifyPhone::class)
            ->set('phone', '12345')
            ->call('sendCode')
            ->assertHasErrors('phone');

        $this->assertDatabaseCount('phone_verifications', 0);
    }

    public function test_a_number_already_bound_to_another_account_is_refused(): void
    {
        $taken = '+359888123456';
        User::factory()->create(['phone_hash' => User::hashPhone($taken)]);

        $user = User::factory()->unverified()->create(['phone_hash' => null]);
        $this->actingAs($user);

        Livewire::test(VerifyPhone::class)
            ->set('phone', '0888123456')
            ->call('sendCode')
            ->assertHasErrors('phone');

        // One phone, one account - the core anti-bot rule.
        $this->assertDatabaseCount('phone_verifications', 0);
    }

    public function test_the_correct_code_verifies_the_user(): void
    {
        $user = User::factory()->unverified()->create(['phone_hash' => null]);

        $attempt = PhoneVerification::create([
            'user_id'    => $user->id,
            'phone_hash' => User::hashPhone('+359888123456'),
            'channel'    => 'log',
            'code_hash'  => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $result = (new PhoneVerifier('127.0.0.1'))->verify($user, '0888123456', '123456');

        $this->assertTrue($result['verified']);
        $this->assertNotNull($user->fresh()->phone_verified_at);

        // Regression: verified_at was set with update() on a non-fillable
        // attribute, so it was silently dropped. The attempt stayed open and
        // the same code could be replayed until it expired.
        $this->assertNotNull($attempt->fresh()->verified_at);
    }

    public function test_a_wrong_code_is_rejected_and_costs_an_attempt(): void
    {
        $user = User::factory()->unverified()->create(['phone_hash' => null]);

        $attempt = PhoneVerification::create([
            'user_id'    => $user->id,
            'phone_hash' => User::hashPhone('+359888123456'),
            'channel'    => 'log',
            'code_hash'  => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $result = (new PhoneVerifier('127.0.0.1'))->verify($user, '0888123456', '999999');

        $this->assertFalse($result['verified']);
        $this->assertSame(1, $attempt->fresh()->attempts);
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_an_expired_code_cannot_be_used(): void
    {
        $user = User::factory()->unverified()->create(['phone_hash' => null]);

        PhoneVerification::create([
            'user_id'    => $user->id,
            'phone_hash' => User::hashPhone('+359888123456'),
            'channel'    => 'log',
            'code_hash'  => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
        ]);

        $result = (new PhoneVerifier('127.0.0.1'))->verify($user, '0888123456', '123456');

        $this->assertFalse($result['verified']);
        $this->assertSame('expired', $result['reason']);
    }

    // --- gating ----------------------------------------------------------

    public function test_browsing_stays_open_to_guests(): void
    {
        $this->get('/')->assertOk();
    }
}
