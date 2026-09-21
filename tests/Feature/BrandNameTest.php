<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\User;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The site name lives in APP_NAME and nowhere else.
 *
 * WHY THIS EXISTS. The site was called RE-MARKET until 20 Sep 2026 and is now
 * RIGO. Almost everything read `config('app.name')` and changed by itself —
 * but `ShowProfile` had „RE-MARKET" typed into its meta description, and that
 * is the worst possible place for a stale brand: nothing errors, no page looks
 * broken, no existing test fails, and the only people who ever see it are
 * Google and whoever shares a profile link. It could have stayed wrong for a
 * year.
 *
 * So this test renames the site to a string that cannot occur by accident and
 * checks the name actually moved. It is deliberately written against the
 * PROPERTY — „the rendered page uses whatever APP_NAME says" — rather than
 * against the literal „RIGO", because a test asserting the current name would
 * have to be edited by the same person making the same mistake next time.
 *
 * The session cookie name is also derived from APP_NAME, but that is resolved
 * once at boot and cannot be re-read mid-request, so it is not asserted here.
 * It is covered by LegalPagesTest, which reads `config('session.cookie')` the
 * same way the cookie page does.
 */
class BrandNameTest extends TestCase
{
    use RefreshDatabase;

    /** Not a word, not a substring of anything, impossible to type by accident. */
    private const BRAND = 'ZZQXBRANDPROBE';

    /** Every name the site has ever had. None may survive a rename. */
    private const OLD_NAMES = ['RE-MARKET', 'REMARKET', 'RIGO'];

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);

        $this->seller = User::factory()->create([
            'username'          => 'prodavach',
            'email_verified_at' => now(),
            'city_id'           => City::first()->id,
        ]);

        config(['app.name' => self::BRAND]);
    }

    public function test_the_home_page_wears_the_configured_name(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $response->assertSee(self::BRAND, false);
        $response->assertSee('property="og:site_name" content="'.self::BRAND.'"', false);

        foreach (self::OLD_NAMES as $old) {
            $response->assertDontSee($old, false);
        }
    }

    /**
     * The one that was actually broken.
     *
     * A meta description is invisible on the page itself, which is exactly why
     * a hardcoded brand can live in one indefinitely.
     */
    public function test_the_profile_meta_description_wears_the_configured_name(): void
    {
        $response = $this->get(route('profile', $this->seller->username))->assertOk();

        $response->assertSee('name="description"', false);
        $response->assertSee(self::BRAND, false);

        foreach (self::OLD_NAMES as $old) {
            $response->assertDontSee($old, false);
        }
    }

    /**
     * The title is „<page> · <site>", so a page with its own title still has to
     * carry the site name — that suffix is the layout's job and the reason no
     * component should put the site name in its own title.
     */
    public function test_an_inner_page_title_still_carries_the_site_name(): void
    {
        $this->get(route('browse'))
            ->assertOk()
            ->assertSee('· '.self::BRAND.'</title>', false);
    }

    /** Header and footer both print it, and both come from the same place. */
    public function test_the_header_and_footer_wear_the_configured_name(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(2, substr_count($html, self::BRAND),
            'the name should appear at least in the header and the footer');
    }

    /**
     * And the legal pages, which name the site in text a lawyer will read.
     *
     * The registered COMPANY is a separate thing and does not move with the
     * brand — КОМПНЕТ СОЛЮШЪНС ООД is in config/legal.php and is what the DSA
     * and consumer-law pages have to name. This only checks that wherever the
     * site's own name appears, it is the configured one.
     */
    public function test_the_legal_pages_carry_no_stale_brand(): void
    {
        foreach (['legal.terms', 'legal.privacy', 'legal.cookies', 'legal.contacts'] as $page) {
            $response = $this->get(route($page))->assertOk();

            foreach (self::OLD_NAMES as $old) {
                $response->assertDontSee($old, false);
            }
        }
    }
}
