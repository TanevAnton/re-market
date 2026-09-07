<?php

namespace App\Console\Commands;

use App\Services\Deals\DealService;
use Illuminate\Console\Command;

/**
 * Closes deals whose reservation window ran out, and puts the listing back on
 * the market so a buyer who vanished does not take the seller's item with them.
 *
 * This command is what gives the completion rate on a profile any meaning. If
 * it never runs, "accepted then ghosted" stays invisible forever and the
 * reputation model quietly stops working.
 */
class LapseDeals extends Command
{
    protected $signature = 'remarket:lapse-deals';

    protected $description = 'Mark open deals past their window as abandoned and relist the item';

    public function handle(DealService $deals): int
    {
        $n = $deals->lapseStale();

        $this->info($n === 0 ? 'Nothing to lapse.' : "Abandoned {$n} deal(s).");

        return self::SUCCESS;
    }
}
