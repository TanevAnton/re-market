<?php

namespace App\Console\Commands;

use App\Models\SavedSearch;
use App\Notifications\SavedSearchMatches;
use App\Services\Search\SavedSearchService;
use Illuminate\Console\Command;

/**
 * Tells people about listings they asked to hear about.
 *
 * Without this the saved_searches table is a list of bookmarks. With it, a
 * buyer who wanted a 4070 under 500 finds out while the card is still for sale
 * - which on a used marketplace is a window measured in hours.
 *
 * Everything here is shaped by one risk: this is the only notification the
 * site sends that is not caused by another person acting, so it is the only
 * one that can feel like spam. The guards against that are the feature.
 */
class NotifySavedSearches extends Command
{
    protected $signature = 'remarket:notify-saved-searches {--dry : Report what would be sent, send nothing}';

    protected $description = 'Notify users about new listings matching their saved searches';

    public function handle(SavedSearchService $searches): int
    {
        $sent = 0;
        $seen = 0;

        SavedSearch::query()
            ->where('notify', true)
            ->with('user')
            ->chunkById(200, function ($chunk) use ($searches, &$sent, &$seen) {
                foreach ($chunk as $search) {
                    $seen++;

                    if (! $search->user || $search->user->banned_at) {
                        continue;
                    }

                    /*
                     * Only listings published since this search last reported.
                     * The watermark is per search rather than global: two
                     * searches belonging to the same person move independently,
                     * and a shared one would mean whichever ran first ate the
                     * matches for the other.
                     */
                    $since = $search->last_notified_at ?? $search->created_at;

                    $matches = $searches->matches($search, $since)
                        ->orderByDesc('published_at')
                        ->limit(20)
                        ->get();

                    if ($matches->isEmpty()) {
                        continue;
                    }

                    if ($this->option('dry')) {
                        $this->line(sprintf(
                            '  %s → %d match(es) for "%s"',
                            $search->user->username, $matches->count(), $search->name,
                        ));
                        $sent++;

                        continue;
                    }

                    $search->user->notify(new SavedSearchMatches(
                        $search,
                        $matches,
                        route('searches', ['open' => $search->id]),
                    ));

                    /*
                     * Move the watermark whether or not the notification is
                     * actually delivered. A user with every channel switched
                     * off would otherwise accumulate an ever-growing backlog
                     * that gets sent in full the moment they turn one back on.
                     */
                    $search->forceFill(['last_notified_at' => now()])->save();

                    $sent++;
                }
            });

        $this->info($this->option('dry')
            ? "Dry run: {$sent} of {$seen} saved search(es) would notify."
            : "Notified {$sent} of {$seen} saved search(es).");

        return self::SUCCESS;
    }
}
