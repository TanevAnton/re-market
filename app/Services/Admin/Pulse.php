<?php

namespace App\Services\Admin;

use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\ModerationItem;
use App\Models\Payment;
use App\Models\StolenReport;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Every number the admin dashboard and the admin nav show.
 *
 * ONE CLASS BECAUSE THE TWO MUST NEVER DISAGREE. The counts in the account
 * menu and the counts on the dashboard are answers to the same questions, and
 * the moment they are written twice they start drifting — a badge saying 12
 * over a screen showing 5 is how a queue stops being trusted. The nav badges
 * were defined inline in the layout first; they now come from here.
 *
 * THE TIMEZONE IS THE WHOLE REASON THE DATE METHODS EXIST. `app.timezone` is
 * UTC, so `today()` in Laravel is midnight UTC — three hours into the Bulgarian
 * morning in summer. A listing published at 02:30 Sofia time would be counted
 * as yesterday's, and nobody would ever notice, because a slightly wrong
 * number looks exactly like a right one. Every boundary here is Sofia midnight
 * converted to the instant it corresponds to in UTC, which is what the columns
 * actually store.
 */
class Pulse
{
    /** The clock the operator reads these numbers against. Not app.timezone. */
    public function zone(): string
    {
        return (string) config('remarket.display_timezone', 'Europe/Sofia');
    }

    /** Local midnight today, as the UTC instant the database stores. */
    public function startOfToday(): Carbon
    {
        return Carbon::now($this->zone())->startOfDay()->utc();
    }

    /**
     * Local midnight at the start of an N-day window that INCLUDES today.
     * days(7) is "the last seven days", not "seven days before today".
     */
    public function startOfDays(int $days): Carbon
    {
        return Carbon::now($this->zone())->startOfDay()->subDays(max(0, $days - 1))->utc();
    }

    // --- activity ---------------------------------------------------------

    /**
     * Listings PUBLISHED in the window, not created in it.
     *
     * A draft started on Monday and published on Thursday is Thursday's news;
     * `created_at` would file it under a day on which nothing appeared on the
     * site. Soft-deleted rows are excluded by the model's global scope, which
     * is correct here: a listing its seller withdrew did not happen.
     */
    public function listingsPublishedSince(Carbon $since): int
    {
        return Listing::whereNotNull('published_at')
            ->where('published_at', '>=', $since)
            ->count();
    }

    /** Listings a visitor can actually buy from right now. */
    public function activeListings(): int
    {
        return Listing::where('status', ListingStatus::Active)->count();
    }

    /** Active plus reserved — everything the public can see. */
    public function visibleListings(): int
    {
        return Listing::visible()->count();
    }

    /**
     * Accounts that still exist AND still belong to somebody.
     *
     * Anonymised rows are excluded on purpose. After a GDPR erasure the row
     * survives — it has to, so a ban can outlive the account — but counting it
     * as a user would mean the site's user count can never go down, which
     * makes it a vanity number rather than a fact.
     */
    public function users(): int
    {
        return User::whereNull('anonymised_at')->count();
    }

    public function usersJoinedSince(Carbon $since): int
    {
        return User::whereNull('anonymised_at')
            ->where('created_at', '>=', $since)
            ->count();
    }

    public function dealsOpenedSince(Carbon $since): int
    {
        return Deal::where('created_at', '>=', $since)->count();
    }

    public function dealsCompletedSince(Carbon $since): int
    {
        return Deal::where('status', DealStatus::Completed)
            ->where('completed_at', '>=', $since)
            ->count();
    }

    /** Handovers in flight — somebody is waiting on somebody right now. */
    public function openDeals(): int
    {
        return Deal::where('status', DealStatus::Open)->count();
    }

    // --- queues that are waiting on a person ------------------------------
    //
    // These are the definitions the nav badges use. Changing one changes both
    // screens, which is the point.

    public function moderationQueue(): int
    {
        return ModerationItem::queue()->count();
    }

    public function pendingPayments(): int
    {
        return Payment::pending()->count();
    }

    /**
     * Tickets whose last message nobody on this side has read.
     *
     * Derived from message ids rather than a flag, so nothing has to remember
     * to decrement a counter — and ids rather than timestamps, because those
     * columns store whole seconds and a same-second reply made the comparison
     * silently false. See the migration.
     */
    public function openTickets(): int
    {
        return Ticket::working()
            ->whereNotNull('last_message_id')
            ->where(fn ($q) => $q->whereNull('staff_seen_message_id')
                ->orWhereColumn('staff_seen_message_id', '<', 'last_message_id'))
            ->count();
    }

    public function stolenClaims(): int
    {
        return StolenReport::pending()->count();
    }

    public function traderRequests(): int
    {
        return User::where('trader_status', User::TRADER_PENDING)->count();
    }

    /** Work that is available rather than work that is late. */
    public function uncatalogued(): int
    {
        return Listing::awaitingCatalogue()->count();
    }

    // --- the trend --------------------------------------------------------

    /**
     * Listings and new accounts per local day, oldest first.
     *
     * IN PHP, NOT IN SQL, and deliberately. Bucketing by Sofia day in Postgres
     * means `published_at AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Sofia'`,
     * whose correctness depends on whether the column was created as `timestamp`
     * or `timestamptz` — a detail that is invisible at the call site and wrong
     * in a way that silently shifts a day's worth of rows. Carbon converts
     * correctly either way.
     *
     * The cost is pulling one timestamp per row in the window. At this site's
     * volume that is a handful of rows; if a day ever brings thousands of
     * listings, this becomes a grouped query and that will be a good problem.
     *
     * @return list<array{date: string, label: string, listings: int, users: int}>
     */
    public function dailySeries(int $days = 7): array
    {
        $zone  = $this->zone();
        $since = $this->startOfDays($days);

        $buckets = [];
        $cursor  = Carbon::now($zone)->startOfDay()->subDays(max(0, $days - 1));

        for ($i = 0; $i < $days; $i++) {
            $buckets[$cursor->toDateString()] = [
                'date'     => $cursor->toDateString(),
                'label'    => $cursor->format('d.m'),
                'listings' => 0,
                'users'    => 0,
            ];
            $cursor = $cursor->copy()->addDay();
        }

        $tally = function (iterable $timestamps, string $key) use (&$buckets, $zone): void {
            foreach ($timestamps as $at) {
                $day = $at->copy()->setTimezone($zone)->toDateString();

                if (isset($buckets[$day])) {
                    $buckets[$day][$key]++;
                }
            }
        };

        $tally(
            Listing::whereNotNull('published_at')
                ->where('published_at', '>=', $since)
                ->pluck('published_at'),
            'listings',
        );

        $tally(
            User::whereNull('anonymised_at')
                ->where('created_at', '>=', $since)
                ->pluck('created_at'),
            'users',
        );

        return array_values($buckets);
    }

    // --- honesty ----------------------------------------------------------

    /**
     * Is any of this invented?
     *
     * While DemoSeeder's accounts are still here, every figure on the dashboard
     * is fiction, and an operator who learns to mentally subtract an unknown
     * amount has stopped reading the screen. Same marker the clear command
     * uses: `@demo.invalid` is reserved by RFC 2606 and can never be a real
     * address.
     */
    public function hasDemoData(): bool
    {
        return User::where('email', 'like', '%@demo.invalid')->exists();
    }
}
