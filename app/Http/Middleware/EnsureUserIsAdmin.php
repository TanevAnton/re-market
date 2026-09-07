<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The moderation queue, and anything else that acts on other people's content.
 *
 * 404 rather than 403: a 403 confirms the URL exists and is worth attacking.
 * Nobody who is not a moderator has any business knowing where the queue lives.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_admin, 404);

        return $next($request);
    }
}
