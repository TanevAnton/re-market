<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Account\EmailChange;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The two links from the change-of-address mails: confirm, and cancel.
 *
 * PUBLIC, for the same reason verification.verify is public — see VerifyEmail
 * for the long version. The short one: the proof is the signature on the URL,
 * not the session, and demanding a session means the link only works in the
 * browser that started the change. Here it would be worse than there. The
 * confirmation link is opened in the NEW mailbox, which is very often a
 * different device from the one the change was requested on, and the cancel
 * link is for somebody whose password an attacker already knows — telling them
 * to log in first is telling them to do the one thing that is no longer theirs
 * alone.
 *
 * Neither link signs anybody in.
 */
class ChangeEmail extends Controller
{
    public function confirm(Request $request, EmailChange $service, string $id, string $token)
    {
        if (! $request->hasValidSignature()) {
            return $this->page('expired', 403);
        }

        $user = User::find($id);

        if ($user === null) {
            abort(403);
        }

        try {
            $service->confirm($user, $token);
        } catch (RuntimeException $e) {
            // „Линкът вече не важи", „изтекъл", or somebody else took the
            // address in the meantime. All three are sentences fit to show.
            return $this->page('failed', 403, $e->getMessage());
        }

        /*
         * If this IS the browser that asked for the change, the session's user
         * is the one that just moved address. Nothing needs re-authenticating —
         * the session is keyed on the account, not the address — so send them
         * on rather than making them read a page.
         */
        if ($request->user()?->is($user)) {
            return redirect()->route('profile.edit')
                ->with('status', 'Имейлът е сменен на '.$user->email.'.');
        }

        return $this->page('confirmed');
    }

    public function cancel(Request $request, EmailChange $service, string $id, string $token)
    {
        if (! $request->hasValidSignature()) {
            return $this->page('expired', 403);
        }

        $user = User::find($id);

        if ($user === null) {
            abort(403);
        }

        $cancelled = $service->cancel($user, $token);

        /*
         * A cancel that finds nothing to cancel is reported as success, not as
         * an error: the link was clicked twice, or the change lapsed on its
         * own. Either way the thing the reader wanted — no change to their
         * address — is true, and „невалиден линк" at that moment reads as „it
         * did not work" to somebody who is already frightened.
         *
         * UNLESS a DIFFERENT change is pending, which is the one case where
         * saying „cancelled" would be a lie that matters: an attacker can
         * cancel and immediately request again, and the victim clicking the
         * first mail's button would be told they were safe while the second
         * request ran its course.
         */
        if (! $cancelled && $user->fresh()?->pending_email !== null) {
            return $this->page('failed', 403,
                'Този линк е от по-стара заявка, а в момента тече нова. '
                .'Влез в профила си, за да я спреш, и смени паролата си.');
        }

        return $this->page('cancelled');
    }

    private function page(string $state, int $status = 200, ?string $reason = null)
    {
        return response()->view('auth.email-change', [
            'state'  => $state,
            'reason' => $reason,
        ], $status);
    }
}
