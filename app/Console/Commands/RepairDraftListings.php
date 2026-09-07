<?php

namespace App\Console\Commands;

use App\Enums\ListingStatus;
use App\Models\Listing;
use Illuminate\Console\Command;

/**
 * One-off repair for listings stranded by the silent-mass-assignment bug.
 *
 * CreateListing::publish() set status/published_at/bumped_at/expires_at with
 * update(), and none of those four are in Listing::$fillable, so Eloquent
 * dropped them without a word. Every ad "published" before that fix is still
 * sitting at status = draft with no publish timestamps - invisible to everyone
 * including its owner's browse page.
 *
 * A draft here is distinguishable from a genuine unfinished draft by having at
 * least one uploaded photo: the wizard only writes images at the publish step.
 */
class RepairDraftListings extends Command
{
    protected $signature = 'remarket:repair-drafts {--apply : Write the changes; without this the command only reports}';

    protected $description = 'Publish listings that were stranded in draft by the mass-assignment bug';

    public function handle(): int
    {
        $stranded = Listing::query()
            ->where('status', ListingStatus::Draft)
            ->whereNull('published_at')
            ->has('images')
            ->with('user:id,username')
            ->get();

        if ($stranded->isEmpty()) {
            $this->info('Nothing stranded. Every draft either has no photos or was never through publish.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'seller', 'title', 'created'],
            $stranded->map(fn (Listing $l) => [
                $l->id,
                $l->user?->username ?? '?',
                mb_strimwidth($l->title, 0, 40, '...'),
                $l->created_at->diffForHumans(),
            ])
        );

        if (! $this->option('apply')) {
            $this->warn('Dry run. Re-run with --apply to publish these.');

            return self::SUCCESS;
        }

        $days = config('remarket.listings.expire_after_days', 60);

        foreach ($stranded as $listing) {
            // forceFill for the same reason publish() uses it: these four
            // columns are intentionally not mass-assignable.
            $listing->forceFill([
                'status'       => ListingStatus::Active,
                'published_at' => $listing->created_at,
                'bumped_at'    => $listing->created_at,
                'expires_at'   => $listing->created_at->copy()->addDays($days),
            ])->save();
        }

        $this->info("Published {$stranded->count()} listing(s).");

        return self::SUCCESS;
    }
}
