<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Runs before cookies/session middleware; active SSO never uses browser tokens. */
class ConfigureSharedSso
{
    public function handle(Request $request, Closure $next)
    {
        if (config('shared_sso.enabled') || config('shared_sso.linking_enabled')) {
            if (!app()->environment('testing') && !in_array(config('session.driver'), ['database', 'redis'], true)) {
                abort(503, 'Shared sign-in requires a server session store.');
            }
            config([
                'session.cookie' => '__Host-editorai-session',
                'session.domain' => null, 'session.path' => '/',
                'session.secure' => true, 'session.http_only' => true,
                'session.same_site' => 'lax', 'session.encrypt' => true,
                'session.block' => true, 'session.block_lock_seconds' => 10, 'session.block_wait_seconds' => 10,
                'cors.allowed_origins' => [rtrim(config('app.url'), '/')], 'cors.supports_credentials' => true,
            ]);
        }
        return $next($request);
    }
}
