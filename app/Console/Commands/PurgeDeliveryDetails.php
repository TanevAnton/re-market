<?php

namespace App\Console\Commands;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Services\Deals\DeliveryService;
use Illuminate\Console\Command;

/**
 * Erase delivery details from deals that are long finished.
 *
 * THE RETENTION RULE IS THE FEATURE. A marketplace that collects a name, a phone
 * number and a home address for every parcel and then keeps them forever has
 * built a database whose only remaining purpose is to be stolen. Thirty days is
 * long enough to reprint a label or argue with a courier and short enough that the
 * table stops being a map of where everybody who bought a graphics card lives.
 *
 * WHAT SURVIVES: the courier, the inspect-and-test flag and the tracking number —
 * facts about the transaction, and the tracking number in particular is the only
 * evidence either side has that a parcel existed. WHAT GOES: the name, the phone,
 * the address, the city and the note — facts about a person.
 *
 * Open deals are never touched, however old. A deal that has been sitting open for
 * two months is a problem, but it is `remarket:lapse-deals`'s problem, and wiping
 * the address of a parcel somebody may still be about to send would turn a stalled
 * deal into an impossible one.
 */
class PurgeDeliveryDetails extends Command
{
    protected $signature = 'remarket:purge-delivery-details
                            {--days= : Override the retention window from config}
                            {--dry-run : List what would be erased and change nothing}';

    protected $description = 'Erase buyer delivery details from long-finished deals';

    public function handle(DeliveryService $delivery): int
    {
        $days = (int) ($this->option('days') ?: config('remarket.delivery.retention_days'));

        if ($days < 1) {
            $this->error('Retention window must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        /*
         * „Finished" is a closed status, not a timestamp, because the four closed
         * statuses stamp four different columns — completed_at, cancelled_at, and
         * abandoned/disputed which stamp neither. `updated_at` is the one column
         * every one of them moves, and for a retention sweep „nothing has happened
         * to this row for thirty days" is the honest test anyway.
         */
        $query = Deal::query()
            ->whereNot('status', DealStatus::Open)
            ->whereNotNull('delivery_set_at')
            ->whereNull('delivery_purged_at')
            ->where('updated_at', '<', $cutoff);

        $total = $query->count();

        if ($total === 0) {
            $this->info("Nothing to purge (retention {$days} days).");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Would purge delivery details from {$total} deal(s) untouched since {$cutoff->toDateString()}.");

            return self::SUCCESS;
        }

        $purged = 0;

        /*
         * Chunked by id, and `chunkById` rather than `chunk` because the purge
         * writes to `updated_at` on every row it touches — the ordering a plain
         * `chunk` relies on shifts underneath it and rows get skipped. Row by row
         * rather than a bulk update so DeliveryService stays the only place that
         * knows which columns are the personal ones.
         */
        $query->chunkById(200, function ($deals) use ($delivery, &$purged) {
            foreach ($deals as $deal) {
                $delivery->purge($deal);
                $purged++;
            }
        });

        $this->info("Purged delivery details from {$purged} deal(s).");

        return self::SUCCESS;
    }
}
