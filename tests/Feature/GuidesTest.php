<?php

namespace Tests\Feature;

use App\Support\Guides;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The buyer's guides: four public pages from config/guides.php.
 *
 * They exist to rank and to be pasted into groups, so what is tested is what a
 * crawler and a person following a pasted link would meet - the status, the
 * title and description, the canonical, the structured data, the sitemap - and
 * that the text cannot promise what the site refuses to promise.
 */
class GuidesTest extends TestCase
{
    use RefreshDatabase;

    private const SLUGS = [
        'proverka-na-videokarta',
        'mining-videokarta',
        'testvane-pri-poluchavane',
        'nalozhen-platej-econt-speedy',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['remarket.seo.indexable' => true]);
    }

    // --- the pages ---------------------------------------------------------

    public function test_the_four_guides_exist(): void
    {
        $this->assertSame(self::SLUGS, array_keys(Guides::all()));
    }

    public function test_the_index_lists_every_guide_with_a_link(): void
    {
        $response = $this->get(route('guides'))->assertOk()->assertSee('Ръководства за купувача');

        foreach (Guides::all() as $slug => $guide) {
            $response->assertSee($guide['title'])
                ->assertSee(route('guide', $slug), false);
        }
    }

    public function test_every_guide_renders_with_its_title_and_its_lead(): void
    {
        foreach (Guides::all() as $slug => $guide) {
            $response = $this->get(route('guide', $slug))->assertOk();

            $response->assertSee($guide['title']);
            $response->assertSee('<h1', false);
            $this->assertGreaterThanOrEqual(3, count($guide['sections']), "{$slug} is too thin to rank");
        }
    }

    public function test_every_guide_asks_to_be_indexed_with_its_own_canonical_and_description(): void
    {
        foreach (Guides::all() as $slug => $guide) {
            $html = $this->get(route('guide', $slug))->getContent();

            $this->assertStringContainsString('<link rel="canonical" href="'.route('guide', $slug).'">', $html);
            $this->assertStringContainsString('<meta name="description"', $html);
            $this->assertStringContainsString('<meta property="og:type" content="article">', $html);
            $this->assertStringNotContainsString('noindex', $html);

            // Google cuts the description around 160 characters; the layout
            // truncates at 155, so a longer one would be chopped mid-sentence.
            $this->assertLessThanOrEqual(155, mb_strlen($guide['description']), "{$slug} description");
        }
    }

    public function test_a_copy_of_the_site_that_is_not_live_stays_out_of_the_index(): void
    {
        config(['remarket.seo.indexable' => false]);

        $this->get(route('guide', self::SLUGS[0]))
            ->assertOk()
            ->assertSee('noindex', false);
    }

    public function test_the_structured_data_is_a_valid_article_with_a_breadcrumb(): void
    {
        foreach (Guides::all() as $slug => $guide) {
            $html = $this->get(route('guide', $slug))->getContent();

            $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $m), "{$slug}: no JSON-LD");

            $data = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
            $types = array_column($data['@graph'], '@type');

            $this->assertContains('Article', $types);
            $this->assertContains('BreadcrumbList', $types);
            $this->assertNotContains('FAQPage', $types);
            $this->assertNotContains('HowTo', $types);
            $this->assertSame($guide['title'], $data['@graph'][0]['headline']);
        }
    }

    public function test_an_unknown_guide_is_a_404_and_so_is_a_slug_the_route_does_not_allow(): void
    {
        $this->get('/rakovodstva/no-such-guide')->assertNotFound();
        $this->get('/rakovodstva/Proverka-Na-Videokarta')->assertNotFound();
    }

    // --- the way in --------------------------------------------------------

    public function test_the_footer_links_to_the_guides_from_every_page(): void
    {
        $this->get(route('home'))->assertSee(route('guides'), false)->assertSee('Ръководства за купувача');
    }

    public function test_the_sitemap_lists_the_index_and_every_guide(): void
    {
        $xml = $this->get(route('sitemap'))->assertOk()->getContent();

        $this->assertStringContainsString('<loc>'.route('guides').'</loc>', $xml);

        foreach (array_keys(Guides::all()) as $slug) {
            $this->assertStringContainsString('<loc>'.route('guide', $slug).'</loc>', $xml);
        }
    }

    // --- the text ----------------------------------------------------------

    /** Walks every string in the guides, however deeply nested. */
    private function allText(): string
    {
        $out    = [];
        $guides = config('guides');

        array_walk_recursive($guides, function ($v) use (&$out) {
            if (is_string($v)) {
                $out[] = $v;
            }
        });

        return implode("\n", $out);
    }

    /**
     * The site refuses to say a deal is safe, guaranteed or verified, and the
     * marketing rule is that nothing around it may say it for the site either.
     */
    public function test_no_guide_promises_what_the_site_refuses_to_promise(): void
    {
        $text = mb_strtolower($this->allText());

        foreach (['гарантирано', 'гарантираме', 'безопасна сделка', 'безопасно', '100% сигурн', 'проверено от'] as $claim) {
            $this->assertStringNotContainsString($claim, $text, "A guide says „{$claim}“.");
        }
    }

    public function test_every_link_written_in_a_guide_points_somewhere_that_exists(): void
    {
        preg_match_all('/\[\[([a-z0-9._:-]+)\|/', $this->allText(), $m);

        $this->assertNotEmpty($m[1]);

        foreach (array_unique($m[1]) as $target) {
            if (str_starts_with($target, 'guide:')) {
                $this->assertNotNull(Guides::find(substr($target, 6)), "Unknown guide {$target}");
            } else {
                $this->assertTrue(Route::has($target), "Unknown route {$target}");
            }
        }
    }

    public function test_related_guides_and_call_to_action_links_exist(): void
    {
        foreach (Guides::all() as $slug => $guide) {
            foreach ($guide['related'] as $related) {
                $this->assertNotNull(Guides::find($related), "{$slug} relates to a missing guide");
                $this->assertNotSame($slug, $related, "{$slug} relates to itself");
            }

            foreach ($guide['cta']['links'] as [$route]) {
                $this->assertTrue(Route::has($route), "{$slug}: unknown route {$route}");
            }
        }
    }

    public function test_the_links_on_a_rendered_guide_do_not_lead_to_errors(): void
    {
        foreach (array_keys(Guides::all()) as $slug) {
            $html = $this->get(route('guide', $slug))->getContent();

            preg_match_all('#<a href="('.preg_quote(url('/'), '#').'[^"\#?]*)"#', $html, $m);

            foreach (array_unique($m[1]) as $href) {
                $this->assertLessThan(400, $this->get($href)->status(), "{$slug} links to {$href}");
            }
        }
    }

    // --- the markup helper -------------------------------------------------

    public function test_markup_is_escaped_before_anything_is_added(): void
    {
        $html = Guides::html('<script>alert(1)</script> **важно** и `код`');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<strong>важно</strong>', $html);
        $this->assertStringContainsString('<code>код</code>', $html);
    }

    public function test_a_link_to_a_route_that_does_not_exist_degrades_to_its_text(): void
    {
        $html = Guides::html('виж [[no.such.route|тук]]');

        $this->assertSame('виж тук', $html);
    }

    public function test_a_link_to_a_guide_and_to_a_route_become_anchors(): void
    {
        $html = Guides::html('[[guide:proverka-na-videokarta|картата]] и [[browse|обявите]]');

        $this->assertStringContainsString('<a href="'.route('guide', 'proverka-na-videokarta').'" wire:navigate>картата</a>', $html);
        $this->assertStringContainsString('<a href="'.route('browse').'" wire:navigate>обявите</a>', $html);
    }

    public function test_the_date_is_written_in_bulgarian_without_depending_on_the_locale(): void
    {
        $this->assertSame('21 ноември 2026', Guides::dateBg('2026-11-21'));
    }
}
