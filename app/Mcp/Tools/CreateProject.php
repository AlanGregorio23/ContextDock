<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('create_project')]
#[Description('Create a new project in ContextDock. Requires context:write. Allows Qwen or other AI agents to initialize and manage user projects.')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class CreateProject extends ContextTool
{
    protected string $ability = 'context:write';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(100)->required()->description('Name of the new project.'),
            'description' => $schema->string()->max(4000)->description('Optional description of the project.'),
            'workspace_id' => $schema->integer()->min(1)->description('Optional workspace ID; defaults to user\'s first workspace if omitted.'),
        ];
    }

    protected function execute(User $user, Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:4000'],
            'workspace_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $workspaceId = $validated['workspace_id'] ?? null;
        if (! $workspaceId) {
            $workspaceId = Workspace::where('user_id', $user->id)->orderBy('id')->value('id')
                ?? app(WorkspaceService::class)->createWorkspace($user, ['name' => 'Default Workspace'])->id;
        }

        $project = app(WorkspaceService::class)->createProject($user, [
            'workspace_id' => (int) $workspaceId,
            'name' => (string) $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return [
            'project' => [
                'id' => $project->id,
                'workspace_id' => $project->workspace_id,
                'name' => $project->name,
                'description' => $project->description,
            ],
        ];
    }
}
