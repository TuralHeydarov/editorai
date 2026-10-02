<?php

namespace App\Services\Auth;

use DomainException;

/** Protocol adapter; pending data must be held in the application's server session. */
final class PkceHandshake
{
    public function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly string $callback,
    ) {
        foreach ([$issuer, $callback] as $url) {
            $parts = parse_url($url);
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
                throw new DomainException('A fixed HTTPS OAuth configuration is required');
            }
        }
        if ($clientId === '') {
            throw new DomainException('A registered OAuth client is required');
        }
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Only authorization_url is sent to the browser; pending stays server-side. */
    public function begin(): array
    {
        $pending = [
            'state' => self::base64url(random_bytes(32)),
            'verifier' => self::base64url(random_bytes(32)),
            'nonce' => self::base64url(random_bytes(32)),
            'expires_at' => time() + 300,
            'issuer' => $this->issuer,
            'client_id' => $this->clientId,
            'callback' => $this->callback,
        ];
        $parameters = [
            'response_type' => 'code', 'client_id' => $this->clientId,
            'redirect_uri' => $this->callback, 'scope' => 'openid email profile',
            'state' => $pending['state'], 'nonce' => $pending['nonce'],
            'code_challenge' => self::base64url(hash('sha256', $pending['verifier'], true)),
            'code_challenge_method' => 'S256',
        ];
        return ['pending' => $pending, 'authorization_url' => rtrim($this->issuer, '/')
            .'/oauth/authorize?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986)];
    }

    /** Caller must atomically consume pending under a locked server session. */
    public function exchangeParameters(array &$pending, string $state, string $code): array
    {
        $saved = $pending;
        $pending = [];
        if (!is_string($saved['state'] ?? null) || $state === ''
            || !hash_equals($saved['state'], $state)
            || !is_int($saved['expires_at'] ?? null) || $saved['expires_at'] <= time()
            || ($saved['issuer'] ?? null) !== $this->issuer
            || ($saved['client_id'] ?? null) !== $this->clientId
            || ($saved['callback'] ?? null) !== $this->callback
            || !is_string($saved['verifier'] ?? null)
            || !preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $saved['verifier'])
            || $code === '' || strlen($code) > 2048) {
            throw new DomainException('Invalid or expired OAuth callback');
        }
        return [
            'grant_type' => 'authorization_code', 'client_id' => $this->clientId,
            'redirect_uri' => $this->callback, 'code' => $code,
            'code_verifier' => $saved['verifier'],
        ];
    }
}
