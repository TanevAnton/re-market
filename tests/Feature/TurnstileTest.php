<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Models\User;
use App\Support\Turnstile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The bot defence, which until now had no test of its own.
 *
 * It had coverage of a sort: sixteen tests across auth, password reset, offers
 * and reports passed because Turnstile was switched OFF, and failed the moment
 * somebody filled the keys in. That is the opposite of coverage — the suite
 * depended on the feature being disabled, and said so only by breaking.
 *
 * The keys are now pinned blank in phpunit.xml so no other test has to think
 * about this, and Turnstile's own behaviour is exercised here, with the HTTP
 * call faked. Nothing in this file reaches Cloudflare.
 */
class TurnstileTest extends TestCase
{
    use RefreshDatabase;

    /** Real-looking keys. The values never leave the process. */
    private function configured(): void
    {
        config([
            'remarket.turnstile.site_key'   => '0x4AAAAAAABtestSiteKey',
            'remarket.turnstile.secret_key' => '0x4AAAAAAABtestSecretKey',
        ]);
    }

    private function cloudflareSays(bool $success, array $errors = []): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([
                'success'     => $success,
                'error-codes' => $errors,
            ]),
        ]);
    }

    // --- switched off -----------------------------------------------------

    /**
     * Unconfigured, it disables itself completely and does not call anybody.
     *
     * This is deliberate and is what makes local development and the LAN box
     * usable: a challenge that cannot be solved there would block the exact
     * flows most in need of testing. The cost is that a live deployment with a
     * blank key silently has no bot defence, which is why `remarket:doctor`
     * says so out loud — see DoctorTest.
     */
    public function test_with_no_keys_it_waves_everything_through(): void
    {
        Http::fake();

        config([
            'remarket.turnstile.site_key'   => null,
            'remarket.turnstile.secret_key' => null,
        ]);

        $this->assertFalse(Turnstile::enabled());
        $this->assertTrue(Turnstile::verify(null));
        $this->assertTrue(Turnstile::verify('anything at all'));

        Http::assertNothingSent();
    }

    /** One key is not half a defence. Both, or it is off. */
    public function test_one_key_is_the_same_as_none(): void
    {
        config([
            'remarket.turnstile.site_key'   => '0x4AAAAAAABtestSiteKey',
            'remarket.turnstile.secret_key' => null,
        ]);

        $this->assertFalse(Turnstile::enabled());
    }

    // --- switched on ------------------------------------------------------

    public function test_a_missing_token_is_refused_without_asking_cloudflare(): void
    {
        Http::fake();
        $this->configured();

        $this->assertFalse(Turnstile::verify(null));
        $this->assertFalse(Turnstile::verify(''));

        // No round trip for something we can answer ourselves.
        Http::assertNothingSent();
    }

    public function test_a_token_cloudflare_accepts_passes(): void
    {
        $this->configured();
        $this->cloudflareSays(true);

        $this->assertTrue(Turnstile::verify('a-real-looking-token', '1.2.3.4'));

        Http::assertSent(fn ($request) => $request['secret'] === '0x4AAAAAAABtestSecretKey'
            && $request['response'] === 'a-real-looking-token'
            && $request['remoteip'] === '1.2.3.4');
    }

    public function test_a_token_cloudflare_rejects_fails(): void
    {
        $this->configured();
        $this->cloudflareSays(false, ['invalid-input-response']);

        $this->assertFalse(Turnstile::verify('a-forged-token'));
    }

    /**
     * A reply that is not an outright success is a failure.
     *
     * Cloudflare returning something unexpected — an empty body, an error page,
     * a shape that changed — must not read as a pass. `($response['success']
     * ?? false) !== true` is what makes that true, and it is one `??` away from
     * being the other thing.
     */
    public function test_a_reply_without_success_is_a_failure(): void
    {
        $this->configured();

        Http::fake(['challenges.cloudflare.com/*' => Http::response([])]);

        $this->assertFalse(Turnstile::verify('a-token'));
    }

    // --- the decision nobody had tested -----------------------------------

    /**
     * WHEN CLOUDFLARE IS UNREACHABLE, EVERYTHING PASSES.
     *
     * The one place in this codebase where failing open is right, and the only
     * one that had never been verified. If it ever regresses to failing closed,
     * a Cloudflare outage becomes a RIGO outage: no signup, no offer, no
     * password reset, for as long as it lasts — and you find out during
     * somebody else's incident.
     *
     * The downside is a few minutes of unfiltered traffic, against which phone
     * verification, the moderation queue and the rate limits are all still
     * standing. That trade is the right way round, and this test is what keeps
     * it that way round.
     */
    public function test_an_unreachable_cloudflare_does_not_take_the_site_down(): void
    {
        $this->configured();

        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->assertTrue(Turnstile::verify('a-token'));
    }

    // --- the component side -----------------------------------------------

    /**
     * A token is single use, so it is cleared whether it worked or not.
     *
     * Leaving a spent one in the component means the seller's SECOND attempt
     * fails for a reason that has nothing to do with anything they did — and
     * the message they get says „опитай отново", which they already did.
     */
    public function test_a_spent_token_is_cleared_from_the_component(): void
    {
        $this->configured();
        $this->cloudflareSays(false);

        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        Livewire::test(Login::class)
            ->set('login', $user->email)
            ->set('password', 'correct-horse')
            ->set('turnstileToken', 'spent-token')
            ->call('authenticate')
            ->assertHasErrors('turnstile')
            ->assertSet('turnstileToken', '');
    }

    /** And with a good token the form behaves as if Turnstile were not there. */
    public function test_a_good_token_lets_the_form_through(): void
    {
        $this->configured();
        $this->cloudflareSays(true);

        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        Livewire::test(Login::class)
            ->set('login', $user->email)
            ->set('password', 'correct-horse')
            ->set('turnstileToken', 'a-good-token')
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    /**
     * The guard against this whole class of problem coming back.
     *
     * phpunit.xml pins both keys blank. If somebody removes those two lines,
     * the suite starts inheriting whatever is in the developer's .env and
     * sixteen unrelated tests begin passing or failing depending on a
     * production setting.
     */
    public function test_the_suite_runs_with_turnstile_pinned_off(): void
    {
        $this->assertSame('', (string) env('TURNSTILE_SITE_KEY'),
            'phpunit.xml must pin TURNSTILE_SITE_KEY blank');

        $this->assertSame('', (string) env('TURNSTILE_SECRET_KEY'),
            'phpunit.xml must pin TURNSTILE_SECRET_KEY blank');
    }

    // --- where the script comes from --------------------------------------

    /*
     * THE BUG THESE FOUR EXIST FOR, 9 Oct.
     *
     * The widget used to push Cloudflare's script into the layout's stack from
     * inside its own @if, under @once. That works only when the widget is
     * rendered during the INITIAL page render. The listing form renders it in
     * the step-4 branch of a four-step wizard, so on first load it was never
     * rendered at all - and a Livewire update cannot add to a stack the layout
     * has already resolved.
     *
     * Result: an empty div on the publish step, window.turnstile undefined, no
     * token, and every publish refused on blank($token) without so much as a
     * request to Cloudflare. No widget to solve, nothing in the log, and
     * "опитай отново" was the only advice the seller got.
     *
     * It was invisible for a month because an unconfigured Turnstile passes
     * everything. The keys went in on 8 Oct and the form died the same evening.
     * The code did not break - it started working, against a page that could
     * never satisfy it.
     */

    /** The script is present on a page that renders no widget at all. */
    public function test_the_layout_loads_the_script_so_any_component_can_use_it(): void
    {
        $this->configured();

        $this->get(route('legal.terms'))
            ->assertOk()
            ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js', false);
    }

    /** Unconfigured it stays silent, which is what keeps local work usable. */
    public function test_an_unconfigured_site_loads_no_third_party_script(): void
    {
        $this->get(route('legal.terms'))
            ->assertOk()
            ->assertDontSee('challenges.cloudflare.com', false);
    }

    /**
     * The widget file must not grow its own script tag back.
     *
     * Blade comments are stripped first. A guard that matches its own
     * documentation has already happened twice here - the `group-hover` one and
     * the `forceDelete` one - and the comment in that file necessarily
     * discusses the very thing it forbids.
     */
    public function test_the_widget_ships_no_script_of_its_own(): void
    {
        $widget = file_get_contents(resource_path('views/components/turnstile.blade.php'));
        $code   = preg_replace('/\{\{--.*?--\}\}/s', '', $widget);

        $this->assertStringNotContainsString('challenges.cloudflare.com', $code,
            'The script belongs in the layout. A push from a conditionally rendered '
            .'component reaches nothing - see the comment in that file.');

        $this->assertStringNotContainsString('@push', $code,
            'Same reason: @push from a Livewire update cannot reach a resolved stack.');
    }

    // --- the listing form carries no challenge ----------------------------

    /**
     * Publishing is authenticated, so the challenge was already cleared at
     * registration, and the controls that fit this step are the ones that
     * exist: NEW_ACCOUNT_MODERATED_LISTINGS, the screener, the queue. None of
     * them costs an honest seller anything, and this site has two sellers.
     *
     * If it is ever added back, it must be added back working - which means the
     * script loading from the layout, as the tests above pin.
     */
    public function test_publishing_a_listing_asks_for_no_challenge(): void
    {
        $this->assertNotContains(
            \App\Livewire\Concerns\ChecksTurnstile::class,
            class_uses_recursive(\App\Livewire\Listings\CreateListing::class),
            'The listing wizard deliberately carries no Turnstile check.',
        );

        $view = file_get_contents(resource_path('views/livewire/listings/create-listing.blade.php'));

        $this->assertStringNotContainsString(
            '<x-turnstile',
            preg_replace('/\{\{--.*?--\}\}/s', '', $view),
            'And no widget in the view either.',
        );
    }
}
