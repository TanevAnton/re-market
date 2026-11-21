<?php

namespace App\Console\Commands;

use App\Support\Market\MarketPriceRefresher;
use Illuminate\Console\Command;

/**
 * Weekly: refresh the outside (OLX.bg) price reference for graphics cards.
 *
 *   php artisan remarket:refresh-market-prices --dry-run --only="rtx 3060" --pages=1
 *
 * Does nothing at all unless MARKET_PRICES_ENABLED is true. See config/market.php
 * for what it fetches, what it keeps and how it behaves.
 */
class RefreshMarketPrices extends Command
{
    protected $signature = 'remarket:refresh-market-prices
                            {--only= : Only chipsets containing this text, e.g. "rtx 3060"}
                            {--pages= : Result pages per chipset (default from config)}
                            {--limit= : Stop after this many chipsets}
                            {--dry-run : Report only, save nothing}';

    protected $description = 'Refresh the OLX.bg asking-price reference for graphics cards (off unless enabled)';

    public function handle(MarketPriceRefresher $refresher): int
    {
        if (! config('market.enabled')) {
            $this->warn('Outside prices are switched off (MARKET_PRICES_ENABLED). Nothing was contacted.');

            return self::SUCCESS;
        }

        $dry    = (bool) $this->option('dry-run');
        $report = $refresher->run(
            $this->option('only') ?: null,
            $dry,
            $this->option('pages') !== null ? (int) $this->option('pages') : null,
            $this->option('limit') !== null ? (int) $this->option('limit') : null,
        );

        $this->info(($dry ? 'DRY RUN - nothing saved. ' : '')
            .count($report['saved']).' models with a figure, '
            .count($report['thin']).' without enough ads, '
            .$report['chipsets'].' chipsets, '.$report['requests'].' requests.');

        foreach ($report['saved'] as $line) {
            $this->line("  + {$line}");
        }

        if ($report['thin'] !== []) {
            $this->newLine();
            $this->warn('Too few matching ads (a model with no figure shows no outside guide):');
            foreach ($report['thin'] as $name => $n) {
                $this->line("  - {$name}: {$n}");
            }
        }

        if ($report['aborted']) {
            $this->newLine();
            $this->error('Stopped: '.$report['aborted']);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
