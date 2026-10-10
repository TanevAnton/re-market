<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * The app sits behind an Apache reverse proxy on the LAN, which
         * terminates TLS and forwards over plain HTTP. Without this, every
         * request appears to come from the proxy: rate limiting on login and
         * registration becomes global instead of per-visitor, and the scheme
         * is read as http, so generated URLs break on an https page.
         *
         * One exact address, never a subnet and never '*'. A trusted proxy
         * may set X-Forwarded-For to anything it likes, so widening this
         * lets anything on the LAN forge a client IP.
         */
        $middleware->trustProxies(at: ['192.168.1.77']);

        // Guards the actions where an unverified account could do damage -
        // listing, offering, messaging - never browsing.
        $middleware->alias([
            'phone.verified' => App\Http\Middleware\EnsurePhoneIsVerified::class,
            'admin'          => App\Http\Middleware\EnsureUserIsAdmin::class,

            /*
             * The account gate: a confirmed email OR a confirmed phone.
             *
             * NOT Laravel's EnsureEmailIsVerified any more. Registration asks
             * which way the person wants to prove the account, and both answers
             * have to unlock it - otherwise somebody who verified by SMS is
             * bounced to „потвърди имейла си" on every page that matters, with
             * no way out, having already done what the site asked of them.
             *
             * Naming the alias here rather than inheriting the framework
             * default is what made this a one-line change instead of an edit to
             * every route, and it is why the default was named explicitly in
             * the first place.
             *
             * Email remains the weaker proof - a throwaway address costs
             * nothing, so a ban costs nothing either - which is why
             * 'phone.verified' exists separately for the actions where that
             * matters.
             */
            'verified'       => App\Http\Middleware\EnsureAccountIsVerified::class,
        ]);

        // The theme cookie is written by JavaScript, so it cannot be encrypted:
        // an undecryptable cookie is silently read as null, which would mean the
        // server rendering the wrong theme on every single request.
        //
        // Nothing here is a secret - it says "this browser prefers dark".
        $middleware->encryptCookies(except: ['theme']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();