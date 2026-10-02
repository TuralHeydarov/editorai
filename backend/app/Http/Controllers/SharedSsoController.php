<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\SharedSso;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class SharedSsoController extends Controller
{
    public function status()
    {
        return response()->json(config('shared_sso.enabled') ? ['enabled' => true, 'csrf_token' => csrf_token()] : ['enabled' => false])
            ->header('Cache-Control', 'no-store');
    }

    public function start(Request $request, SharedSso $sso, bool $forLink = false)
    {
        abort_unless(config('shared_sso.enabled') || ($forLink && config('shared_sso.linking_enabled')), 404);
        try { $flow = $sso->handshake()->begin(); }
        catch (Throwable) { abort(503, 'Shared sign-in is unavailable.'); }
        $request->session()->put('shared_pending', $flow['pending']);
        return redirect()->away($flow['authorization_url'])->header('Cache-Control', 'no-store');
    }

    /** Explicit consent + live old token + fresh password; email never chooses owner. */
    public function link(Request $request, SharedSso $sso)
    {
        abort_unless(config('shared_sso.enabled') || config('shared_sso.linking_enabled'), 404);
        $request->validate(['password' => 'required|string', 'confirm_link' => 'accepted']);
        $token = PersonalAccessToken::findToken($request->bearerToken() ?? '');
        $user = $token?->tokenable;
        abort_unless($user instanceof User && $token->name === 'auth-token' && (!$token->expires_at || $token->expires_at->isFuture())
            && !$user->is_blocked && Hash::check($request->input('password'), $user->password), 403, 'Account proof required.');
        $response = $this->start($request, $sso, true);
        $pending = $request->session()->get('shared_pending');
        $pending['link_user_id'] = $user->id;
        $request->session()->put('shared_pending', $pending);
        return $request->expectsJson() ? response()->json(['authorization_url' => $response->headers->get('Location')])->header('Cache-Control', 'no-store') : $response;
    }

    public function callback(Request $request, SharedSso $sso)
    {
        abort_unless(config('shared_sso.enabled') || config('shared_sso.linking_enabled'), 404);
        $pending = $request->session()->pull('shared_pending', []);
        $nonce = $pending['nonce'] ?? '';
        $linkId = $pending['link_user_id'] ?? null;
        try {
            $parameters = $sso->handshake()->exchangeParameters($pending,
                (string) $request->query('state', ''), (string) $request->query('code', ''));
            $session = $sso->exchange($parameters, $nonce);
            $session = $sso->ensureActive($session);
        } catch (DomainException) { abort(403, 'Invalid or expired shared sign-in.'); }
        catch (Throwable) { abort(503, 'Shared sign-in is unavailable.'); }

        $binding = DB::table('shared_identity_bindings')
            ->where('issuer', $session['issuer'])->where('subject', $session['subject'])->first();
        if ($linkId !== null) {
            abort_if(($binding && $binding->user_id != $linkId)
                || DB::table('shared_identity_bindings')->where('user_id', $linkId)
                    ->where(function ($q) use ($session) {
                        $q->where('issuer', '!=', $session['issuer'])->orWhere('subject', '!=', $session['subject']);
                    })->exists(), 409, 'Account is already linked.');
            try {
                DB::table('shared_identity_bindings')->insertOrIgnore([
                    'user_id' => $linkId, 'issuer' => $session['issuer'], 'subject' => $session['subject'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (Throwable) { abort(409, 'Account link could not be confirmed.'); }
            $binding = DB::table('shared_identity_bindings')
                ->where('issuer', $session['issuer'])->where('subject', $session['subject'])->first();
            abort_unless($binding && $binding->user_id == $linkId, 409, 'Account link could not be confirmed.');
        }
        if (!$binding) {
            $session['deadline'] = time() + 300;
            $request->session()->put('shared_onboarding', $sso->encrypt($session));
            return redirect('/api/auth/sso/onboard')->header('Cache-Control', 'no-store');
        }
        $user = User::find($binding->user_id);
        abort_unless($user && !$user->is_blocked, 403, 'Application access is unavailable.');
        Auth::guard('web')->login($user);
        $request->session()->regenerate(true);
        $request->session()->forget('shared_onboarding');
        $request->session()->put('shared_identity', $sso->encrypt($session));
        return redirect('/')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function logout(Request $request, SharedSso $sso)
    {
        abort_unless(config('shared_sso.enabled') || config('shared_sso.linking_enabled'), 404);
        $global = $request->input('scope') === 'global';
        $failed = false;
        if ($global) {
            try {
                $stored = $request->session()->get('shared_identity');
                if (!$stored) { throw new DomainException(); }
                $session = $sso->ensureActive($sso->decrypt($stored));
                $sso->revoke($session);
            } catch (Throwable) { $failed = true; }
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($failed) { abort(503, 'Local logout completed; shared logout could not be confirmed.'); }
        return response()->json(['scope' => $global ? 'global' : 'local'])->header('Cache-Control', 'no-store');
    }

    public function loginPage()
    {
        abort_unless(config('shared_sso.enabled') || config('shared_sso.linking_enabled'), 404);
        return response()->view('shared-sso', ['mode' => 'login', 'sharedEnabled' => (bool) config('shared_sso.enabled'), 'appName' => 'EditorAI'])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function accountPage(Request $request)
    {
        abort_unless(config('shared_sso.enabled'), 404);
        return response()->view('shared-sso', ['mode' => 'account', 'appName' => 'EditorAI',
            'userName' => $request->user()->name])->header('Cache-Control', 'no-store');
    }

    public function onboardPage(Request $request, SharedSso $sso)
    {
        abort_unless(config('shared_sso.enabled'), 404);
        $stored = $request->session()->get('shared_onboarding');
        abort_unless(is_string($stored), 403, 'Sign in to Tural first.');
        try { $pending = $sso->decrypt($stored); }
        catch (Throwable) { abort(403, 'Sign in to Tural first.'); }
        abort_unless(($pending['deadline'] ?? 0) > time()
            && is_string($pending['profile']['email'] ?? null), 403, 'A verified email is required.');
        return response()->view('shared-sso', ['mode' => 'onboard', 'appName' => 'EditorAI',
            'email' => $pending['profile']['email']])->header('Cache-Control', 'no-store');
    }

    public function onboard(Request $request, SharedSso $sso)
    {
        abort_unless(config('shared_sso.enabled'), 404);
        $request->validate(['name' => 'required|string|max:255', 'confirm_create' => 'accepted']);
        $stored = $request->session()->pull('shared_onboarding');
        abort_unless(is_string($stored), 403, 'Sign in to Tural first.');
        try { $session = $sso->ensureActive($sso->decrypt($stored)); }
        catch (DomainException) { abort(403, 'Shared sign-in has ended.'); }
        catch (Throwable) { abort(503, 'Shared sign-in is unavailable.'); }
        abort_unless(($session['deadline'] ?? 0) > time()
            && is_string($session['profile']['email'] ?? null), 403, 'A verified email is required.');
        $email = $session['profile']['email'];
        // Email collision is a link prompt, never proof of ownership.
        abort_if(User::where('email', $email)->exists(), 409, 'Link your existing application account.');
        abort_if(DB::table('shared_identity_bindings')->where('issuer', $session['issuer'])
            ->where('subject', $session['subject'])->exists(), 409, 'Account is already linked.');
        try {
            $user = DB::transaction(function () use ($request, $session, $email) {
                $attributes = ['name' => $request->input('name'), 'email' => $email,
                    'password' => Hash::make(bin2hex(random_bytes(32)))];

                $user = User::create($attributes);
                DB::table('shared_identity_bindings')->insert(['user_id' => $user->id,
                    'issuer' => $session['issuer'], 'subject' => $session['subject'],
                    'created_at' => now(), 'updated_at' => now()]);
                return $user;
            });
        } catch (Throwable) { abort(409, 'Account creation could not be confirmed.'); }
        Auth::guard('web')->login($user);
        $request->session()->regenerate(true);
        unset($session['profile'], $session['deadline']);
        $request->session()->put('shared_identity', $sso->encrypt($session));
        return redirect('/')->header('Cache-Control', 'no-store');
    }
}
