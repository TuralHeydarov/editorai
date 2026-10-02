<?php

namespace Tests\Unit;

use App\Services\Auth\OidcIdentityVerifier;
use DomainException;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

class OidcIdentityVerifierTest extends TestCase
{
    private $key;
    private array $jwks;
    private array $identity;
    private array $access;

    protected function setUp(): void
    {
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($this->key);
        $encode = static fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $this->jwks = ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'fixture',
            'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e'])]]];
        $this->identity = ['iss' => 'https://issuer.example/auth/v1', 'aud' => 'fixture-client',
            'sub' => '11111111-1111-4111-8111-111111111111', 'nonce' => 'fixture-nonce', 'iat' => time(), 'exp' => time()+300];
        $this->access = array_replace($this->identity, ['aud' => 'authenticated',
            'client_id' => 'fixture-client', 'role' => 'authenticated']);
    }

    private function token(array $claims): string
    {
        return JWT::encode($claims, $this->key, 'RS256', 'fixture');
    }

    private function verifier(): OidcIdentityVerifier
    {
        return new OidcIdentityVerifier('https://issuer.example/auth/v1', 'fixture-client');
    }

    public function test_signed_tokens_return_only_verified_subject_binding(): void
    {
        $this->identity['email'] = 'untrusted-profile@example.invalid';
        $binding = $this->verifier()->verify($this->token($this->identity), $this->token($this->access), 'fixture-nonce', $this->jwks);
        $this->assertSame(['issuer' => $this->identity['iss'], 'subject' => $this->identity['sub']], $binding);
    }

    public function test_invalid_identity_and_access_claims_are_denied(): void
    {
        foreach ([
            ['id', 'iss', 'https://wrong.example/auth/v1'], ['id', 'aud', 'other-client'],
            ['id', 'nonce', 'wrong'], ['id', 'exp', time()-1], ['id', 'exp', null],
            ['id', 'azp', 'other-client'], ['id', 'sub', 'not-a-uuid'],
            ['access', 'iss', 'https://wrong.example/auth/v1'], ['access', 'aud', 'other-resource'],
            ['access', 'client_id', 'other-client'], ['access', 'role', 'service_role'],
            ['access', 'sub', '22222222-2222-4222-8222-222222222222'],
            ['access', 'exp', time()-1], ['access', 'iat', time()+60],
        ] as [$type, $claim, $value]) {
            $id = $this->identity; $access = $this->access;
            if ($type === 'id') { $id[$claim] = $value; } else { $access[$claim] = $value; }
            try {
                $this->verifier()->verify($this->token($id), $this->token($access), 'fixture-nonce', $this->jwks);
                $this->fail('Invalid claims accepted');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_wrong_signature_and_symmetric_algorithm_are_denied(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        foreach ([JWT::encode($this->identity, $other, 'RS256', 'fixture'),
            JWT::encode($this->identity, str_repeat('fixture', 8), 'HS256', 'fixture')] as $id) {
            try {
                $this->verifier()->verify($id, $this->token($this->access), 'fixture-nonce', $this->jwks);
                $this->fail('Invalid signature accepted');
            } catch (DomainException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_optional_access_hash_is_verified(): void
    {
        $access = $this->token($this->access);
        $this->identity['at_hash'] = rtrim(strtr(base64_encode(substr(hash('sha256', $access, true), 0, 16)), '+/', '-_'), '=');
        $this->assertSame($this->identity['sub'], $this->verifier()->verify($this->token($this->identity), $access, 'fixture-nonce', $this->jwks)['subject']);
        $this->identity['at_hash'] = 'wrong';
        $this->expectException(DomainException::class);
        $this->verifier()->verify($this->token($this->identity), $access, 'fixture-nonce', $this->jwks);
    }

    public function test_es256_signing_and_key_selection(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($key);
        $encode = static fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $jwks = ['keys' => [['kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'kid' => 'ec-fixture',
            'x' => $encode(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)), 'y' => $encode(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))]]];
        $id = JWT::encode($this->identity, $key, 'ES256', 'ec-fixture');
        $access = JWT::encode($this->access, $key, 'ES256', 'ec-fixture');
        $this->assertSame($this->identity['sub'], $this->verifier()->verify($id, $access, 'fixture-nonce', $jwks)['subject']);
        $jwks['keys'][] = $jwks['keys'][0];
        $this->expectException(DomainException::class);
        $this->verifier()->verify($id, $access, 'fixture-nonce', $jwks);
    }
}
