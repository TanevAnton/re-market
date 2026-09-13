<?php

namespace App\Console\Commands;

use App\Models\ListingDraft;
use App\Services\Images\ImageProcessor;
use Illuminate\Console\Command;

/**
 * Sweep abandoned wizards, and the photos behind them.
 *
 * The wizard writes every uploaded photo to disk immediately, long before a
 * listing row exists. Before drafts, an abandoned wizard left those files
 * behind permanently: nothing referenced them, nothing could find them, and
 * the only symptom was a disk that grew and never shrank.
 *
 * Drafts gave those files an owner. This gives the owner a lifetime.
 */
class PruneListingDrafts extends Command
{
    protected $signature = 'remarket:prune-drafts
                            {--days= : Older than this many days (default from config)}
                            {--dry : Report what would go, delete nothing}';

    protected $description = 'Delete abandoned listing drafts and the photos they were holding';

    public function handle(ImageProcessor $images): int
    {
        $days = (int) ($this->option('days') ?: config('remarket.listings.draft_ttl_days', 30));

        $stale = ListingDraft::stale($days)->with('user:id,username')->get();

        if ($stale->isEmpty()) {
            $this->info("No drafts older than {$days} days.");

            return self::SUCCESS;
        }

        $photos = $stale->sum(fn (ListingDraft $d) => $d->photoCount());

        $this->table(
            ['seller', 'title', 'step', 'photos', 'last touched'],
            $stale->map(fn (ListingDraft $d) => [
                $d->user?->username ?? '?',
                mb_strimwidth((string) ($d->title ?: '—'), 0, 40, '…'),
                $d->step,
                $d->photoCount(),
                $d->updated_at->diffForHumans(),
            ]),
        );

        if ($this->option('dry')) {
            $this->line("  <fg=gray>--dry: {$stale->count()} draft(s) and {$photos} photo(s) would be deleted.</>");

            return self::SUCCESS;
        }

        foreach ($stale as $draft) {
            // discard(), not delete(): the files are the point.
            $draft->discard($images);
        }

        $this->info("Deleted {$stale->count()} draft(s) and {$photos} photo(s).");

        return self::SUCCESS;
    }
}
