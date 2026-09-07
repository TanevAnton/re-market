<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the actions where an unverified account would do damage - creating
 * listings, sending offers, messaging - rather than the whole site. Browsing
 * stays open, because gating reading behind signup is how a marketplace with
 * no users stays a marketplace with no users.
 */
class EnsurePhoneIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        if ($user->phone_verified_at === null) {
            return redirect()->route('phone.verify');
        }

        return $next($request);
    }
}
