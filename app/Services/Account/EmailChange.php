<?php

namespace App\Services\Account;

use App\Models\User;
use App\Notifications\ConfirmNewEmail;
use App\Notifications\EmailChangeRequested;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Changing the address an account is reachable at.
 *
 * A service rather than three methods on the Livewire component, because the
 * confirm and cancel halves are hit by a public controller - the links have to
 * work from the phone the mail was opened on, not only from the browser that
 * asked for the change - and the rules below have to be identical on both
 * sides.
 *
 * THREE THINGS MAKE THIS SAFE, and none of them is optional:
 *
 *   1. The current password. Without it, an unattended logged-in session is a
 *      one-click account takeover: change the address, then „forgot password".
 *      The component asks for it; this service assumes it has been checked.
 *
 *   2. The new address is proved before it is adopted. Until somebody opens the
 *      link in the new inbox, users.email is untouched and the old address
 *      still works. A typo therefore costs nothing, which is the whole bug this
 *      feature exists to fix.
 *
 *   3. The OLD address is told. Requiring the password means an attacker who
 *      gets this far already has it, so that mail is not prevention - it is
 *      detection, and it is the only warning a victim ever gets. It carries a
 *      cancel link that works without logging in, because somebody whose
 *      password is known cannot be asked to log in to defend themselves.
 */
class EmailChange
{
    /** How long a confirmation link is good for. */
    private const TTL_MINUTES = 60;

    /** And how long before another one may be asked for. */
    private const RESEND_SECONDS = 120;

    /**
     * Start a change. Returns nothing; throws with a sentence fit to show.
     *
     * @throws RuntimeException
     */
    public function request(User $user, string $newEmail): void
    {
        $newEmail = mb_strtolower(trim($newEmail));

        if ($newEmail === mb_strtolower($user->email)) {
            throw new RuntimeException('Това вече е твоят адрес.');
        }

        /*
         * Checked here AND again at confirmation. Between the two, somebody
         * else may register the address or confirm their own claim on it, and
         * the second check is the one that actually protects users.email - this
         * one exists so the common case fails immediately rather than after a
         * round trip through a mailbox.
         */
        if (User::withTrashed()->whereRaw('lower(email) = ?', [$newEmail])->exists()) {
            throw new RuntimeException('Вече има профил с този имейл.');
        }

        if ($this->cooldownLeft($user) > 0) {
            throw new RuntimeException('Току-що изпратихме линк. Провери пощата си.');
        }

        /*
         * 40 random characters, stored as a sha256. The signature on the URL is
         * the real guard; this token is what makes a link REVOCABLE - a
         * signature cannot be withdrawn once it is issued, so without it the
         * cancel button in the warning mail would be decoration.
         */
        $token = Str::random(40);

        $user->forceFill([
            'pending_email'         => $newEmail,
            'pending_email_token'   => hash('sha256', $token),
            'pending_email_sent_at' => now(),
        ])->save();

        /*
         * To the NEW address, and it has to be routed by hand: $user->notify()
         * resolves the recipient from users.email, which is still the old one -
         * that is the entire point of this flow. Sending the proof-of-ownership
         * mail to the address already on file would prove nothing.
         */
        Notification::route('mail', $newEmail)->notify(new ConfirmNewEmail(
            $newEmail,
            $this->signedUrl('email.change.confirm', $user, $token),
            self::TTL_MINUTES,
        ));

        // To the old one: this is happening, and here is how to stop it.
        $user->notify(new EmailChangeRequested(
            $user->email,
            $newEmail,
            $this->signedUrl('email.change.cancel', $user, $token),
        ));
    }

    /**
     * Adopt the pending address.
     *
     * @throws RuntimeException
     */
    public function confirm(User $user, string $token): void
    {
        if (! $this->tokenMatches($user, $token)) {
            throw new RuntimeException('Линкът вече не важи.');
        }

        if ($this->expired($user)) {
            $this->clear($user);

            throw new RuntimeException('Линкът е изтекъл.');
        }

        $pending = $user->pending_email;

        /*
         * THE CHECK THAT ACTUALLY MATTERS. Minutes or an hour have passed since
         * the request, and in that time somebody else may have registered this
         * address or confirmed their own pending claim on it. users.email is
         * unique, so without this the confirmation dies as a constraint
         * violation - a 500 on a link somebody was told to trust.
         *
         * Excludes this user, so a double-click on the link is a no-op rather
         * than an error.
         */
        $taken = User::withTrashed()
            ->whereRaw('lower(email) = ?', [$pending])
            ->whereKeyNot($user->getKey())
            ->exists();

        if ($taken) {
            $this->clear($user);

            throw new RuntimeException('Вече има профил с този имейл.');
        }

        $wasUnverified = $user->email_verified_at === null;

        $user->forceFill([
            'email'             => $pending,
            // They just proved it by opening this link. Asking them to prove
            // the same address twice would be theatre.
            'email_verified_at' => now(),
            'pending_email'         => null,
            'pending_email_token'   => null,
            'pending_email_sent_at' => null,
        ])->save();

        /*
         * Only for an account that had never confirmed an address at all.
         * Verified means „this account now has a proven mailbox" - it is not a
         * per-address event, and firing it on every change would run any
         * welcome flow hung off it again for an existing member.
         */
        if ($wasUnverified) {
            event(new Verified($user));
        }
    }

    /** Called from the warning mail, by somebody who may not be logged in. */
    public function cancel(User $user, string $token): bool
    {
        if (! $this->tokenMatches($user, $token)) {
            return false;
        }

        $this->clear($user);

        return true;
    }

    public function cooldownLeft(User $user): int
    {
        if ($user->pending_email_sent_at === null) {
            return 0;
        }

        $elapsed = (int) $user->pending_email_sent_at->diffInSeconds(now());

        return max(0, self::RESEND_SECONDS - $elapsed);
    }

    private function expired(User $user): bool
    {
        return $user->pending_email_sent_at === null
            || $user->pending_email_sent_at->lt(now()->subMinutes(self::TTL_MINUTES));
    }

    private function tokenMatches(User $user, string $token): bool
    {
        return $user->pending_email !== null
            && $user->pending_email_token !== null
            && hash_equals($user->pending_email_token, hash('sha256', $token));
    }

    private function clear(User $user): void
    {
        $user->forceFill([
            'pending_email'         => null,
            'pending_email_token'   => null,
            'pending_email_sent_at' => null,
        ])->save();
    }

    private function signedUrl(string $route, User $user, string $token): string
    {
        return URL::temporarySignedRoute(
            $route,
            Carbon::now()->addMinutes(self::TTL_MINUTES),
            ['id' => $user->getKey(), 'token' => $token],
        );
    }
}
