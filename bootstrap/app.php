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
             * The account gate WHILE SMS IS OFF. Laravel ships this alias by
             * default; naming it here makes the dependency explicit and stops
             * a framework default silently deciding who may post on the site.
             *
             * Email is a weaker proof than a phone number - a throwaway
             * address costs nothing, so a ban costs nothing either. The
             * moderation queue is what carries the weight until 'phone.verified'
             * goes on.
             */
            'verified'       => Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
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