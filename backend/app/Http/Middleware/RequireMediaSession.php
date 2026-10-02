<?php
namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class RequireMediaSession
{
    public const COOKIE = '__Host-editorai-media';
    public const TTL = 300;

    public static function validToken(PersonalAccessToken $token, User $user): bool
    {
        $expiration = config('sanctum.expiration');
        return $token->name === 'auth-token' && $token->tokenable instanceof User
            && (int) $token->tokenable_id === (int) $user->id && !$user->is_blocked
            && (!$token->expires_at || $token->expires_at->isFuture())
            && (!$expiration || $token->created_at->gt(now()->subMinutes($expiration)));
    }

    public function handle(Request $request, Closure $next)
    {
        if (config('shared_sso.enabled')) {
            // Old media leases never bypass full shared-session/revocation validation.
            return app(RequireSharedSession::class)->handle($request, function ($request) use ($next) {
                $request->setUserResolver(fn () => Auth::guard('web')->user());
                return $next($request);
            });
        }
        try {
            $lease = json_decode(Crypt::decryptString($request->cookie(self::COOKIE) ?? ''), true, 16, JSON_THROW_ON_ERROR);
            abort_unless(is_array($lease) && is_int($lease['until'] ?? null) && $lease['until'] > time()
                && $lease['until'] <= time() + self::TTL && is_int($lease['user'] ?? null)
                && is_int($lease['token'] ?? null) && is_string($lease['hash'] ?? null), 404);
            $user = User::find($lease['user']);
            $token = PersonalAccessToken::find($lease['token']);
            abort_unless($user && $token && self::validToken($token, $user)
                && hash_equals($token->token, $lease['hash']), 404);
        } catch (Throwable) { abort(404); }
        $request->setUserResolver(fn () => $user);
        return $next($request);
    }
}
