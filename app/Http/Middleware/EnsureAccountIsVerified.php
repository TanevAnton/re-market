<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The account gate: EITHER a confirmed email OR a confirmed phone.
 *
 * This replaces Laravel's EnsureEmailIsVerified on the `verified` alias,
 * because registration now asks which way the person wants to prove the
 * account and both answers have to actually unlock it. Leaving the framework's
 * middleware in place would have let somebody verify by SMS and still be
 * bounced to „потвърди имейла си" on every page that matters - a dead end with
 * no way out, since they already did what the site asked.
 *
 * WRITTEN AS ITS OWN CLASS RATHER THAN AS AN OVERRIDE OF hasVerifiedEmail().
 * Overriding that method on the User model would have been one line and would
 * have made the model lie: every caller - the signed verification link, the
 * resend throttle, Laravel's own listener - asks that question meaning the
 * email specifically. A gate that accepts two proofs belongs in the gate.
 *
 * What this deliberately does NOT do is make the two proofs equivalent
 * everywhere. A phone-verified account still has an unconfirmed email address,
 * and every notification the site sends goes to it. `phone.verified` remains
 * the separate, stronger gate for the actions where it matters.
 */
class EnsureAccountIsVerified
{
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        $user = $request->user();

        if (! $user) {
            return Redirect::guest(URL::route('login'));
        }

        if ($user->email_verified_at !== null || $user->phone_verified_at !== null) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Профилът не е потвърден.');
        }

        return Redirect::guest(URL::route($redirectToRoute ?: 'verification.notice'));
    }
}
