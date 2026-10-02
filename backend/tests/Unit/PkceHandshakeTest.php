<?php

namespace Tests\Unit;

use App\Services\Auth\PkceHandshake;
use DomainException;
use PHPUnit\Framework\TestCase;

class PkceHandshakeTest extends TestCase
{
    private function client(): PkceHandshake
    {
        return new PkceHandshake('https://issuer.example/auth/v1', 'fixture-client', 'https://app.example/auth/callback');
    }

    public function test_pkce_state_nonce_and_exact_callback(): void
    {
        $flow = $this->client()->begin();
        parse_str(parse_url($flow['authorization_url'], PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame('https://app.example/auth/callback', $query['redirect_uri']);
        $this->assertSame($flow['pending']['nonce'], $query['nonce']);
        $expected = rtrim(strtr(base64_encode(hash('sha256', $flow['pending']['verifier'], true)), '+/', '-_'), '=');
        $this->assertSame($expected, $query['code_challenge']);
        $this->assertNotSame($flow['pending']['state'], $this->client()->begin()['pending']['state']);
        $pending = $flow['pending'];
        $params = $this->client()->exchangeParameters($pending, $pending['state'], 'fixture-code');
        $this->assertSame([], $pending);
        $this->assertSame($flow['pending']['verifier'], $params['code_verifier']);
        $this->expectException(DomainException::class);
        $this->client()->exchangeParameters($pending, $flow['pending']['state'], 'fixture-code');
    }

    public function test_wrong_state_expiry_or_changed_client_is_consumed_and_denied(): void
    {
        foreach (['state', 'expires_at', 'client_id', 'callback', 'issuer', 'verifier'] as $field) {
            $pending = $this->client()->begin()['pending'];
            $state = $pending['state'];
            $pending[$field] = $field === 'expires_at' ? time() - 1 : 'wrong';
            try {
                $this->client()->exchangeParameters($pending, $state, 'fixture-code');
                $this->fail('Invalid pending context accepted');
            } catch (DomainException) {
                $this->assertSame([], $pending);
            }
        }
    }

    public function test_insecure_issuer_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        new PkceHandshake('http://issuer.example/auth/v1', 'fixture-client', 'https://app.example/auth/callback');
    }
}
