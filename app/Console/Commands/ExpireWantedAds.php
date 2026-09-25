<?php

namespace App\Console\Commands;

use App\Services\Wanted\WantedService;
use Illuminate\Console\Command;

/**
 * Closes wanted ads nobody answered.
 *
 * `WantedAd::visible()` already checks the expiry date, so the site never
 * shows a stale request even if this never runs. That makes this housekeeping
 * rather than correctness — but a status column that disagrees with what the
 * site displays is how the next person writes a query against the wrong one,
 * and „how many requests went unanswered" is a number worth being able to ask
 * for without recomputing dates.
 */
class ExpireWantedAds extends Command
{
    protected $signature   = 'remarket:expire-wanted';
    protected $description = 'Close wanted ads whose window has passed';

    public function handle(WantedService $wanted): int
    {
        $closed = $wanted->expire();

        $this->info("[wanted] expired {$closed}");

        return self::SUCCESS;
    }
}
