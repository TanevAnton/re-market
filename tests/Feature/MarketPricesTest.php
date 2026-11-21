<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartMarketPrice;
use App\Support\Market\MarketPriceRefresher;
use App\Support\Market\OlxParser;
use App\Support\Market\PriceStats;
use App\Support\Market\RobotsGuard;
use App\Support\Market\TitleMatcher;
use App\Support\PriceGuidance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The outside price reference: what a model is asked for on OLX.bg, used by the
 * seller's price step when RIGO has too few listings of its own.
 *
 * Everything that touches the network is faked - the suite never contacts
 * another site. The page markup in these tests is a SYNTHETIC stand-in for
 * OLX.bg's, written to exercise the parser's logic; whether the real markup
 * parses is what `remarket:olx-probe` is for, run once against the live site.
 */
class MarketPricesTest extends TestCase
{
    use RefreshDatabase;

    private const ROBOTS = "User-agent: *\nDisallow: /api/\nDisallow: /*?q=\nAllow: /\n";

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'market.enabled'   => true,
            'market.delay_ms'  => [0, 0],
            'market.min_sample' => 8,
        ]);
    }

    // --- builders ----------------------------------------------------------

    private function card(string $title, string $price, string $id): string
    {
        return '<div data-cy="l-card"><a href="/d/obyava/'.$id.'.html"><h4>'.$title.'</h4></a>'
            .'<p data-testid="ad-price">'.$price.'</p></div>';
    }

    private function page(array $cards): string
    {
        return '<html><body>'.implode('', $cards).'</body></html>';
    }

    private function gpu(string $model, int $vram, string $chipset = 'RTX 3060'): Part
    {
        return Part::create([
            'category'     => 'gpu',
            'manufacturer' => 'NVIDIA',
            'model'        => $model,
            'slug'         => str($model)->slug()->toString(),
            'specs'        => ['chipset' => $chipset, 'vram_gb' => $vram],
            'aliases'      => [],
            'is_published' => true,
        ]);
    }

    /** One search page with a realistic mix, and an empty page 2. */
    private function fakeOlx(?string $page1 = null, int $status = 200): void
    {
        $cards = [];
        foreach (range(0, 9) as $i) {
            $cards[] = $this->card('Asus RTX 3060 12GB', (300 + $i * 5).' €', "g12-{$i}");
        }
        $cards[] = $this->card('RTX 3060 12GB чисто нова гаранция', '5 000 €', 'outlier-high');
        $cards[] = $this->card('RTX 3060 12GB', '1 €', 'outlier-low');
        foreach (range(0, 2) as $i) {
            $cards[] = $this->card('Gigabyte RTX 3060 8GB', (240 + $i * 5).' €', "g8-{$i}");
        }
        $cards[] = $this->card('Лаптоп Lenovo с RTX 3060 12GB', '900 €', 'laptop');
        $cards[] = $this->card('RTX 3060 Ti 8GB', '420 €', 'ti');
        $cards[] = $this->card('RTX 3060 Gigabyte', '310 €', 'no-size');
        $cards[] = $this->card('i5-12400 + RTX 3060 12GB', '700 €', 'bundle');
        $cards[] = $this->card('RTX 3060 12GB', 'По договаряне', 'negotiable');

        Http::fake([
            'www.olx.bg/robots.txt' => Http::response(self::ROBOTS, 200),
            'www.olx.bg/ads/*page=2*' => Http::response('<html><body></body></html>', 200),
            'www.olx.bg/ads/*' => Http::response($page1 ?? $this->page($cards), $status),
        ]);
    }

    // --- the figure ----------------------------------------------------------

    public function test_a_run_saves_one_band_per_model_from_the_right_ads_only(): void
    {
        $twelve = $this->gpu('GeForce RTX 3060 12GB', 12);
        $eight  = $this->gpu('GeForce RTX 3060 8GB', 8);
        $this->fakeOlx();

        $report = app(MarketPriceRefresher::class)->run();

        $this->assertNull($report['aborted']);

        // 10 real ads; the two outliers, the laptop, the Ti, the bundle, the
        // sizeless one and the negotiable one are all out.
        $row = PartMarketPrice::where('part_id', $twelve->id)->firstOrFail();
        $this->assertSame('olx', $row->source);
        $this->assertSame(10, $row->sample_size);
        $this->assertSame(32250, $row->median_cents);
        $this->assertGreaterThan($row->p25_cents, $row->p75_cents);

        // Three 8GB ads is below the minimum: no figure, and no row.
        $this->assertDatabaseMissing('part_market_prices', ['part_id' => $eight->id]);
        $this->assertSame(3, $report['thin']['NVIDIA GeForce RTX 3060 8GB'] ?? null);
    }

    public function test_only_aggregates_are_stored(): void
    {
        $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->gpu('GeForce RTX 3060 8GB', 8);
        $this->fakeOlx();

        app(MarketPriceRefresher::class)->run();

        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('part_market_prices');

        foreach (['title', 'url', 'seller', 'description', 'location', 'image'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->gpu('GeForce RTX 3060 8GB', 8);
        $this->fakeOlx();

        $report = app(MarketPriceRefresher::class)->run(dryRun: true);

        $this->assertCount(1, $report['saved']);
        $this->assertDatabaseCount('part_market_prices', 0);
    }

    public function test_running_again_updates_the_row_instead_of_adding_one(): void
    {
        $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->gpu('GeForce RTX 3060 8GB', 8);
        $this->fakeOlx();

        app(MarketPriceRefresher::class)->run();
        app(MarketPriceRefresher::class)->run();

        $this->assertDatabaseCount('part_market_prices', 1);
    }

    public function test_it_identifies_itself_reads_robots_once_and_stops_at_an_empty_page(): void
    {
        $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->gpu('GeForce RTX 3060 8GB', 8);
        $this->fakeOlx();

        app(MarketPriceRefresher::class)->run();

        // robots.txt, page 1, page 2 (empty, so no page 3).
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => str_contains((string) $r->header('User-Agent')[0], 'RIGO-pricebot'));
    }

    // --- refusing, stopping, failing loud -----------------------------------

    public function test_a_403_ends_the_run_without_retrying(): void
    {
        $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->fakeOlx(status: 403);

        $report = app(MarketPriceRefresher::class)->run();

        $this->assertStringContainsString('403', (string) $report['aborted']);
        $this->assertDatabaseCount('part_market_prices', 0);
        Http::assertSentCount(2);   // robots.txt and the one refused page
    }

    public function test_a_challenge_page_is_treated_as_a_refusal(): void
    {
        $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->fakeOlx('<html><title>Just a moment...</title></html>');

        $report = app(MarketPriceRefresher::class)->run();

        $this->assertStringContainsString('challenge', (string) $report['aborted']);
    }

    public function test_a_path_disallowed_by_robots_txt_is_never_requested(): void
    {
        $this->gpu('GeForce RTX 3060 12GB', 12);

        Http::fake([
            'www.olx.bg/robots.txt' => Http::response("User-agent: *\nDisallow: /ads/\n", 200),
            'www.olx.bg/ads/*'      => Http::response('should never be fetched', 200),
        ]);

        $report = app(MarketPriceRefresher::class)->run();

        $this->assertStringContainsString('robots.txt', (string) $report['aborted']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/ads/'));
    }

    public function test_an_unreadable_robots_txt_stops_the_run(): void
    {
        $this->gpu('GeForce RTX 3060 12GB', 12);

        Http::fake(['www.olx.bg/robots.txt' => Http::response('', 503)]);

        $report = app(MarketPriceRefresher::class)->run();

        $this->assertStringContainsString('robots.txt', (string) $report['aborted']);
    }

    public function test_a_page_that_parses_to_nothing_stops_the_run_and_says_why(): void
    {
        $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->fakeOlx('<html><body><p>something else entirely</p></body></html>');

        $report = app(MarketPriceRefresher::class)->run();

        $this->assertStringContainsString('olx-probe', (string) $report['aborted']);
    }

    public function test_the_url_template_can_never_produce_a_query_string_search(): void
    {
        $this->gpu('GeForce RTX 3060 12GB', 12);
        config(['market.olx.path' => '/ads/?q={q}']);

        Http::fake([
            'www.olx.bg/robots.txt' => Http::response(self::ROBOTS, 200),
            'www.olx.bg/*'          => Http::response('x', 200),
        ]);

        $report = app(MarketPriceRefresher::class)->run();

        $this->assertStringContainsString('robots.txt', (string) $report['aborted']);
    }

    public function test_the_command_does_nothing_unless_it_is_switched_on(): void
    {
        config(['market.enabled' => false]);
        Http::fake();

        $this->artisan('remarket:refresh-market-prices')
            ->expectsOutputToContain('switched off')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_command_reports_and_fails_when_it_is_stopped(): void
    {
        $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->fakeOlx(status: 429);

        $this->artisan('remarket:refresh-market-prices')->assertFailed();
    }

    public function test_the_command_runs_end_to_end(): void
    {
        $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->gpu('GeForce RTX 3060 8GB', 8);
        $this->fakeOlx();

        $this->artisan('remarket:refresh-market-prices', ['--only' => 'rtx 3060'])->assertSuccessful();

        $this->assertDatabaseCount('part_market_prices', 1);
    }

    public function test_the_probe_shows_what_the_parser_read_and_saves_the_page(): void
    {
        $this->fakeOlx();
        \Illuminate\Support\Facades\Storage::fake('local');

        $this->artisan('remarket:olx-probe', ['query' => 'rtx 3060'])
            ->expectsOutputToContain('Strategy: cards')
            ->assertSuccessful();

        \Illuminate\Support\Facades\Storage::disk('local')->assertExists('olx-probe.html');
    }

    public function test_the_probe_fails_loudly_on_a_page_it_cannot_read(): void
    {
        $this->fakeOlx('<html><body>no ads here</body></html>');
        \Illuminate\Support\Facades\Storage::fake('local');

        $this->artisan('remarket:olx-probe')->expectsOutputToContain('Nothing parsed')->assertFailed();
    }

    // --- the guide ---------------------------------------------------------

    private function withMarketRow(Part $part, array $over = []): PartMarketPrice
    {
        return PartMarketPrice::create(array_merge([
            'part_id' => $part->id, 'source' => 'olx',
            'p25_cents' => 300_00, 'median_cents' => 340_00, 'p75_cents' => 380_00,
            'sample_size' => 12, 'captured_at' => now(),
        ], $over));
    }

    public function test_without_a_band_of_its_own_the_guide_falls_back_to_the_outside_figure(): void
    {
        $part = $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->withMarketRow($part);

        $guidance = PriceGuidance::for($part, 450_00);

        $this->assertSame('olx', $guidance['source']);
        $this->assertSame(12, $guidance['sample']);
        $this->assertSame(340_00, $guidance['suggestion']);
        $this->assertSame('high', $guidance['standing']['position']);
        $this->assertNull($guidance['trend']);
        $this->assertStringContainsString('OLX', PriceGuidance::note($guidance));
    }

    public function test_the_sites_own_band_wins_when_there_is_one(): void
    {
        $part = $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->withMarketRow($part);
        $part->forceFill([
            'price_p25_cents' => 200_00, 'price_median_cents' => 220_00,
            'price_p75_cents' => 240_00, 'price_stats_at' => now(),
        ])->save();

        $guidance = PriceGuidance::for($part->fresh(), 230_00);

        $this->assertSame('own', $guidance['source']);
        $this->assertSame(220_00, $guidance['suggestion']);
    }

    public function test_a_stale_or_thin_outside_figure_is_not_shown(): void
    {
        $stale = $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->withMarketRow($stale, ['captured_at' => now()->subDays(40)]);

        $thin = $this->gpu('GeForce RTX 3060 8GB', 8);
        $this->withMarketRow($thin, ['sample_size' => 3]);

        $this->assertNull(PriceGuidance::for($stale));
        $this->assertNull(PriceGuidance::for($thin));
    }

    /** The public badge and the model page rest on the site's own listings, never on this. */
    public function test_the_outside_figure_never_feeds_the_public_band_or_badge(): void
    {
        $part = $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->withMarketRow($part);

        $this->assertNull($part->priceBand());
        $this->assertNull($part->priceStanding(450_00));
    }

    public function test_the_price_step_says_where_the_figure_comes_from(): void
    {
        $part = $this->gpu('GeForce RTX 3060 12GB', 12);
        $this->withMarketRow($part);

        $html = view('partials.price-guidance', ['guidance' => PriceGuidance::for($part)])->render();

        $this->assertStringContainsString('По обяви в OLX.bg', $html);
        $this->assertStringContainsString('12 обяви', $html);
        $this->assertStringContainsString('ПРЕДЛАГА', $html);
    }

    // --- the pieces ----------------------------------------------------------

    public function test_prices_are_read_in_euro_cents(): void
    {
        $this->assertSame(78200, OlxParser::parsePrice('782 €'));
        $this->assertSame(125000, OlxParser::parsePrice("1\u{00A0}250 €"));
        $this->assertSame(24990, OlxParser::parsePrice('249,90 €'));
        $this->assertSame(76694, OlxParser::parsePrice('1 500 лв.'));
        $this->assertSame(78200, OlxParser::parsePrice('782 €Договаряне'));
        $this->assertNull(OlxParser::parsePrice('По договаряне'));
        $this->assertNull(OlxParser::parsePrice('0 €'));
    }

    public function test_the_parser_reports_which_strategy_worked(): void
    {
        $this->assertSame('cards', OlxParser::parse($this->page([$this->card('RX 580', '90 €', 'a')]))['strategy']);
        $this->assertSame('none', OlxParser::parse('<html></html>')['strategy']);

        $ld = '<script type="application/ld+json">{"itemListElement":[{"item":{"name":"RX 580","url":"https://x/1",'
            .'"offers":{"price":"90","priceCurrency":"EUR"}}}]}</script>';
        $parsed = OlxParser::parse($ld);
        $this->assertSame('json-ld', $parsed['strategy']);
        $this->assertSame(9000, $parsed['cards'][0]['cents']);
    }

    public function test_robots_rules_are_read_the_way_crawlers_read_them(): void
    {
        $guard = new RobotsGuard(self::ROBOTS);

        $this->assertTrue($guard->allows('/ads/q-rtx-3060/'));
        $this->assertTrue($guard->allows('/ads/q-rtx-3060/?page=2'));
        $this->assertFalse($guard->allows('/ads/?q=rtx'));
        $this->assertFalse($guard->allows('/api/v1/offers/'));
    }

    public function test_the_matcher_tells_one_card_from_its_neighbours(): void
    {
        $exclude = (array) config('market.exclude');
        $m = fn ($chip, $vram, $title, $amb = false) => TitleMatcher::matches($chip, $vram, $title, $amb, $exclude);

        $this->assertTrue($m('RTX 3060', 12, 'Asus RTX 3060 12GB'));
        $this->assertTrue($m('RTX 3060', null, 'Видео карта ртх 3060'));
        $this->assertFalse($m('RTX 3060', 12, 'RTX 3060 Ti 8GB'));
        $this->assertFalse($m('RTX 3060', null, 'rtx3060ti'));
        $this->assertTrue($m('RTX 3060 Ti', 8, 'RTX3060Ti'));
        $this->assertFalse($m('RTX 3060', 12, 'RTX 3060 8GB'));
        $this->assertFalse($m('RX 580', 8, 'RX 5800'));
        $this->assertFalse($m('RTX 3060', null, 'Лаптоп с RTX 3060'));
        $this->assertFalse($m('RTX 3060', null, 'i5-12400 + RTX 3060'));
        $this->assertFalse($m('RTX 3060', 12, 'RTX 3060 Gigabyte', true));
    }

    public function test_the_stats_trim_the_extremes_and_refuse_a_thin_sample(): void
    {
        $stats = PriceStats::summarise([30000, 31000, 32000, 33000, 34000, 35000, 36000, 37000, 5000, 200000], 8);

        $this->assertSame(8, $stats['sample']);
        $this->assertSame(33500, $stats['median']);
        $this->assertNull(PriceStats::summarise([100, 200], 8));
    }
}
