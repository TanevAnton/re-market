<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;

/**
 * „Потвърди имейла" — the link from the verification mail.
 *
 * WHY THIS IS NOT BEHIND `auth`, WHICH IS WHERE IT STARTED.
 *
 * Laravel's own scaffolding puts this route in the authenticated group and
 * reads the user off the session. That works exactly once: in the browser that
 * registered, with the session still alive. Everywhere else — the phone the
 * mail app opens, a laptop at work, the browser that got closed on the way —
 * it redirects to the login screen, and the person is asked to sign in to an
 * account they are in the middle of proving they own. Several of them simply
 * do not come back.
 *
 * The session was never the proof here. The SIGNATURE is: the URL carries an
 * HMAC over itself keyed with APP_KEY, so it cannot be guessed or edited, and
 * it was delivered to the one mailbox whose ownership this whole exercise
 * exists to establish. Holding the link IS the evidence. Which browser is
 * holding it says nothing about anything.
 *
 * SO THE LINK VERIFIES AND DOES NOT SIGN ANYBODY IN. That is a deliberate
 * stop, and the temptation runs the other way — logging them in on the phone
 * would be the smoother story. But an email that logs you in is a password
 * that lives in a mailbox: forwarded threads, shared family addresses, a
 * support agent pasting „here, use my link", and a corporate mail scanner that
 * fetches every URL it is sent, all become ways into somebody's account. The
 * link proves an address. It is not a key to the account, and it should not
 * become one quietly.
 *
 * The hash is sha1 of the address as it stood when the mail went out, so a
 * link stops working the moment the address changes. That is correct: it was
 * proof about THAT address.
 */
class VerifyEmail extends Controller
{
    public function __invoke(Request $request, string $id, string $hash)
    {
        /*
         * Checked by hand rather than with the `signed` middleware, which
         * answers an expired link with a bare 403. Sixty minutes is easy to
         * miss — the mail arrives while somebody is working and gets opened on
         * the sofa — and „Access denied" at that moment reads as „your account
         * is broken" rather than „ask for another one".
         */
        if (! $request->hasValidSignature()) {
            return response()->view('auth.email-verified', ['state' => 'expired'], 403);
        }

        // find(), not findOrFail(): a soft-deleted account is excluded by the
        // model's global scope, so an old link cannot quietly revive one.
        $user = User::find($id);

        if ($user === null || ! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        $alreadyDone = $user->hasVerifiedEmail();

        if (! $alreadyDone) {
            $user->markEmailAsVerified();

            // Nothing listens to this yet. It is fired anyway because it is the
            // seam every „welcome" mail and onboarding step eventually hangs
            // off, and discovering later that it was never dispatched is a far
            // worse afternoon than this one line.
            event(new Verified($user));
        }

        /*
         * Same browser, same person: finish the journey rather than showing a
         * page that tells them to go somewhere. Any OTHER session — a guest, or
         * somebody signed in as a different account on a shared machine — is
         * left exactly as it was. Verifying an address is not a reason to touch
         * whoever happens to be logged in here.
         */
        if ($request->user()?->is($user)) {
            return redirect()->route('browse')->with(
                'status',
                $alreadyDone ? 'Имейлът вече е потвърден.' : 'Имейлът е потвърден.',
            );
        }

        return response()->view('auth.email-verified', [
            'state' => $alreadyDone ? 'already' : 'done',
        ]);
    }
}
