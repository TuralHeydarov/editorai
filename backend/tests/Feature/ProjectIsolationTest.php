<?php

namespace Tests\Feature;

use App\Models\Clip;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services_external.json2video.api_key' => 'disabled-fixture',
            'services_external.pexels.api_key' => 'disabled-fixture',
            'services_external.freesound.token' => 'disabled-fixture',
            'services_external.openai.api_key' => 'disabled-fixture',
        ]);
        Http::preventStrayRequests();
    }

    private function project(?User $user): Project
    {
        return Project::create([
            'user_id' => $user?->id,
            'source_url' => '/storage/videos/fixture.mp4',
            'status' => 'uploaded',
        ]);
    }

    public function test_owner_can_read_uploaded_project_and_ownership_persists(): void
    {
        $owner = User::factory()->create();
        $project = $this->project($owner);
        $this->assertSame($owner->id, $project->fresh()->user_id);
        Sanctum::actingAs($owner);
        $this->getJson('/api/projects/'.$project->id)->assertOk();
        $this->getJson('/api/projects')->assertJsonCount(1);
    }

    public function test_other_user_and_orphan_are_denied_without_external_calls(): void
    {
        $project = $this->project(User::factory()->create());
        $orphan = $this->project(null);
        Sanctum::actingAs(User::factory()->create());
        foreach ([$project, $orphan] as $hidden) {
            $this->getJson('/api/projects/'.$hidden->id)->assertNotFound();
            $this->postJson('/api/projects/'.$hidden->id.'/render')->assertNotFound();
            $this->postJson('/api/projects/'.$hidden->id.'/analyze')->assertNotFound();
        }
        $this->getJson('/api/projects')->assertExactJson([]);
    }

    public function test_clip_cannot_be_used_through_a_different_project(): void
    {
        $owner = User::factory()->create();
        $first = $this->project($owner);
        $second = $this->project($owner);
        $clip = Clip::create(['project_id' => $second->id, 'trim_start' => 0, 'trim_end' => 1]);
        Sanctum::actingAs($owner);
        $this->putJson('/api/projects/'.$first->id.'/clips/'.$clip->id, ['title' => 'changed'])->assertNotFound();
        $this->deleteJson('/api/projects/'.$first->id.'/clips/'.$clip->id)->assertNotFound();
        $this->assertDatabaseHas('clips', ['id' => $clip->id, 'project_id' => $second->id, 'title' => null]);
    }

    public function test_anonymous_project_read_is_denied(): void
    {
        $project = $this->project(User::factory()->create());
        $this->getJson('/api/projects/'.$project->id)->assertUnauthorized();
    }
}
