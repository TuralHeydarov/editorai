<?php
namespace Tests\Feature;

use App\Http\Middleware\RequireMediaSession;
use App\Models\Project;
use App\Models\User;
use App\Services\Auth\SharedSso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateProjectMediaTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;
    private $token;
    private string $bearer;
    private Project $project;
    private string $bytes;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shared_sso.enabled' => false, 'services_external.json2video.api_key' => 'disabled-fixture',
            'services_external.pexels.api_key' => 'disabled-fixture', 'services_external.freesound.token' => 'disabled-fixture',
            'services_external.openai.api_key' => 'disabled-fixture']);
        Http::preventStrayRequests();
        Storage::fake('public');
        $this->bytes = pack('N', 24).'ftypisom'.pack('N', 0).'isomiso2';
        Storage::disk('public')->put('videos/fixture.mp4', $this->bytes);
        $this->owner = User::factory()->create();
        $created = $this->owner->createToken('auth-token');
        $this->token = $created->accessToken; $this->bearer = $created->plainTextToken;
        $this->project = Project::create(['user_id' => $this->owner->id, 'source_url' => '/storage/videos/fixture.mp4', 'status' => 'uploaded']);
    }

    private function lease(?int $until = null): string
    {
        return Crypt::encryptString(json_encode(['user' => $this->owner->id, 'token' => $this->token->id,
            'hash' => $this->token->token, 'until' => $until ?? time()+RequireMediaSession::TTL]));
    }

    public function test_owner_mints_host_only_cookie_and_range_and_head_work_without_bearer_url(): void
    {
        $response = $this->withToken($this->bearer)->postJson('/api/projects/'.$this->project->id.'/media-session')->assertNoContent();
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === RequireMediaSession::COOKIE);
        $this->assertNotNull($cookie); $this->assertTrue($cookie->isSecure()); $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($cookie->getDomain()); $this->assertSame('/', $cookie->getPath()); $this->assertSame('lax', $cookie->getSameSite());
        $this->assertStringNotContainsString($this->bearer, $cookie->getValue());
        $this->flushHeaders()->withUnencryptedCookie(RequireMediaSession::COOKIE, $cookie->getValue());
        $url = '/api/projects/'.$this->project->id.'/media';
        $this->get($url, ['Range' => 'bytes=0-7'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-7/24')
            ->assertHeader('Content-Length', '8')->assertHeader('Content-Type', 'video/mp4');
        $this->get($url, ['Range' => 'bytes=999-1000'])->assertStatus(416);
        $head = $this->head($url)->assertOk()->assertHeader('Content-Length', '24');
        $this->assertStringContainsString('no-store', $head->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $head->headers->get('Cache-Control'));
        $this->assertSame($this->bytes, Storage::disk('public')->get('videos/fixture.mp4'));
    }

    public function test_anonymous_tampered_expired_and_revoked_media_are_denied(): void
    {
        $url = '/api/projects/'.$this->project->id.'/media';
        $this->get($url)->assertNotFound();
        $this->withUnencryptedCookie(RequireMediaSession::COOKIE, 'tampered')->get($url)->assertNotFound();
        $this->withUnencryptedCookie(RequireMediaSession::COOKIE, $this->lease(time()-1))->get($url)->assertNotFound();
        $lease = $this->lease(); $this->token->delete();
        $this->withUnencryptedCookie(RequireMediaSession::COOKIE, $lease)->get($url, ['Range' => 'bytes=0-7'])->assertNotFound();
    }

    public function test_owner_cannot_read_foreign_or_null_owned_project_and_no_assignments_occur(): void
    {
        $foreign = Project::create(['user_id' => User::factory()->create()->id, 'source_url' => '/storage/videos/other.mp4', 'status' => 'uploaded']);
        $orphan = Project::create(['user_id' => null, 'source_url' => '/storage/videos/orphan.mp4', 'status' => 'uploaded']);
        $this->withUnencryptedCookie(RequireMediaSession::COOKIE, $this->lease());
        foreach ([$foreign, $orphan] as $project) $this->get('/api/projects/'.$project->id.'/media')->assertNotFound();
        $this->assertNull($orphan->fresh()->user_id);
        $this->withToken($this->bearer)->postJson('/api/projects/'.$orphan->id.'/media-session')->assertNotFound();
    }

    public function test_public_path_query_tokens_traversal_symlinks_and_foreign_file_alias_fail_closed(): void
    {
        $this->get('/storage/videos/fixture.mp4')->assertNotFound();
        $this->get('/api/projects/'.$this->project->id.'/media?token='.$this->bearer)->assertNotFound();
        $this->withUnencryptedCookie(RequireMediaSession::COOKIE, $this->lease());
        foreach (['/storage/videos/../fixture.mp4', '/storage/videos/%2e%2e%2ffixture.mp4', '/storage/videos/fixture.mp4?query'] as $path) {
            $this->project->update(['source_url' => $path]); $this->get('/api/projects/'.$this->project->id.'/media')->assertNotFound();
        }
        Storage::disk('public')->put('outside.mp4', $this->bytes);
        symlink(Storage::disk('public')->path('outside.mp4'), Storage::disk('public')->path('videos/escape.mp4'));
        $this->project->update(['source_url' => '/storage/videos/escape.mp4']);
        $this->get('/api/projects/'.$this->project->id.'/media')->assertNotFound();
        $this->project->update(['source_url' => '/storage/videos/fixture.mp4']);
        Project::create(['user_id' => null, 'source_url' => '/storage/videos/fixture.mp4', 'status' => 'uploaded']);
        $this->get('/api/projects/'.$this->project->id.'/media')->assertNotFound();
    }

    public function test_expired_token_and_symlinked_video_directory_are_denied(): void
    {
        $url = '/api/projects/'.$this->project->id.'/media';
        $this->withUnencryptedCookie(RequireMediaSession::COOKIE, $this->lease());
        $this->token->update(['expires_at' => now()->subMinute()]);
        $this->head($url)->assertNotFound();
        $this->token->update(['expires_at' => null]);
        $disk = Storage::disk('public');
        rename($disk->path('videos'), $disk->path('outside-videos'));
        symlink($disk->path('outside-videos'), $disk->path('videos'));
        $this->get($url)->assertNotFound();
    }

    public function test_private_upload_external_render_is_rejected_before_mutation_or_provider_calls(): void
    {
        $this->withToken($this->bearer)->postJson('/api/projects/'.$this->project->id.'/render')->assertStatus(422);
        $this->assertSame('uploaded', $this->project->fresh()->status); Http::assertNothingSent();
        $this->withToken($this->bearer)->getJson('/api/projects/'.$this->project->id)->assertJsonPath('playback_url', '/api/projects/'.$this->project->id.'/media');
    }

    private function assertPrivateChatDenied(string $action): void
    {
        $this->mock(\App\Services\AIService::class, function ($mock) use ($action) {
            $mock->shouldReceive('processUserMessage')->once()->andReturn(['message' => 'fixture', 'action' => ['type' => $action]]);
        });
        $this->withToken($this->bearer)->postJson('/api/projects/'.$this->project->id.'/chat', ['message' => 'fixture'])->assertStatus(422);
        $this->assertSame('uploaded', $this->project->fresh()->status);
        $this->assertNull($this->project->fresh()->transcribe_job_id);
        Http::assertNothingSent();
    }

    public function test_chat_transcription_cannot_fetch_private_media(): void { $this->assertPrivateChatDenied('transcribe'); }
    public function test_chat_automatic_transcription_cannot_fetch_private_media(): void { $this->assertPrivateChatDenied('analyze_video'); }
    public function test_chat_render_cannot_fetch_private_media(): void { $this->assertPrivateChatDenied('render'); }

    public function test_provider_guard_rejects_absolute_private_urls_and_preserves_remote_transcription(): void
    {
        $provider = app(\App\Services\Json2VideoService::class);
        foreach (['/storage/videos/fixture.mp4', '/api/projects/1/media', config('app.url').'/storage/videos/fixture.mp4',
            'https://editorai.tural.ai/api/projects/1/media', 'https://editorai.tural.ai/storage/videos/%66ixture.mp4'] as $url) {
            try { $provider->transcribe($url); $this->fail('Private provider fetch was accepted'); }
            catch (\DomainException $e) { $this->assertStringContainsString('not enabled', $e->getMessage()); }
        }
        Http::assertNothingSent();
        Http::fake(['*' => Http::response(['job_id' => 'remote-fixture'])]);
        $this->assertSame('remote-fixture', $provider->transcribe('https://media.example/remote.mp4')['job_id']);
        Http::assertSentCount(1);
    }

    public function test_existing_transcript_analysis_and_timeline_editing_remain_available(): void
    {
        $this->project->update(['srt_content' => '1\n00:00:00,000 --> 00:00:01,000\nfixture']);
        $this->mock(\App\Services\AIService::class, function ($mock) {
            $mock->shouldReceive('processUserMessage')->once()->andReturn(['message' => 'fixture', 'action' => ['type' => 'analyze_video']]);
            $mock->shouldReceive('chat')->once()->andReturn('{"message":"transcript fixture"}');
        });
        $this->withToken($this->bearer)->postJson('/api/projects/'.$this->project->id.'/chat', ['message' => 'fixture'])
            ->assertOk()->assertJsonPath('action_result.analyzed', true);
        Http::assertNothingSent();
        $this->assertSame('uploaded', $this->project->fresh()->status);
    }

    public function test_shared_mode_checks_revocation_on_range_and_ignores_legacy_cookie(): void
    {
        config(['shared_sso.enabled' => true]);
        $subject = '00000000-0000-4000-8000-000000000001'; $issuer = 'https://id.fixture/auth/v1';
        DB::table('shared_identity_bindings')->insert(['user_id' => $this->owner->id, 'issuer' => $issuer, 'subject' => $subject,
            'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->owner, 'web')->withSession(['shared_identity' => 'fixture-encrypted'])
            ->withUnencryptedCookie(RequireMediaSession::COOKIE, $this->lease());
        $this->mock(SharedSso::class, function ($mock) use ($issuer, $subject) {
            $mock->shouldReceive('decrypt')->andReturn(['issuer' => $issuer, 'subject' => $subject]);
            $mock->shouldReceive('ensureActive')->once()->andReturn(['issuer' => $issuer, 'subject' => $subject]);
            $mock->shouldReceive('encrypt')->once()->andReturn('fixture-encrypted');
        });
        $url='/api/projects/'.$this->project->id.'/media';
        $this->get($url, ['Range' => 'bytes=0-7'])->assertStatus(206);
        $this->mock(SharedSso::class, function ($mock) {
            $mock->shouldReceive('decrypt')->andReturn([]);
            $mock->shouldReceive('ensureActive')->andThrow(new \DomainException());
        });
        $this->get($url, ['Range' => 'bytes=8-15'])->assertUnauthorized();
    }
}
