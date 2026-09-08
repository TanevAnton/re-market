<?php

namespace Tests\Feature;

use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The published pages.
 *
 * Most of what these tests protect is not "does the page render" but "is the
 * thing the law requires actually on it, and reachable by the person who needs
 * it". A contact point behind a login is not published; a notice-and-action
 * mechanism a non-user cannot read about does not satisfy Art. 16.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = [
        'legal.terms', 'legal.privacy', 'legal.cookies', 'legal.contacts', 'legal.notice',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        config([
            'legal.entity.name'             => 'ПРИМЕР ООД',
            'legal.entity.eik'              => '123456789',
            'legal.entity.address'          => 'гр. Горна Оряховица, ул. Примерна 1',
            'legal.contact.users'           => 'support@example.bg',
            'legal.contact.authorities'     => 'legal@example.bg',
            'legal.contact.privacy'         => 'privacy@example.bg',
        ]);
    }

    /** Behind a login they would not be published at all. */
    public function test_every_legal_page_is_public(): void
    {
        foreach (self::PAGES as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_the_footer_links_to_them_from_every_page(): void
    {
        $this->get(route('browse'))
            ->assertSee(route('legal.terms'), false)
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('legal.contacts'), false);
    }

    /**
     * Art. 11 (authorities) and Art. 12 (users) are two separate obligations.
     * One address published once does not discharge both.
     */
    public function test_both_contact_points_are_published(): void
    {
        $this->get(route('legal.contacts'))
            ->assertSee('support@example.bg')
            ->assertSee('legal@example.bg')
            ->assertSee('български');
    }

    public function test_the_provider_is_identifiable(): void
    {
        $this->get(route('legal.contacts'))
            ->assertSee(config('legal.entity.name'))
            ->assertSee('123456789');
    }

    /**
     * A blank company number has to LOOK blank. An invented one would be a
     * false statement about a real registered company, and a silently empty
     * one would let the page go live looking finished.
     */
    public function test_a_missing_company_detail_is_visibly_missing(): void
    {
        config(['legal.entity.eik' => null]);

        $this->get(route('legal.contacts'))->assertSee('LEGAL_ENTITY_EIK');
    }

    /**
     * Art. 14: the moderation policy, the tools, whether automation decides,
     * and the complaint route. Stated, not implied.
     */
    public function test_the_terms_state_the_moderation_policy(): void
    {
        $response = $this->get(route('legal.terms'));

        $response->assertSee('Решенията се вземат от човек', false);
        $response->assertSee('мотивирано решение', false);
        $response->assertSee('6 месеца', false);
    }

    /**
     * Build plan §8.4. This wording is what keeps the platform inside the DAC7
     * classifieds carve-out and outside the marketplace regimes - it is load
     * bearing, not boilerplate.
     */
    public function test_the_terms_describe_offers_as_non_binding(): void
    {
        $response = $this->get(route('legal.terms'));

        $response->assertSee('необвързващо', false);
        $response->assertSee('не сключва договор', false);
        $response->assertSee('Не обработваме плащания', false);
    }

    public function test_the_privacy_notice_names_the_basis_for_the_phone_hash(): void
    {
        $response = $this->get(route('legal.privacy'));

        // Legitimate interest, not consent - consent is not freely given when
        // it is a condition of using the service.
        $response->assertSee('легитимен интерес', false);
        $response->assertSee('необратим хеш', false);
        $response->assertSee('КЗЛД', false);
    }

    /** Published, and generated from the same config the jobs should read. */
    public function test_the_privacy_notice_publishes_the_retention_schedule(): void
    {
        $response = $this->get(route('legal.privacy'));

        foreach (config('legal.retention') as $period) {
            $response->assertSee($period, false);
        }
    }

    public function test_the_cookie_page_lists_every_cookie_the_site_sets(): void
    {
        $response = $this->get(route('legal.cookies'));

        // The session cookie name is derived from APP_NAME, so the page reads
        // it from config rather than restating it - and so does this test.
        foreach ([config('session.cookie'), 'XSRF-TOKEN', 'theme'] as $cookie) {
            $response->assertSee($cookie, false);
        }
    }

    /**
     * A guest has to be able to read how to report before deciding to. The
     * seller whose photographs were stolen has no account here.
     */
    public function test_a_guest_can_read_how_to_report(): void
    {
        $this->get(route('legal.notice'))
            ->assertOk()
            ->assertSee('Не е нужен профил', false);
    }
}
