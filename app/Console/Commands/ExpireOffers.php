<?php

namespace App\Console\Commands;

use App\Services\Offers\OfferService;
use Illuminate\Console\Command;

/**
 * Offers have a TTL so a seller who never logs in cannot leave a buyer's money
 * notionally committed forever. Without this sweep the TTL is decoration.
 *
 * OfferService also treats an over-TTL offer as dead at the moment someone
 * tries to act on it, so a late sweep is a tidiness problem, not a correctness
 * one.
 */
class ExpireOffers extends Command
{
    protected $signature = 'remarket:expire-offers';

    protected $description = 'Mark pending offers past their TTL as expired';

    public function handle(OfferService $offers): int
    {
        $n = $offers->expireStale();

        $this->info($n === 0 ? 'Nothing to expire.' : "Expired {$n} offer(s).");

        return self::SUCCESS;
    }
}
