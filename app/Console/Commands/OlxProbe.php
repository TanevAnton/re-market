<?php

namespace App\Console\Commands;

use App\Support\Market\MarketBlocked;
use App\Support\Market\OlxClient;
use App\Support\Market\OlxParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Fetch ONE OLX.bg search page and say what the parser made of it.
 *
 * The first thing to run, and the thing to run again when the weekly job stops
 * with „parsed to zero ads": it shows which strategy worked, how many ads and
 * prices it read, and saves the raw page so the parser can be adjusted against
 * what the site actually sends. Reads one page, respects robots.txt, saves
 * nothing but that file, and works whether or not MARKET_PRICES_ENABLED is on.
 */
class OlxProbe extends Command
{
    protected $signature = 'remarket:olx-probe {query=rtx 3060} {--page=1}';

    protected $description = 'Fetch one OLX.bg search page and show what the parser reads from it';

    public function handle(OlxClient $client): int
    {
        $query = (string) $this->argument('query');
        $page  = max(1, (int) $this->option('page'));
        $path  = $client->searchPath($query, $page);

        $this->line("GET {$path}");

        try {
            $html = $client->search($query, $page);
        } catch (MarketBlocked $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }

        Storage::disk('local')->put('olx-probe.html', $html);

        $parsed = OlxParser::parse($html);
        $priced = array_filter($parsed['cards'], fn ($c) => $c['cents'] !== null);

        $this->info(strlen($html).' bytes. Strategy: '.$parsed['strategy'].'. '
            .count($parsed['cards']).' ads, '.count($priced).' with a price.');

        foreach (array_slice($parsed['cards'], 0, 8) as $card) {
            $price = $card['cents'] === null ? '—' : number_format($card['cents'] / 100, 2, ',', ' ').' €';
            $this->line('  '.str_pad($price, 12).mb_strimwidth($card['title'], 0, 70, '…'));
        }

        if ($parsed['cards'] === []) {
            $this->newLine();
            $this->warn('Nothing parsed. The raw page was saved to storage/app/private/olx-probe.html - send it and the parser can be adjusted to it.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Raw page: storage/app/private/olx-probe.html');

        return self::SUCCESS;
    }
}
