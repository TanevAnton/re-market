<?php

namespace App\Console\Commands;

use App\Models\DataExport;
use App\Models\User;
use App\Services\Privacy\AccountDeletion;
use App\Services\Privacy\PersonalDataExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The two halves of the privacy promise that only exist if a cron runs.
 *
 * 1. Accounts past their grace window get scrubbed.
 * 2. Expired export archives get deleted off the disk.
 *
 * LIKE THE DELIVERY PURGE, THIS FAILS SILENTLY BY LOOKING FINE. Nobody notices
 * that an account they asked to delete two months ago still holds their email
 * and phone, and nobody notices a folder of account archives accumulating. Both
 * are reported by `remarket:doctor` for that reason.
 */
class PurgeDeletedAccounts extends Command
{
    protected $signature = 'remarket:purge-accounts {--dry-run : List what would happen and change nothing}';

    protected $description = 'Anonymise accounts past their deletion grace period and delete expired data exports';

    public function handle(AccountDeletion $deletion, PersonalDataExport $exports): int
    {
        $dry = (bool) $this->option('dry-run');

        // --- accounts ------------------------------------------------------

        $cutoff = now()->subDays($deletion->graceDays());

        /*
         * withTrashed(), and it is required rather than tidy: request() does not
         * soft-delete the user, but a banned or otherwise removed account can
         * already be trashed, and those are exactly the ones whose data must
         * still go when they ask.
         */
        $due = User::withTrashed()
            ->whereNotNull('deletion_requested_at')
            ->whereNull('anonymised_at')
            ->where('deletion_requested_at', '<=', $cutoff)
            ->get();

        foreach ($due as $user) {
            if ($dry) {
                $this->warn("Would anonymise #{$user->id} ({$user->username}), asked {$user->deletion_requested_at->diffForHumans()}.");

                continue;
            }

            $deletion->anonymise($user);
            $this->info("Anonymised #{$user->id}.");
        }

        if ($due->isEmpty()) {
            $this->line('   No accounts past the grace window.');
        }

        // --- exports -------------------------------------------------------

        $stale = DataExport::where('expires_at', '<', now())
            ->whereIn('status', [DataExport::READY, DataExport::PENDING])
            ->get();

        $disk = $exports->disk();
        $gone = 0;

        foreach ($stale as $export) {
            if ($dry) {
                $this->warn("Would delete export {$export->uuid}.");

                continue;
            }

            if ($export->path && Storage::disk($disk)->exists($export->path)) {
                Storage::disk($disk)->delete($export->path);
            }

            // Status AND path cleared together: a row still naming a file that
            // is gone is the thing that makes a later audit unanswerable.
            $export->forceFill([
                'status' => DataExport::EXPIRED,
                'path'   => null,
            ])->save();

            $gone++;
        }

        $this->info($dry
            ? "Dry run: {$due->count()} account(s), {$stale->count()} export(s) would be purged."
            : "Purged {$due->count()} account(s) and {$gone} expired export(s).");

        return self::SUCCESS;
    }
}
