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
        // Guards the actions where an unverified account could do damage -
        // listing, offering, messaging - never browsing.
        $middleware->alias([
            'phone.verified' => App\Http\Middleware\EnsurePhoneIsVerified::class,
            'admin'          => App\Http\Middleware\EnsureUserIsAdmin::class,
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
