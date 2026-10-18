<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * „Влез с Google" — the two ends of the OAuth round trip.
 *
 * WHAT THIS DOES NOT DO IS THE INTERESTING PART: it does not create an account.
 *
 * Google hands back an address, a name and a picture. It does not hand back a
 * username, a phone number, or an agreement to the terms of use — and all three
 * are required to be a seller here. A flow that created the row anyway would
 * have to invent the username (permanent, on every listing), leave the phone
 * empty (the one field this site just decided to require), and treat a click on
 * a Google button as acceptance of a contract, which it is not. So the callback
 * parks what Google said in the session and sends a NEW person to a completion
 * screen. Only that screen writes a row.
 *
 * An EXISTING person is signed in immediately, which is the whole point of the
 * button.
 */
class GoogleAuth extends Controller
{
    /** How long the parked Google payload stays usable. */
    private const HANDOFF_TTL = 900;   // 15 minutes

    public function redirect()
    {
        if (! $this->configured()) {
            return redirect()->route('login')
                ->withErrors(['login' => 'Входът с Google не е настроен в момента.']);
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request)
    {
        if (! $this->configured()) {
            return redirect()->route('login')
                ->withErrors(['login' => 'Входът с Google не е настроен в момента.']);
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            /*
             * Everything lands here: the person pressing „Cancel" on Google's
             * consent screen, an expired state token, a clock skew, a
             * redirect_uri that does not match the console. None of it is the
             * visitor's problem to decode, and none of it should be a stack
             * trace on a login page.
             */
            report($e);

            return redirect()->route('login')
                ->withErrors(['login' => 'Входът с Google не завърши. Опитай пак или влез с парола.']);
        }

        $googleId = (string) $google->getId();
        $email    = mb_strtolower((string) $google->getEmail());

        if ($googleId === '' || $email === '') {
            return redirect()->route('login')
                ->withErrors(['login' => 'Google не ни върна имейл адрес. Влез с парола.']);
        }

        /*
         * GOOGLE MUST SAY THE ADDRESS IS VERIFIED, and this is not a formality.
         *
         * The entire reason it is safe to hand somebody an existing account on
         * the strength of a matching address is that Google has proved they
         * read that mailbox. An unverified address on a Google account proves
         * only that somebody typed it. Accepting one would turn this button
         * into „type any email, get that account".
         *
         * Rare in practice - consumer accounts are always verified - which is
         * exactly why it would never be noticed if it were missing.
         */
        if (! ($google->user['email_verified'] ?? false)) {
            return redirect()->route('login')
                ->withErrors(['login' => 'Google не е потвърдил този адрес. Влез с парола.']);
        }

        // Known Google identity: the ordinary case, and the fast one.
        if ($user = User::where('google_id', $googleId)->first()) {
            return $this->signIn($user);
        }

        /*
         * SAME ADDRESS, NO LINK YET. Link it.
         *
         * Google has proved this person reads that mailbox, which is strictly
         * stronger evidence than the password on the account - the password is
         * a secret that may have leaked, the mailbox is the thing every
         * password reset is sent to. So they are the legitimate owner and the
         * two ways in become one account. Refusing here would strand anybody
         * who has forgotten they signed up with a password, which is most
         * people, a year later.
         *
         * NOT withTrashed(): a deleted account stays deleted. Signing in must
         * not be a way to resurrect one, and the phone hash it keeps exists
         * precisely to stop a ban being shed that way.
         */
        if ($user = User::whereRaw('lower(email) = ?', [$email])->first()) {
            $user->forceFill([
                'google_id' => $googleId,

                // They just proved the address. If it was never confirmed, it
                // is confirmed now - asking them to go and click a link in the
                // mailbox Google just vouched for is theatre.
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            return $this->signIn($user);
        }

        /*
         * A NEW PERSON. Nothing is written yet.
         *
         * The session is the right place for this and a signed URL is not: this
         * payload is not a credential anybody should be able to carry between
         * browsers. It is server-side, it dies with the session, and it carries
         * its own expiry so a half-finished signup left open overnight does not
         * still create an account tomorrow.
         */
        $request->session()->put('google.signup', [
            'id'    => $googleId,
            'email' => $email,
            'name'  => (string) $google->getName(),
            'at'    => now()->timestamp,
        ]);

        return redirect()->route('register.complete');
    }

    /** The payload parked by callback(), or null if it is missing or stale. */
    public static function handoff(): ?array
    {
        $data = session('google.signup');

        if (! is_array($data) || ! isset($data['id'], $data['email'], $data['at'])) {
            return null;
        }

        if (now()->timestamp - (int) $data['at'] > self::HANDOFF_TTL) {
            session()->forget('google.signup');

            return null;
        }

        return $data;
    }

    private function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    private function signIn(User $user)
    {
        /*
         * The same suspension check Login::authenticate() makes, on purpose.
         * A second door into the site that does not ask the same question is
         * how a banned account walks back in, and „we forgot the other login
         * path" is the most ordinary way that happens.
         */
        if ($user->isSuspended()) {
            return redirect()->route('login')
                ->withErrors(['login' => 'Профилът е ограничен. Свържи се с нас.']);
        }

        Auth::login($user, remember: true);

        // Against session fixation: the id that existed before authenticating
        // must not still be valid after it.
        session()->regenerate();
        session()->forget('google.signup');

        return redirect()->intended(route('browse'));
    }
}
