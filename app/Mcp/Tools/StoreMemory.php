<?php

namespace App\Mcp\Tools;

use App\Data\Input;
use App\Models\User;
use App\Services\MemoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('store_memory')]
#[Description('Persist a memory explicitly requested by the user. Requires context:write. Embedding is queued locally; indexing is asynchronous. Never store passwords, tokens, or private keys.')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class StoreMemory extends ContextTool
{
    protected string $ability = 'context:write';

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->enum(['global', 'workspace', 'project', 'session'])->required(),
            'workspace_id' => $schema->integer()->min(1)->description('Required for workspace scope.'),
            'project_id' => $schema->integer()->min(1)->description('Required for project and session scopes.'),
            'conversation_id' => $schema->integer()->min(1)->description('Required for session scope.'),
            'type' => $schema->string()->enum(['preference', 'fact', 'decision', 'instruction', 'task', 'summary', 'note', 'architecture', 'entity', 'temporary'])->required(),
            'title' => $schema->string()->max(200)->required(),
            'content' => $schema->string()->max(12000)->required(),
            'importance' => $schema->number()->min(0)->max(1),
            'confidence' => $schema->number()->min(0)->max(1),
            'is_pinned' => $schema->boolean(),
        ];
    }

    protected function execute(User $user, Request $request): array
    {
        return ['memory' => app(MemoryService::class)->store($user, Input::memory($user, $request->all()))->makeHidden('embedding')->toArray()];
    }
}
