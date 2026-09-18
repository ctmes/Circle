<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Behind Caddy in production, the TLS terminates at the proxy and PHP
         * sees a plain HTTP request from inside the Docker network. Without
         * this, Laravel decides the application is running on http and every
         * absolute URL it generates — including the signed storage URLs the
         * browser is about to call — comes out with the wrong scheme.
         *
         * Trusting every proxy is safe here and only here: nothing reaches
         * php-fpm except through Caddy, because in the production compose file
         * the API binds no host port at all. On a host where that is not true,
         * name the proxy instead.
         */
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This is a JSON API with no login page. Without this, an
        // unauthenticated call tries to redirect to a `login` route that does
        // not exist and surfaces as a 500 instead of a 401.
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
