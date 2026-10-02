<?php

namespace App\Services\Auth;

use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Throwable;

/** JWKS must come from the fixed trusted issuer, never a token header or request. */
final class OidcIdentityVerifier
{
    public function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly string $accessAudience = 'authenticated',
    ) {
        $parts = parse_url($issuer);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || $clientId === '' || $accessAudience === '') {
            throw new DomainException('A fixed issuer and registered client are required');
        }
    }

    private function decode(string $token, array $jwks): array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                throw new DomainException();
            }
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
            if (!in_array($header['alg'] ?? '', ['RS256', 'ES256'], true)
                || !is_string($header['kid'] ?? null) || $header['kid'] === '') {
                throw new DomainException();
            }
            $keys = array_values(array_filter($jwks['keys'] ?? [], static fn ($key) =>
                is_array($key) && ($key['kid'] ?? null) === $header['kid']
                && ($key['alg'] ?? null) === $header['alg']
                && ($key['use'] ?? 'sig') === 'sig'
                && (($key['kty'] ?? null) === ($header['alg'] === 'RS256' ? 'RSA' : 'EC'))
            ));
            if (count($keys) !== 1) {
                throw new DomainException();
            }
            $claims = (array) JWT::decode($token, JWK::parseKeySet(['keys' => $keys]));
            if (($claims['iss'] ?? null) !== $this->issuer
                || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
                || !is_int($claims['iat'] ?? null) || $claims['iat'] > time()
                || !is_string($claims['sub'] ?? null)
                || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $claims['sub'])) {
                throw new DomainException();
            }
            return $claims;
        } catch (Throwable) {
            // Never put a token, claim payload or provider response into logs/errors.
            throw new DomainException('Invalid shared identity token');
        }
    }

    public function verify(string $idToken, string $accessToken, string $nonce, array $jwks): array
    {
        $id = $this->decode($idToken, $jwks);
        $access = $this->decode($accessToken, $jwks);
        $idAudiences = is_array($id['aud'] ?? null) ? $id['aud'] : [$id['aud'] ?? null];
        $accessAudiences = is_array($access['aud'] ?? null) ? $access['aud'] : [$access['aud'] ?? null];
        if (!in_array($this->clientId, $idAudiences, true)
            || (count($idAudiences) > 1 && ($id['azp'] ?? null) !== $this->clientId)
            || (isset($id['azp']) && $id['azp'] !== $this->clientId)
            || $nonce === '' || !is_string($id['nonce'] ?? null) || !hash_equals($nonce, $id['nonce'])
            || !in_array($this->accessAudience, $accessAudiences, true)
            || ($access['client_id'] ?? null) !== $this->clientId
            || ($access['role'] ?? null) !== 'authenticated'
            || $access['sub'] !== $id['sub']) {
            throw new DomainException('Invalid shared identity binding');
        }
        if (isset($id['at_hash'])) {
            $expected = rtrim(strtr(base64_encode(substr(hash('sha256', $accessToken, true), 0, 16)), '+/', '-_'), '=');
            if (!is_string($id['at_hash']) || !hash_equals($expected, $id['at_hash'])) {
                throw new DomainException('Invalid access token binding');
            }
        }
        // Email and profile metadata never confer ownership or app permissions.
        return ['issuer' => $this->issuer, 'subject' => $id['sub']];
    }

    /** Access tokens on refresh have no ID token/nonce; retain the fixed client + session. */
    public function verifyAccess(string $accessToken, array $jwks): array
    {
        $claims = $this->decode($accessToken, $jwks);
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        if (!in_array($this->accessAudience, $audiences, true)
            || ($claims['client_id'] ?? null) !== $this->clientId
            || ($claims['role'] ?? null) !== 'authenticated'
            || !is_string($claims['session_id'] ?? null)
            || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $claims['session_id'])) {
            throw new DomainException('Invalid shared access session');
        }
        return ['issuer' => $this->issuer, 'subject' => $claims['sub'],
            'client_id' => $this->clientId, 'session_id' => $claims['session_id'], 'expires_at' => $claims['exp']];
    }
}
