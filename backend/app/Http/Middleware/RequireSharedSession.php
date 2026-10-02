<?php

namespace App\Http\Middleware;

use App\Services\Auth\SharedSso;
use Closure;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class RequireSharedSession
{
    public function handle(Request $request, Closure $next)
    {
        if (!config('shared_sso.enabled')) { return $next($request); }
        $sso = app(SharedSso::class);
        try {
            $stored = $request->session()->get('shared_identity');
            if (!$stored) { throw new DomainException(); }
            $session = $sso->ensureActive($sso->decrypt($stored));
            $user = Auth::guard('web')->user();
            if (!$user || $user->is_blocked || !DB::table('shared_identity_bindings')
                ->where('user_id', $user->id)->where('issuer', $session['issuer'])->where('subject', $session['subject'])->exists()) {
                throw new DomainException();
            }
            $request->session()->put('shared_identity', $sso->encrypt($session));
        } catch (Throwable $e) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort($e instanceof DomainException ? 401 : 503, 'Shared session is unavailable.');
        }
        return $next($request);
    }
}
