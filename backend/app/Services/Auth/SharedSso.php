<?php

namespace App\Services\Auth;

use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SharedSso
{
    public function handshake(): PkceHandshake
    {
        abort_unless(config('shared_sso.enabled') || config('shared_sso.linking_enabled'), 404);
        if (config('shared_sso.client_secret') === '') {
            throw new RuntimeException('Shared sign-in is not configured.');
        }
        return new PkceHandshake(config('shared_sso.issuer'), config('shared_sso.client_id'), config('shared_sso.callback'));
    }

    private function request()
    {
        return Http::acceptJson()->timeout(8)->connectTimeout(3)
            ->withOptions(['allow_redirects' => false])
            ->withHeaders(config('shared_sso.api_key') === '' ? [] : ['apikey' => config('shared_sso.api_key')]);
    }

    private function verifier(): OidcIdentityVerifier
    {
        return new OidcIdentityVerifier(config('shared_sso.issuer'), config('shared_sso.client_id'), config('shared_sso.audience'));
    }

    private function jwks(): array
    {
        $response = $this->request()->get(rtrim(config('shared_sso.issuer'), '/').'/.well-known/jwks.json');
        if (!$response->successful() || !is_array($response->json('keys'))) {
            throw new RuntimeException('Shared sign-in is unavailable.');
        }
        return $response->json();
    }

    private function tokens(array $parameters): array
    {
        $response = $this->request()->asForm()
            ->withBasicAuth(config('shared_sso.client_id'), config('shared_sso.client_secret'))
            ->post(rtrim(config('shared_sso.issuer'), '/').'/oauth/token', $parameters);
        $tokens = $response->json();
        if (!$response->successful() || !is_array($tokens)
            || !is_string($tokens['access_token'] ?? null) || !is_string($tokens['refresh_token'] ?? null)
            || ($tokens['token_type'] ?? '') !== 'bearer') {
            throw new RuntimeException('Shared sign-in is unavailable.');
        }
        return $tokens;
    }

    public function exchange(array $parameters, string $nonce): array
    {
        $tokens = $this->tokens($parameters);
        $jwks = $this->jwks();
        $this->verifier()->verify($tokens['id_token'] ?? '', $tokens['access_token'], $nonce, $jwks);
        $session = $this->session($tokens, $jwks);
        // ID token signature, audience and nonce have been verified above.
        $profile = json_decode(\Firebase\JWT\JWT::urlsafeB64Decode(explode('.', $tokens['id_token'])[1]), true);
        if (($profile['email_verified'] ?? null) === true && is_string($profile['email'] ?? null)
            && strlen($profile['email']) <= 255 && filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) {
            $session['profile'] = ['email' => $profile['email']];
        }
        return $session;
    }

    private function session(array $tokens, array $jwks): array
    {
        $claims = $this->verifier()->verifyAccess($tokens['access_token'], $jwks);
        return array_merge($claims, [
            'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'],
        ]);
    }

    public function ensureActive(array $session): array
    {
        if (($session['issuer'] ?? null) !== config('shared_sso.issuer')
            || ($session['client_id'] ?? null) !== config('shared_sso.client_id')) {
            throw new DomainException('Invalid shared session.');
        }
        if (($session['expires_at'] ?? 0) <= time() + 30) {
            $updated = $this->session($this->tokens([
                'grant_type' => 'refresh_token', 'refresh_token' => $session['refresh_token'],
            ]), $this->jwks());
            if ($updated['subject'] !== $session['subject'] || $updated['session_id'] !== $session['session_id']) {
                throw new DomainException('Invalid refreshed session.');
            }
            $session = array_merge($session, $updated);
        }
        if (!$this->sessionActive($session['session_id'], $session['subject'])) {
            throw new DomainException('Shared session has ended.');
        }
        return $session;
    }

    public function sessionActive(string $sessionId, string $subject): bool
    {
        try {
            $row = DB::selectOne('select tural_auth.session_active(cast(? as uuid), cast(? as uuid)) as active', [$sessionId, $subject]);
            return $row && in_array($row->active, [true, 1, '1', 't'], true);
        } catch (Throwable) {
            throw new RuntimeException('Shared session verification is unavailable.');
        }
    }

    public function encrypt(array $session): string
    {
        return Crypt::encryptString(json_encode($session, JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $session): array
    {
        try {
            return json_decode(Crypt::decryptString($session), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DomainException('Invalid shared session.');
        }
    }

    public function revoke(array $session): void
    {
        $response = $this->request()->withToken($session['access_token'])
            ->post(rtrim(config('shared_sso.issuer'), '/').'/logout?scope=global');
        if (!$response->successful()) {
            throw new RuntimeException('Shared logout could not be confirmed.');
        }
    }
}
