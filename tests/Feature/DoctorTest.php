<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The deployment check.
 *
 * Worth testing precisely because nobody will read its output carefully until
 * the day something is wrong — and on that day it has to be right. The Turnstile
 * case is the one it exists for: blank keys are not an error anywhere else in
 * the codebase, by design, so this is the only thing that says so out loud.
 */
class DoctorTest extends TestCase
{
    use RefreshDatabase;

    /** A deployment that says it is the real site. */
    private function live(): void
    {
        config([
            'remarket.seo.indexable' => true,
            'app.url'                => 'https://example.bg',
            'app.debug'              => false,
            'mail.default'           => 'smtp',
            'mail.from.address'      => 'no-reply@example.bg',
            'remarket.phone_hash_salt' => 'sol',
            'remarket.verify.allow_log_channel_in_production' => false,
            // Set explicitly so these tests are about the doctor rather than
            // about whatever the testing environment happens to define.
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);
    }

    public function test_blank_turnstile_keys_fail_a_live_deployment(): void
    {
        $this->live();
        config([
            'remarket.turnstile.site_key'   => null,
            'remarket.turnstile.secret_key' => null,
        ]);

        $this->artisan('remarket:doctor')
            ->expectsOutputToContain('Turnstile is OFF')
            ->assertFailed();
    }

    /**
     * One blank key disables Turnstile entirely — `enabled()` requires both —
     * so a half-configured deployment must not look configured.
     */
    public function test_one_blank_key_is_still_off(): void
    {
        $this->live();
        config([
            'remarket.turnstile.site_key'   => '0x4AAA',
            'remarket.turnstile.secret_key' => null,
        ]);

        $this->artisan('remarket:doctor')
            ->expectsOutputToContain('TURNSTILE_SECRET_KEY')
            ->assertFailed();
    }

    public function test_configured_turnstile_passes(): void
    {
        $this->live();
        config([
            'remarket.turnstile.site_key'   => '0x4AAA',
            'remarket.turnstile.secret_key' => '0x4AAA-secret',
        ]);

        $this->artisan('remarket:doctor')
            ->expectsOutputToContain('Turnstile is configured')
            ->assertSuccessful();
    }

    /**
     * Cloudflare's dummy keys work on any hostname, which is what makes them
     * useful before the domain exists and dangerous after: the "always passes"
     * pair renders a real-looking widget that every bot clears. To every other
     * part of the codebase a key is a key, so this is the only thing that can
     * tell the difference.
     */
    public function test_cloudflare_test_keys_fail_a_live_deployment(): void
    {
        $this->live();
        config([
            'remarket.turnstile.site_key'   => '1x00000000000000000000AA',
            'remarket.turnstile.secret_key' => '1x0000000000000000000000000000000AA',
        ]);

        $this->artisan('remarket:doctor')
            ->expectsOutputToContain('TEST keys')
            ->assertFailed();
    }

    public function test_cloudflare_test_keys_are_fine_on_a_box_with_no_domain(): void
    {
        config([
            'remarket.seo.indexable'        => false,
            'remarket.turnstile.site_key'   => '1x00000000000000000000AA',
            'remarket.turnstile.secret_key' => '1x0000000000000000000000000000000AA',
            'app.url'                       => 'http://192.168.1.75:2323',
            'app.debug'                     => false,
            'mail.default'                  => 'smtp',
            'mail.from.address'             => 'no-reply@re-tech.bg',
            'remarket.phone_hash_salt'      => 'sol',
            'app.key'                       => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        $this->artisan('remarket:doctor')
            ->expectsOutputToContain('test keys')
            ->assertSuccessful();
    }

    /**
     * The LAN box has no keys on purpose and must not be told it is broken —
     * a check that cries wolf on the box you run it on every day is a check
     * nobody reads on the day it matters.
     */
    public function test_a_test_deployment_is_warned_not_failed(): void
    {
        config([
            'remarket.seo.indexable'        => false,
            'remarket.turnstile.site_key'   => null,
            'remarket.turnstile.secret_key' => null,
            'app.url'                       => 'http://192.168.1.75:2323',
            'app.debug'                     => false,
            'mail.default'                  => 'smtp',
            'mail.from.address'             => 'no-reply@re-tech.bg',
            'remarket.phone_hash_salt'      => 'sol',
            'app.key'                       => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        $this->artisan('remarket:doctor')->assertSuccessful();
    }

    /** Codes written to a log file instead of sent, on the public site. */
    public function test_the_log_verification_channel_fails_a_live_deployment(): void
    {
        $this->live();
        config([
            'remarket.turnstile.site_key'   => '0x4AAA',
            'remarket.turnstile.secret_key' => '0x4AAA-secret',
            'remarket.verify.allow_log_channel_in_production' => true,
        ]);

        $this->artisan('remarket:doctor')
            ->expectsOutputToContain('VERIFY_ALLOW_LOG_CHANNEL')
            ->assertFailed();
    }

    /** A LAN address cannot sign links for a domain. */
    public function test_a_lan_app_url_fails_a_live_deployment(): void
    {
        $this->live();
        config([
            'app.url'                       => 'http://192.168.1.75:2323',
            'remarket.turnstile.site_key'   => '0x4AAA',
            'remarket.turnstile.secret_key' => '0x4AAA-secret',
        ]);

        $this->artisan('remarket:doctor')->assertFailed();
    }

    /**
     * The check that cannot be made by reading .env: a worker that has died
     * leaves a site that responds normally and a table nobody drains.
     */
    public function test_a_stalled_queue_is_reported(): void
    {
        $this->live();
        config([
            'remarket.turnstile.site_key'   => '0x4AAA',
            'remarket.turnstile.secret_key' => '0x4AAA-secret',
            'queue.default'                 => 'database',
        ]);

        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => '{}',
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => now()->subMinutes(30)->getTimestamp(),
            'created_at'   => now()->subMinutes(30)->getTimestamp(),
        ]);

        $this->artisan('remarket:doctor')
            ->expectsOutputToContain('queue worker is not running')
            ->assertFailed();
    }

    public function test_a_job_queued_a_moment_ago_is_not_an_alarm(): void
    {
        $this->live();
        config([
            'remarket.turnstile.site_key'   => '0x4AAA',
            'remarket.turnstile.secret_key' => '0x4AAA-secret',
            'queue.default'                 => 'database',
        ]);

        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => '{}',
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => now()->getTimestamp(),
            'created_at'   => now()->getTimestamp(),
        ]);

        $this->artisan('remarket:doctor')->assertSuccessful();
    }
}
