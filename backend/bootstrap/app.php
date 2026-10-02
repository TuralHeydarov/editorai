<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(\Illuminate\Auth\Middleware\Authenticate::class, \App\Http\Middleware\SharedApiSession::class);
        $middleware->prepend(\App\Http\Middleware\ConfigureSharedSso::class);
        $middleware->encryptCookies(except: [\App\Http\Middleware\RequireMediaSession::COOKIE]);
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\SubstituteBindings::class, \App\Http\Middleware\RequireMediaSession::class);
        $middleware->alias([
            'sso.api' => \App\Http\Middleware\SharedApiSession::class,
            'sso.session' => \App\Http\Middleware\RequireSharedSession::class,
            'legacy.login' => \App\Http\Middleware\DisableLegacyLogin::class,
            'media.session' => \App\Http\Middleware\RequireMediaSession::class,
            'project.owner' => \App\Http\Middleware\EnsureProjectOwnership::class,
        ]);
        // Token-based auth — no CSRF needed for API
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
