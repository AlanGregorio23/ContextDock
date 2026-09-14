<?php

namespace Tests\Feature;

use App\Jobs\CreateBackup;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
    }

    public function test_backup_creates_structured_json_and_records_audit(): void
    {
        $user = User::factory()->create(['name' => 'Alice']);
        $workspace = Workspace::create(['user_id' => $user->id, 'name' => 'Core Space']);
        $project = Project::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'name' => 'ContextDock MVP',
        ]);
        Memory::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'scope' => 'project',
            'type' => 'decision',
            'title' => 'Embedding Model',
            'content' => 'qwen3-embedding:0.6b via Ollama',
            'content_hash' => hash('sha256', 'qwen3-embedding:0.6b via Ollama'),
        ]);
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Architecture Discussion',
        ]);
        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Can we keep external AI calls separate?',
        ]);

        $service = app(BackupService::class);
        $path = $service->createForUser($user);

        Storage::disk('backups')->assertExists($path);

        $json = Storage::disk('backups')->get($path);
        $data = json_decode($json, true);

        $this->assertEquals('1.0', $data['version']);
        $this->assertEquals('Alice', $data['user']['name']);
        $this->assertCount(1, $data['workspaces']);
        $this->assertCount(1, $data['projects']);
        $this->assertCount(1, $data['memories']);
        $this->assertCount(1, $data['conversations']);
        $this->assertCount(1, $data['messages']);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'backup.created',
            'subject_type' => 'backup',
        ]);
    }

    public function test_backup_dispatch_queues_job_and_logs_request(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $service = app(BackupService::class);
        $service->dispatch($user);

        Queue::assertPushed(CreateBackup::class, function ($job) use ($user) {
            return $job->userId === $user->id;
        });

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'backup.requested',
            'subject_type' => 'backup',
        ]);
    }
}
