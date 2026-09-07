<?php

namespace Tests\Feature;

use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The theme is decided server-side.
 *
 * These tests exist because the previous localStorage version failed in a way
 * nobody would write a test for: the page was correct, then changed on the next
 * refresh. The only durable fix is that the first byte of HTML is already right,
 * which is a thing a request test can actually assert.
 */
class ThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
    }

    public function test_no_cookie_means_follow_the_operating_system(): void
    {
        $response = $this->get(route('browse'));

        // Not "light" - the OS may well say dark, and only the browser knows.
        $response->assertSee('data-theme="system"', false);
        $response->assertDontSee('class="h-full dark"', false);
    }

    public function test_a_dark_cookie_is_rendered_onto_the_html_tag(): void
    {
        $response = $this->withUnencryptedCookie('theme', 'dark')->get(route('browse'));

        // The class must be in the markup, not applied by a script afterwards:
        // that gap is the flash, and the disagreement is the flip on refresh.
        $response->assertSee('class="h-full dark"', false);
        $response->assertSee('data-theme="dark"', false);
    }

    public function test_a_light_cookie_renders_no_dark_class(): void
    {
        $response = $this->withUnencryptedCookie('theme', 'light')->get(route('browse'));

        $response->assertSee('data-theme="light"', false);
        $response->assertDontSee('class="h-full dark"', false);
    }

    /**
     * The cookie is written by JavaScript and travels from the client, so it is
     * user input. It reaches an HTML attribute, and only these three values may
     * ever get there.
     */
    public function test_an_unrecognised_cookie_value_falls_back_to_system(): void
    {
        $response = $this->withUnencryptedCookie('theme', '" onload="alert(1)')
            ->get(route('browse'));

        $response->assertSee('data-theme="system"', false);
        $response->assertDontSee('onload=', false);
    }
}
