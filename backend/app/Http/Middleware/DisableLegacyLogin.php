<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DisableLegacyLogin
{
    public function handle(Request $request, Closure $next)
    {
        abort_if(config('shared_sso.enabled'), 404);
        return $next($request);
    }
}
