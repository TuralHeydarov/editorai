<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;

/** Apply CSRF + server session before Sanctum only when the shared gate is active. */
class SharedApiSession
{
    public function handle(Request $request, Closure $next)
    {
        if (!config('shared_sso.enabled')) { return $next($request); }
        $middleware = app('router')->getMiddlewareGroups()['web'];
        $middleware[] = RequireSharedSession::class;
        return app(Pipeline::class)->send($request)->through($middleware)->then($next);
    }
}
