<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The site runs on a box behind an Apache reverse proxy on the LAN, which
 * terminates TLS and forwards over plain HTTP.
 *
 * This is tested because both ways of getting it wrong fail silently.
 *
 * Trust nothing, and every request carries the proxy's address: rate limiting
 * on login, registration and password reset counts all visitors as one, so one
 * attacker locks out the whole site while a distributed attempt never trips it
 * at all. The scheme reads as http on an https page, and generated URLs break.
 *
 * Trust too widely, and a trusted proxy is believed about who the client is -
 * so anything else on the LAN can set X-Forwarded-For and wear an address that
 * is not its own.
 *
 * Nothing about either failure shows up in a log or a page. Only a test does.
 */
class TrustedProxyTest extends TestCase
{
    /** The address of the Apache reverse proxy, as bootstrap/app.php names it. */
    private const PROXY = '192.168.1.77';

    /** Another machine on the same LAN. Not a proxy, and must never be believed. */
    private const NEIGHBOUR = '192.168.1.200';

    private const CLIENT = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        // Probes rather than real pages: what is under test is the global
        // middleware stack, and a real route would drag in a layout, a database
        // and a dozen reasons to fail for something unrelated.
        Route::get('/_proxy_probe_ip', fn () => request()->ip());
        Route::get('/_proxy_probe_scheme', fn () => request()->isSecure() ? 'secure' : 'plain');
    }

    public function test_a_forwarded_address_from_the_proxy_is_believed(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->get('/_proxy_probe_ip', ['X-Forwarded-For' => self::CLIENT])
            ->assertOk()
            ->assertSee(self::CLIENT);
    }

    /**
     * The one that matters. If this ever passes the forged address through, a
     * visitor picks their own rate-limit bucket and the limiter stops being a
     * limit.
     */
    public function test_a_forwarded_address_from_anywhere_else_is_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::NEIGHBOUR])
            ->get('/_proxy_probe_ip', ['X-Forwarded-For' => self::CLIENT])
            ->assertOk()
            ->assertSee(self::NEIGHBOUR)
            ->assertDontSee(self::CLIENT);
    }

    public function test_the_proxy_can_report_that_the_original_request_was_https(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->get('/_proxy_probe_scheme', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertSee('secure');
    }

    public function test_an_untrusted_host_cannot_claim_the_request_was_https(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::NEIGHBOUR])
            ->get('/_proxy_probe_scheme', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertSee('plain');
    }

    /**
     * A plain unproxied request must still work, or local development and the
     * LAN port stop being usable.
     */
    public function test_a_request_with_no_forwarded_headers_is_untouched(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::CLIENT])
            ->get('/_proxy_probe_ip')
            ->assertOk()
            ->assertSee(self::CLIENT);
    }

    /**
     * The behaviour above is one line in bootstrap/app.php. A refactor that
     * drops it, or an afternoon of debugging that widens it to '*', leaves
     * every test above still passing on a machine where no proxy is involved.
     * This one reads the file.
     */
    public function test_the_trusted_proxy_list_is_explicit_and_not_a_wildcard(): void
    {
        // Comments in that file discuss the wildcard, so they come out before
        // the search - otherwise the guard matches its own documentation.
        $code = preg_replace(
            ['#/\*.*?\*/#s', '#//.*#'],
            '',
            (string) file_get_contents(base_path('bootstrap/app.php')),
        );

        $this->assertDoesNotMatchRegularExpression(
            '/trustProxies\s*\(\s*at:\s*[\'"]\*[\'"]/',
            (string) $code,
            'trustProxies must name exact addresses. A wildcard trusts whoever connects, '
            .'which lets any client forge X-Forwarded-For and choose its own identity.',
        );

        $this->assertMatchesRegularExpression(
            '/trustProxies\s*\(\s*at:\s*\[/',
            (string) $code,
            'The reverse proxy must be trusted explicitly, or every visitor behind it '
            .'shares one rate-limit bucket and the scheme is read as http.',
        );
    }
}
