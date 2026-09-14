<?php

namespace Tests\Feature;

use App\Models\Memory;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

class McpTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(array $abilities = ['context:read']): User
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('mcp-test', $abilities)->plainTextToken);

        return $user;
    }

    private function project(User $user): Project
    {
        $workspace = Workspace::create(['user_id' => $user->id, 'name' => 'Test workspace']);

        return Project::create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'name' => 'Test project']);
    }

    private function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], [
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-11-25',
        ]);
    }

    private function callTool(string $name, array $arguments): TestResponse
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments]);
    }

    public function test_http_requires_authentication(): void
    {
        $this->rpc('tools/list')->assertUnauthorized();
    }

    public function test_http_initialization_and_discovery_expose_the_tools(): void
    {
        $this->authenticate();
        $this->rpc('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
        ])->assertOk()->assertJsonPath('result.serverInfo.name', 'ContextDock');

        $response = $this->rpc('tools/list')->assertOk();
        $this->assertEqualsCanonicalizing([
            'search_memory', 'store_memory', 'get_project_context', 'search_documents',
            'get_project_decisions', 'get_pinned_memories', 'get_recent_context',
            'create_project', 'create_conversation', 'store_message',
        ], array_column($response->json('result.tools'), 'name'));
    }

    public function test_read_only_token_cannot_store_memory(): void
    {
        $user = $this->authenticate();
        $project = $this->project($user);
        $this->callTool('store_memory', [
            'project_id' => $project->id, 'type' => 'note', 'title' => 'Test', 'content' => 'Must not be persisted.',
        ])->assertOk()->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'Permission denied.');
        $this->assertDatabaseCount('memories', 0);
    }

    public function test_tool_call_returns_authorized_decisions_without_embedding(): void
    {
        $user = $this->authenticate();
        $project = $this->project($user);
        Memory::create([
            'user_id' => $user->id, 'workspace_id' => $project->workspace_id, 'project_id' => $project->id,
            'scope' => 'project', 'type' => 'decision', 'title' => 'Local embeddings', 'content' => 'Use Ollama.',
            'content_hash' => hash('sha256', 'Use Ollama.'),
        ]);

        $response = $this->callTool('get_project_decisions', ['project_id' => $project->id])
            ->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.decisions.0.title', 'Local embeddings');
        $this->assertArrayNotHasKey('embedding', $response->json('result.structuredContent.decisions.0'));
    }

    public function test_write_token_stores_a_memory_for_its_authenticated_owner(): void
    {
        Queue::fake();
        $user = $this->authenticate(['context:read', 'context:write']);
        $other = User::factory()->create();
        $project = $this->project($user);

        $this->callTool('store_memory', [
            'scope' => 'project', 'project_id' => $project->id, 'user_id' => $other->id,
            'type' => 'fact', 'title' => 'Embedding model', 'content' => 'qwen3-embedding:0.6b via Ollama',
        ])->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.memory.user_id', $user->id);
        $this->assertDatabaseHas('memories', [
            'user_id' => $user->id, 'project_id' => $project->id,
            'title' => 'Embedding model', 'embedding_status' => 'pending',
        ]);
    }

    public function test_tool_cannot_read_another_users_project(): void
    {
        $other = User::factory()->create();
        $project = $this->project($other);
        Memory::create([
            'user_id' => $other->id, 'workspace_id' => $project->workspace_id, 'project_id' => $project->id,
            'scope' => 'project', 'type' => 'decision', 'title' => 'Private decision', 'content' => 'Other-user secret.',
            'content_hash' => hash('sha256', 'Other-user secret.'),
        ]);
        $this->authenticate();

        $response = $this->callTool('get_project_decisions', ['project_id' => $project->id, 'user_id' => $other->id])
            ->assertOk()->assertJsonPath('result.isError', true);
        $this->assertStringNotContainsString('Other-user secret', $response->getContent());
        $this->assertStringNotContainsString('Private decision', $response->getContent());
    }

    public function test_context_tool_returns_pack_without_inspector_debug(): void
    {
        $user = $this->authenticate();
        $project = $this->project($user);
        $this->mock(ContextBuilder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->once()->andReturn([
                'pack' => ['project' => ['name' => 'Project'], 'relevant_memories' => []],
                'debug' => ['excluded' => 'INTERNAL_DEBUG_SENTINEL'], 'run_id' => 99,
            ]);
        });

        $response = $this->callTool('get_project_context', ['project_id' => $project->id, 'query' => 'What matters?'])
            ->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.project.name', 'Project');
        $this->assertStringNotContainsString('INTERNAL_DEBUG_SENTINEL', $response->getContent());
    }

    public function test_untrusted_browser_origin_is_rejected(): void
    {
        $this->authenticate();
        $this->withHeader('Origin', 'https://untrusted.example');
        $this->rpc('tools/list')->assertForbidden();
    }

    public function test_invalid_arguments_are_reported_without_internal_exception_details(): void
    {
        $this->authenticate();
        $this->callTool('get_pinned_memories', ['project_id' => -1])->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'Invalid arguments: project_id.');
    }

    public function test_tools_create_project_and_conversation_and_store_message(): void
    {
        $user = $this->authenticate(['context:read', 'context:write']);

        $projectRes = $this->callTool('create_project', [
            'name' => 'Qwen Managed Project',
            'description' => 'Created via MCP by Qwen assistant',
        ])->assertOk()->assertJsonPath('result.isError', false);

        $projectId = $projectRes->json('result.structuredContent.project.id');
        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'user_id' => $user->id,
            'name' => 'Qwen Managed Project',
        ]);

        $convRes = $this->callTool('create_conversation', [
            'project_id' => $projectId,
            'title' => 'Architecture Discussion with Qwen',
            'source' => 'qwen',
        ])->assertOk()->assertJsonPath('result.isError', false);

        $convId = $convRes->json('result.structuredContent.conversation.id');
        $this->assertDatabaseHas('conversations', [
            'id' => $convId,
            'project_id' => $projectId,
            'source' => 'qwen',
        ]);

        $msgRes = $this->callTool('store_message', [
            'conversation_id' => $convId,
            'role' => 'user',
            'content' => 'Please set up the repository architecture.',
        ])->assertOk()->assertJsonPath('result.isError', false);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $convId,
            'role' => 'user',
            'content' => 'Please set up the repository architecture.',
        ]);

        $this->callTool('store_message', [
            'conversation_id' => $convId,
            'role' => 'assistant',
            'content' => 'Repository architecture established successfully.',
        ])->assertOk()->assertJsonPath('result.isError', false);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $convId,
            'role' => 'assistant',
            'content' => 'Repository architecture established successfully.',
        ]);
    }
}
