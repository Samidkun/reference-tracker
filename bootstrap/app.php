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
        // Trust the load balancer's forwarding headers.
        //
        // Without this, a request that arrives over HTTPS at a proxy but
        // reaches PHP over plain HTTP reports isSecure() === false — so HSTS
        // was never sent and the session cookie was issued without Secure.
        // Both silently defeated their purpose on the most likely deployment
        // topology. Configurable so the trusted range can be narrowed.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );

        // Security headers belong on EVERY response, including error pages —
        // so this is a global prepend, not a web-group append.
        $middleware->prepend(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->web(append: [
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Must run BEFORE the session middleware writes its cookie, so it has
        // to be prepended rather than appended to the web group.
        $middleware->web(prepend: [
            \App\Http\Middleware\ForceHttpsSessionCookie::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
