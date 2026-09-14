<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(array $abilities = ['context:read']): User
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('api-test', $abilities)->plainTextToken);

        return $user;
    }

    private function project(User $user): Project
    {
        $workspace = Workspace::create(['user_id' => $user->id, 'name' => 'Test workspace']);

        return Project::create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'name' => 'Test project']);
    }

    public function test_api_requires_token_authentication(): void
    {
        $this->postJson('/api/memory/search', ['project_id' => 1, 'query' => 'test'])->assertUnauthorized();
    }

    public function test_api_rejects_token_without_context_abilities(): void
    {
        $this->authenticate(['unrelated:scope']);
        $this->postJson('/api/memory/search', ['project_id' => 1, 'query' => 'test'])->assertForbidden();
    }

    public function test_api_store_memory_requires_context_write(): void
    {
        $user = $this->authenticate(['context:read']);
        $project = $this->project($user);

        $this->postJson('/api/memory/store', [
            'scope' => 'project',
            'project_id' => $project->id,
            'type' => 'note',
            'title' => 'Test Note',
            'content' => 'Sample content',
        ])->assertForbidden();

        $this->assertDatabaseCount('memories', 0);
    }

    public function test_api_store_memory_succeeds_with_write_token(): void
    {
        Queue::fake();
        $user = $this->authenticate(['context:read', 'context:write']);
        $project = $this->project($user);

        $response = $this->postJson('/api/memory/store', [
            'scope' => 'project',
            'project_id' => $project->id,
            'type' => 'decision',
            'title' => 'Use Postgres pgvector',
            'content' => 'We use pgvector with cosine distance for semantic retrieval.',
        ])->assertCreated();

        $response->assertJsonPath('memory.title', 'Use Postgres pgvector');
        $this->assertDatabaseHas('memories', [
            'user_id' => $user->id,
            'project_id' => $project->id,
            'title' => 'Use Postgres pgvector',
            'type' => 'decision',
        ]);
    }

    public function test_api_context_pack_returns_valid_structure(): void
    {
        $user = $this->authenticate(['context:read']);
        $project = $this->project($user);

        $this->mock(ContextBuilder::class, function (MockInterface $mock) use ($project): void {
            $mock->shouldReceive('build')->once()->andReturn([
                'pack' => [
                    'project' => ['id' => $project->id, 'name' => $project->name],
                    'relevant_memories' => [],
                    'metadata' => ['version' => '1.0', 'estimated_tokens' => 45],
                ],
                'debug' => ['candidate_limit' => 60],
                'run_id' => 123,
            ]);
        });

        $response = $this->postJson('/api/context', [
            'project_id' => $project->id,
            'query' => 'Summarize architecture',
        ])->assertOk();

        $response->assertJsonPath('project.name', 'Test project');
        $response->assertJsonPath('metadata.version', '1.0');
    }

    public function test_api_debug_context_returns_debug_information(): void
    {
        $user = $this->authenticate(['context:read']);
        $project = $this->project($user);

        $this->mock(ContextBuilder::class, function (MockInterface $mock) use ($project): void {
            $mock->shouldReceive('build')->once()->andReturn([
                'pack' => ['project' => ['id' => $project->id]],
                'debug' => ['candidate_limit' => 60, 'debug_info' => 'VISIBLE_IN_DEBUG'],
                'run_id' => 456,
            ]);
        });

        $response = $this->postJson('/api/context/debug', [
            'project_id' => $project->id,
            'query' => 'Debug query',
        ])->assertOk();

        $response->assertJsonPath('debug.debug_info', 'VISIBLE_IN_DEBUG');
        $response->assertJsonPath('run_id', 456);
    }

    public function test_api_conversation_and_message_lifecycle(): void
    {
        $user = $this->authenticate(['context:read', 'context:write']);
        $project = $this->project($user);

        $convResponse = $this->postJson('/api/conversations', [
            'project_id' => $project->id,
            'title' => 'API Chat Session',
            'source' => 'rest_api',
        ])->assertCreated();

        $conversationId = $convResponse->json('conversation.id');

        $msgResponse = $this->postJson("/api/conversations/{$conversationId}/messages", [
            'role' => 'user',
            'content' => 'Hello ContextDock from REST client',
        ])->assertCreated();

        $msgResponse->assertJsonPath('message_item.role', 'user');

        $listResponse = $this->getJson("/api/projects/{$project->id}/conversations/{$conversationId}/messages")
            ->assertOk();

        $this->assertCount(1, $listResponse->json('messages'));
        $this->assertEquals('Hello ContextDock from REST client', $listResponse->json('messages.0.content'));
    }

    public function test_api_cannot_access_another_users_project(): void
    {
        $otherUser = User::factory()->create();
        $otherProject = $this->project($otherUser);

        $this->authenticate(['context:read', 'context:write']);

        $this->postJson('/api/memory/store', [
            'scope' => 'project',
            'project_id' => $otherProject->id,
            'type' => 'note',
            'title' => 'Infiltration Attempt',
            'content' => 'Trying to write to another users project',
        ])->assertStatus(404);
    }
}
