<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\SharedSso;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SharedSsoFlowTest extends TestCase
{
    use RefreshDatabase;

    private $key;
    private array $jwks;
    private string $subject = '11111111-1111-4111-8111-111111111111';
    private string $sessionId = '22222222-2222-4222-8222-222222222222';
    private string $client = '33333333-3333-4333-8333-333333333333';
    private string $issuer = 'https://issuer.example/auth/v1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['shared_sso.enabled' => true, 'shared_sso.issuer' => $this->issuer,
            'shared_sso.client_id' => $this->client, 'shared_sso.client_secret' => 'fixture-secret',
            'shared_sso.callback' => 'https://editor.example/api/auth/sso/callback',
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'services_external.json2video.api_key' => 'fixture-disabled',
            'services_external.pexels.api_key' => 'fixture-disabled',
            'services_external.freesound.token' => 'fixture-disabled',
            'services_external.openai.api_key' => 'fixture-disabled']);
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = openssl_pkey_get_details($this->key);
        $encode = static fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $this->jwks = ['keys' => [['kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'kid' => 'fixture',
            'x' => $encode(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)), 'y' => $encode(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))]]];
        // Only the production shared function is replaced; JWT/PKCE/HTTP/session/binding paths are real.
        $this->partialMock(SharedSso::class, function ($mock) { $mock->shouldReceive('sessionActive')->andReturn(true); });
        Http::preventStrayRequests();
    }

    private function fakeTokens(string $nonce, ?string $client = null, ?string $sessionId = null): void
    {
        $factory = new \Illuminate\Http\Client\Factory();
        Http::swap($factory);
        Http::preventStrayRequests();
        $id = ['iss' => $this->issuer, 'aud' => $this->client, 'sub' => $this->subject,
            'iat' => time(), 'exp' => time()+300, 'nonce' => $nonce, 'email' => 'new-fixture@example.invalid', 'email_verified' => true];
        $access = array_replace($id, ['aud' => 'authenticated', 'client_id' => $client ?? $this->client,
            'role' => 'authenticated', 'session_id' => $sessionId ?? $this->sessionId]);
        Http::fake([
            $this->issuer.'/oauth/token' => Http::response(['access_token' => JWT::encode($access, $this->key, 'ES256', 'fixture'),
                'id_token' => JWT::encode($id, $this->key, 'ES256', 'fixture'), 'refresh_token' => 'fixture-refresh', 'token_type' => 'bearer']),
            $this->issuer.'/.well-known/jwks.json' => Http::response($this->jwks),
            $this->issuer.'/logout?scope=global' => Http::response([], 204),
        ]);
    }

    private function bind(User $user): void
    {
        DB::table('shared_identity_bindings')->insert(['user_id' => $user->id,
            'issuer' => $this->issuer, 'subject' => $this->subject, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function begin(): array
    {
        $response = $this->get('/api/auth/sso/start')->assertRedirect();
        $this->assertStringStartsWith($this->issuer.'/oauth/authorize?', $response->headers->get('Location'));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('https://editor.example/api/auth/sso/callback', $query['redirect_uri']);
        return $query;
    }

    private function finish(array $query)
    {
        return $this->get('/api/auth/sso/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fixture-code']));
    }

    public function test_default_off_and_legacy_login_closed_when_enabled(): void
    {
        config(['shared_sso.enabled' => false]);
        $this->get('/api/auth/sso/status')->assertExactJson(['enabled' => false]);
        $this->get('/api/auth/sso/callback')->assertNotFound();
        config(['shared_sso.enabled' => true]);
        $this->postJson('/api/auth/login', ['email' => 'fixture@example.invalid', 'password' => 'fixture'])->assertNotFound();
        $this->postJson('/api/auth/register', [])->assertNotFound();
    }

    public function test_callback_creates_only_server_session_and_replay_is_denied(): void
    {
        $user = User::factory()->create(); $this->bind($user);
        $query = $this->begin(); $this->fakeTokens($query['nonce']);
        $response = $this->finish($query)->assertRedirect('/');
        $this->assertSame($user->id, Auth::guard('web')->id());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $ciphertext = session('shared_identity');
        $this->assertIsString($ciphertext);
        $this->assertStringNotContainsString('fixture-refresh', $ciphertext);
        $this->assertSame($this->subject, app(SharedSso::class)->decrypt($ciphertext)['subject']);
        $this->assertStringNotContainsString('fixture-code', $response->headers->get('Location'));
        $this->finish($query)->assertForbidden();
        Http::assertSentCount(2);
    }

    public function test_wrong_state_nonce_client_or_session_id_fail_closed(): void
    {
        $user = User::factory()->create(); $this->bind($user);
        $query = $this->begin();
        $this->get('/api/auth/sso/callback?state=wrong&code=x')->assertForbidden();
        Http::assertNothingSent();
        foreach ([['wrong-nonce', null, null], [null, 'another-client', null], [null, null, 'not-a-uuid']] as [$nonce, $client, $sid]) {
            $query = $this->begin(); $this->fakeTokens($nonce ?? $query['nonce'], $client, $sid);
            $this->finish($query)->assertForbidden();
            $this->assertNull(session('shared_identity'));
        }
    }

    public function test_unmapped_subject_never_receives_existing_account_by_email(): void
    {
        User::factory()->create(['email' => 'new-fixture@example.invalid']);
        $query = $this->begin(); $this->fakeTokens($query['nonce']);
        $this->finish($query)->assertRedirect('/api/auth/sso/onboard');
        $this->postJson('/api/auth/sso/onboard', ['name' => 'Fixture', 'confirm_create' => true])->assertStatus(409);
        $this->assertSame(0, DB::table('shared_identity_bindings')->count());
        $this->assertSame(1, User::count());
    }

    public function test_explicit_link_requires_old_token_password_and_consent(): void
    {
        $user = User::factory()->create(['password' => Hash::make('fixture-password')]);
        $token = $user->createToken('auth-token')->plainTextToken;
        $this->withToken($token)->postJson('/api/auth/sso/link', ['password' => 'wrong', 'confirm_link' => true])->assertForbidden();
        $this->withToken($token)->postJson('/api/auth/sso/link', ['password' => 'fixture-password'])->assertUnprocessable();
        $response = $this->withToken($token)->postJson('/api/auth/sso/link', ['password' => 'fixture-password', 'confirm_link' => true])->assertOk();
        parse_str(parse_url($response->json('authorization_url'), PHP_URL_QUERY), $query);
        $this->fakeTokens($query['nonce']); $this->finish($query)->assertRedirect('/');
        $this->assertDatabaseHas('shared_identity_bindings', ['user_id' => $user->id, 'subject' => $this->subject]);
    }

    public function test_existing_subject_cannot_be_relinked_to_another_old_user(): void
    {
        $first = User::factory()->create(); $this->bind($first);
        $second = User::factory()->create(['password' => Hash::make('fixture-password')]);
        $token = $second->createToken('auth-token')->plainTextToken;
        $response = $this->withToken($token)->postJson('/api/auth/sso/link', ['password' => 'fixture-password', 'confirm_link' => true])->assertOk();
        parse_str(parse_url($response->json('authorization_url'), PHP_URL_QUERY), $query);
        $this->fakeTokens($query['nonce']); $this->finish($query)->assertStatus(409);
        $this->assertDatabaseHas('shared_identity_bindings', ['user_id' => $first->id, 'subject' => $this->subject]);
        $this->assertSame(1, DB::table('shared_identity_bindings')->count());
    }

    public function test_global_logout_sends_fixed_provider_request_and_invalidates_local_session(): void
    {
        $user = User::factory()->create(); $this->bind($user);
        $query = $this->begin(); $this->fakeTokens($query['nonce']); $this->finish($query)->assertRedirect('/');
        $this->postJson('/api/auth/sso/logout', ['scope' => 'global'])->assertExactJson(['scope' => 'global']);
        $this->assertNull(session('shared_identity'));
        $this->assertNull(Auth::guard('web')->id());
        Http::assertSent(fn ($request) => $request->url() === $this->issuer.'/logout?scope=global');
    }

    public function test_api_accepts_only_bound_shared_session_and_revocation_denies_it(): void
    {
        $user = User::factory()->create(); $this->bind($user);
        $token = $user->createToken('auth-token')->plainTextToken;
        $this->withToken($token)->getJson('/api/auth/user')->assertUnauthorized();
        $query = $this->begin(); $this->fakeTokens($query['nonce']); $this->finish($query)->assertRedirect('/');
        $this->getJson('/api/auth/user')->assertOk()->assertJsonPath('id', $user->id);
        $this->partialMock(SharedSso::class, function ($mock) { $mock->shouldReceive('sessionActive')->andReturn(false); });
        $this->getJson('/api/auth/user')->assertUnauthorized();
        $this->assertNull(session('shared_identity'));
    }

    public function test_csrf_required_for_api_writes_and_link_even_with_a_shared_session(): void
    {
        $user = User::factory()->create(); $this->bind($user);
        $query = $this->begin(); $this->fakeTokens($query['nonce']); $this->finish($query)->assertRedirect('/');
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken {
                protected function runningUnitTests() { return false; }
            };
        });
        $this->postJson('/api/projects', [])->assertStatus(419);
        $this->postJson('/api/auth/sso/link', ['password' => 'fixture', 'confirm_link' => true])->assertStatus(419);
    }

    public function test_refresh_preserves_subject_client_and_session_without_returning_tokens(): void
    {
        $user = User::factory()->create(); $this->bind($user);
        $query = $this->begin(); $this->fakeTokens($query['nonce']); $this->finish($query)->assertRedirect('/');
        $sso = app(SharedSso::class); $session = $sso->decrypt(session('shared_identity'));
        $session['expires_at'] = time()-1;
        $this->withSession(['shared_identity' => $sso->encrypt($session)])->getJson('/api/auth/user')->assertOk();
        Http::assertSent(fn ($request) => $request->url() === $this->issuer.'/oauth/token'
            && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'fixture-refresh');
        $this->assertGreaterThan(time(), $sso->decrypt(session('shared_identity'))['expires_at']);
        $session = $sso->decrypt(session('shared_identity')); $session['expires_at'] = time()-1;
        $this->fakeTokens($query['nonce'], null, '44444444-4444-4444-8444-444444444444');
        $this->withSession(['shared_identity' => $sso->encrypt($session)])->getJson('/api/auth/user')->assertUnauthorized();
    }

    public function test_unavailable_session_verification_returns_503_and_clears_local_session(): void
    {
        $user = User::factory()->create(); $this->bind($user);
        $query = $this->begin(); $this->fakeTokens($query['nonce']); $this->finish($query)->assertRedirect('/');
        $this->partialMock(SharedSso::class, function ($mock) {
            $mock->shouldReceive('sessionActive')->andThrow(new \RuntimeException('Fixture unavailable'));
        });
        $this->getJson('/api/auth/user')->assertStatus(503);
        $this->assertNull(session('shared_identity'));
    }

    public function test_new_account_requires_explicit_onboarding_and_gets_no_existing_data_or_admin(): void
    {
        $query = $this->begin(); $this->fakeTokens($query['nonce']);
        $this->finish($query)->assertRedirect('/api/auth/sso/onboard');
        $this->assertSame(0, User::count());
        $this->get('/api/auth/sso/onboard')->assertOk()->assertSee('Create your');
        $this->postJson('/api/auth/sso/onboard', ['name' => 'Fixture'])->assertUnprocessable();
        $this->postJson('/api/auth/sso/onboard', ['name' => 'Fixture', 'confirm_create' => true])->assertRedirect('/');
        $this->assertSame(1, User::count());
        $user = User::first();
        $this->assertFalse((bool) $user->is_admin);
        $this->assertDatabaseHas('shared_identity_bindings', ['user_id' => $user->id, 'subject' => $this->subject]);
        $this->assertSame(0, DB::table('projects')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_shared_pages_render_account_choices_without_tokens(): void
    {
        $this->get('/api/auth/sso/login')->assertOk()->assertSee('Link an existing')->assertSee('Application password');
        $user = User::factory()->create(); $this->bind($user);
        $query = $this->begin(); $this->fakeTokens($query['nonce']); $this->finish($query)->assertRedirect('/');
        $response = $this->get('/api/auth/sso/account')->assertOk()->assertSee('Sign out of this app')->assertSee('Sign out of all Tural apps');
        $this->assertStringNotContainsString('fixture-refresh', $response->getContent());
        $this->assertStringNotContainsString('fixture-secret', $response->getContent());
    }

    public function test_prelink_stage_keeps_legacy_signin_and_requires_old_session_for_link(): void
    {
        config(['shared_sso.enabled' => false, 'shared_sso.linking_enabled' => true]);
        $user = User::factory()->create(['password' => Hash::make('fixture-password')]);
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'fixture-password'])->assertOk();
        $token = $response->json('token');
        $this->get('/api/auth/sso/start')->assertNotFound();
        $this->withToken($token)->postJson('/api/auth/sso/link', ['password' => 'fixture-password', 'confirm_link' => true])->assertOk();
        $this->get('/api/auth/sso/login')->assertOk()->assertDontSee('Continue with Tural</a>', false);
    }
}
