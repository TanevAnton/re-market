<?php

namespace App\Services\Privacy;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\Listing;
use App\Models\User;
use App\Services\Images\ImageProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * „Изтрий профила ми", done so that it erases the person without erasing
 * everybody they ever traded with.
 *
 * THE SHAPE OF THIS IS DECIDED BY THE FOREIGN KEYS, not by preference. Every
 * user FK in this schema is `cascadeOnDelete`, so a real delete takes the
 * counterparty's deals, both directions of every rating, and the invoices чл. 38
 * ДОПК requires kept for five years. The account row therefore STAYS and the
 * person is scrubbed out of it — which is what GDPR Art. 17(3)(b) contemplates
 * and what the deletion screen tells them in plain Bulgarian before they confirm.
 *
 * NOTHING HERE EVER CALLS forceDelete(). There is a test asserting that.
 */
class AccountDeletion
{
    /** The window between asking and the data actually going. */
    public function graceDays(): int
    {
        return max(1, (int) config('remarket.privacy.deletion_grace_days', 30));
    }

    /**
     * Reasons this account cannot be closed yet, in the user's words.
     *
     * An open deal is the one that matters: somebody is waiting for a parcel or
     * for payment at a counter, and a seller who can evaporate mid-handover has
     * been handed a way to take the money and disappear from their own record.
     * „Finish or cancel your open deals first" is not bureaucracy, it is the
     * other person's protection.
     *
     * @return list<string>
     */
    public function blockers(User $user): array
    {
        $reasons = [];

        $openDeals = Deal::where('status', DealStatus::Open)
            ->where(fn ($q) => $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))
            ->count();

        if ($openDeals > 0) {
            $reasons[] = $openDeals === 1
                ? 'Имаш една незавършена сделка. Приключи я или я откажи — отсрещната страна чака.'
                : "Имаш {$openDeals} незавършени сделки. Приключи ги или ги откажи — отсрещната страна чака.";
        }

        return $reasons;
    }

    /**
     * Start the clock.
     *
     * The account goes dark immediately — listings down, profile gone — but the
     * data survives until the sweep. Both halves matter: the user gets the effect
     * they asked for straight away, and the destruction is still reversible for
     * long enough that a hijacked account is not a permanent loss.
     */
    public function request(User $user): User
    {
        if ($user->anonymised_at !== null) {
            throw new RuntimeException('Профилът вече е изтрит.');
        }

        if ($blockers = $this->blockers($user)) {
            throw new RuntimeException($blockers[0]);
        }

        if ($user->deletion_requested_at !== null) {
            return $user;  // Asked twice. Not an error.
        }

        DB::transaction(function () use ($user) {
            $user->forceFill(['deletion_requested_at' => now()])->save();

            /*
             * Off the market at once. A listing that stays up after its seller
             * has left produces an offer nobody will ever answer, and the buyer
             * blames the site rather than the absent seller.
             *
             * SOFT delete, and that is load-bearing: `deals.listing_id` is
             * `cascadeOnDelete`, so a hard delete here would take out every deal
             * ever done against these listings — and the ratings hanging off
             * them. A soft delete is an UPDATE and fires no cascade.
             */
            Listing::where('user_id', $user->id)->delete();
        });

        return $user;
    }

    /** They came back. */
    public function cancel(User $user): User
    {
        if ($user->anonymised_at !== null) {
            throw new RuntimeException('Профилът вече е изтрит и не може да се възстанови.');
        }

        $user->forceFill(['deletion_requested_at' => null])->save();

        // Listings are NOT restored. They came down with a reason, their
        // expiry kept running while they were gone, and silently re-publishing
        // a week-old price is worse than making the seller look at it.
        return $user;
    }

    public function due(User $user): bool
    {
        return $user->deletion_requested_at !== null
            && $user->anonymised_at === null
            && $user->deletion_requested_at->addDays($this->graceDays())->isPast();
    }

    /**
     * The scrub itself.
     *
     * WHAT GOES: everything that identifies the person — name, username, email,
     * avatar, phone, company details, city, Telegram link, their saved searches,
     * favourites, drafts and the files behind their photographs.
     *
     * WHAT STAYS, and why each one is defensible under Art. 17(3):
     *
     *   - INVOICES and the credit ledger. чл. 38 ДОПК, five years. Not ours to
     *     delete and not theirs to demand.
     *   - DEALS and RATINGS, both directions. Somebody else's trading history
     *     and somebody else's reputation. Attributed to a tombstone, not a name.
     *   - MESSAGES. A conversation has two authors and the other one still needs
     *     to be able to read what was agreed. The privacy page says so.
     *   - The STOLEN-GOODS record, because a theft claim is a third party's
     *     report about an object, not a fact about the account.
     */
    public function anonymise(User $user): User
    {
        if ($user->anonymised_at !== null) {
            return $user;
        }

        $tombstone = 'deleted-'.Str::lower(Str::random(10));

        DB::transaction(function () use ($user, $tombstone) {
            $this->deleteOwnedFiles($user);

            $user->forceFill([
                'name'            => 'Изтрит профил',
                'username'        => $tombstone,
                // RFC 2606 reserves .invalid, so this can never be delivered to
                // and can never collide with a real address.
                'email'           => $tombstone.'@deleted.invalid',
                'password'        => bcrypt(Str::random(64)),
                'remember_token'  => null,
                'avatar_path'     => null,
                'city_id'         => null,
                'trader_details'  => null,
                'telegram_chat_id' => null,
                'notify_email'    => false,
                'notify_telegram' => false,
                'phone_last4'     => null,
                'phone_country'   => null,
                'phone_verified_at' => null,
                'email_verified_at' => null,

                /*
                 * THE PHONE HASH IS THE ONE JUDGEMENT CALL IN HERE.
                 *
                 * The original migration kept it deliberately: „the hash
                 * survives account deletion so a banned phone cannot simply
                 * re-register". That is a real anti-abuse interest — and it is
                 * not a good enough reason to keep a pseudonymous identifier for
                 * somebody who simply left. So it survives only where the
                 * interest is real: an account that was banned or suspended.
                 * Everyone else's goes, because „we might want it later" is not
                 * a lawful basis.
                 */
                'phone_hash'      => $this->wasEnforcedAgainst($user) ? $user->phone_hash : null,

                'anonymised_at'   => now(),
                'deleted_at'      => $user->deleted_at ?? now(),
            ])->save();

            // Purely theirs, depended on by nobody.
            DB::table('favorites')->where('user_id', $user->id)->delete();
            DB::table('saved_searches')->where('user_id', $user->id)->delete();
            DB::table('listing_drafts')->where('user_id', $user->id)->delete();
            DB::table('telegram_links')->where('user_id', $user->id)->delete();
            DB::table('part_promotion_dismissals')->where('user_id', $user->id)->delete();

            /*
             * Delivery details on their deals go NOW rather than waiting out
             * their own 30-day retention window. The deal record survives for
             * the counterparty; the address and phone on it do not need to.
             */
            foreach (['buyer_id', 'seller_id'] as $side) {
                Deal::where($side, $user->id)
                    ->whereNull('delivery_purged_at')
                    ->whereNotNull('delivery_set_at')
                    ->update([
                        'delivery_name'      => null,
                        'delivery_phone'     => null,
                        'delivery_address'   => null,
                        'delivery_office'    => null,
                        'delivery_city_id'   => null,
                        'delivery_note'      => null,
                        'delivery_purged_at' => now(),
                    ]);
            }
        });

        return $user;
    }

    /**
     * Was this account actually a problem, or did somebody just leave?
     *
     * Only the first justifies keeping the phone hash. Checked against the
     * enforcement columns rather than a guess.
     */
    private function wasEnforcedAgainst(User $user): bool
    {
        return $user->banned_at !== null
            || $user->suspended_until !== null
            || filled($user->suspension_reason);
    }

    /**
     * Their photographs, off the disk.
     *
     * The ROWS stay — `listing_images` hangs off listings that deals reference,
     * and this class does not create cascades it spent its docblock avoiding.
     * The FILES go, because one of them is by design a photograph of the person's
     * own handwriting with their username and a date on it.
     */
    private function deleteOwnedFiles(User $user): void
    {
        $paths = DB::table('listing_images')
            ->join('listings', 'listings.id', '=', 'listing_images.listing_id')
            ->where('listings.user_id', $user->id)
            ->pluck('listing_images.path');

        if ($paths->isEmpty()) {
            return;
        }

        // Full size and thumbnail, in one call: `delete()` is variadic and hands
        // the lot to Storage::delete(), which takes an array. A dozen listings
        // with a dozen photographs each is one filesystem call rather than 288.
        $both = $paths
            ->flatMap(fn (string $p) => [$p, str_replace('.jpg', '_t.jpg', $p)])
            ->all();

        try {
            (new ImageProcessor())->delete(...$both);
        } catch (\Throwable) {
            // A missing file is not a reason to abandon an erasure. The columns
            // are scrubbed either way; a half-finished scrub would be worse than
            // an orphaned file, and the sweep does not come back for this row.
        }
    }
}
