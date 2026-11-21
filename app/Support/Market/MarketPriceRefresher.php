<?php

namespace App\Support\Market;

use App\Models\Part;
use App\Models\PartMarketPrice;

/**
 * One pass over the graphics-card catalogue: ask OLX.bg about each chipset,
 * decide which ads are about which model, and save one band per model.
 *
 * ONE SEARCH PER CHIPSET, NOT PER MODEL. „RTX 3060 8GB" and „RTX 3060 12GB" are
 * answered by the same results, so asking twice would double the load for no
 * gain; the ads are sorted into models by the memory size in the title.
 *
 * IT FAILS LOUD AND EARLY. If the first search of a run parses to zero ads the
 * markup has probably changed, and carrying on would only record gaps - so the
 * run stops and says so, and `remarket:olx-probe` shows what the parser saw.
 */
class MarketPriceRefresher
{
    public const SOURCE = 'olx';

    public function __construct(private readonly OlxClient $client) {}

    /**
     * @return array{
     *     chipsets: int, requests: int, saved: list<string>, thin: array<string, int>,
     *     aborted: ?string
     * }
     */
    public function run(?string $only = null, bool $dryRun = false, ?int $pages = null, ?int $limit = null): array
    {
        $report = ['chipsets' => 0, 'requests' => 0, 'saved' => [], 'thin' => [], 'aborted' => null];

        $groups = $this->groups($only);

        if ($limit !== null) {
            $groups = array_slice($groups, 0, $limit, true);
        }

        $pages   ??= (int) config('market.olx.pages', 3);
        $min       = (int) config('market.min_sample', 8);
        $exclude   = (array) config('market.exclude', []);
        $first     = true;

        foreach ($groups as $chipset => $parts) {
            try {
                $cards = $this->collect($chipset, $pages);
            } catch (MarketBlocked $e) {
                $report['aborted'] = $e->getMessage();
                break;
            }

            $report['chipsets']++;

            if ($first && $cards === []) {
                $report['aborted'] = 'The first search parsed to zero ads - OLX.bg\'s markup may have changed. Run remarket:olx-probe.';
                break;
            }
            $first = false;

            foreach ($parts as $part) {
                $vram      = isset($part->specs['vram_gb']) ? (int) $part->specs['vram_gb'] : null;
                $ambiguous = count($parts) > 1;

                $prices = [];
                foreach ($cards as $card) {
                    if ($card['cents'] === null) {
                        continue;
                    }

                    if (TitleMatcher::matches($chipset, $vram, $card['title'], $ambiguous, $exclude)) {
                        $prices[] = $card['cents'];
                    }
                }

                $stats = PriceStats::summarise($prices, $min);

                if ($stats === null) {
                    $report['thin'][$part->fullName()] = count($prices);

                    continue;
                }

                if (! $dryRun) {
                    PartMarketPrice::updateOrCreate(
                        ['part_id' => $part->id, 'source' => self::SOURCE],
                        [
                            'p25_cents'    => $stats['p25'],
                            'median_cents' => $stats['median'],
                            'p75_cents'    => $stats['p75'],
                            'sample_size'  => $stats['sample'],
                            'captured_at'  => now(),
                        ],
                    );
                }

                $report['saved'][] = $part->fullName().' — '.number_format($stats['median'] / 100, 0, ',', ' ')
                    .' € ('.$stats['sample'].' обяви)';
            }
        }

        $report['requests'] = $this->client->requests();

        return $report;
    }

    /**
     * Graphics cards grouped by chipset, in a stable order.
     *
     * @return array<string, list<Part>>
     */
    private function groups(?string $only): array
    {
        $groups = [];

        foreach (Part::where('category', 'gpu')->orderBy('model')->get() as $part) {
            $chipset = trim((string) ($part->specs['chipset'] ?? ''));

            if ($chipset === '') {
                continue;
            }

            if ($only !== null && ! str_contains(mb_strtolower($chipset), mb_strtolower($only))) {
                continue;
            }

            $groups[$chipset][] = $part;
        }

        ksort($groups);

        return $groups;
    }

    /**
     * Result pages for one chipset, de-duplicated, stopping at the first page
     * that has nothing on it.
     *
     * @return list<array{title: string, cents: ?int, key: string}>
     * @throws MarketBlocked
     */
    private function collect(string $chipset, int $pages): array
    {
        $seen = [];

        for ($page = 1; $page <= max(1, $pages); $page++) {
            $parsed = OlxParser::parse($this->client->search($chipset, $page));

            if ($parsed['cards'] === []) {
                break;
            }

            foreach ($parsed['cards'] as $card) {
                $seen[$card['key']] = $card;
            }
        }

        return array_values($seen);
    }
}
