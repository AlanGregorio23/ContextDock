<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\MemoryService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_project_decisions')]
#[Description('Get up to 20 current decisions from the project and its authorized inherited memory scopes.')]
#[IsReadOnly]
class GetProjectDecisions extends ContextTool
{
    protected function execute(User $user, Request $request): array
    {
        $input = $this->projectInput($request);
        $memories = app(MemoryService::class)->scope($user, $input['project_id'], $input['conversation_id'] ?? null)
            ->where('type', 'decision')->orderByDesc('created_at')->limit(20)->get();

        return ['decisions' => $memories->makeHidden('embedding')->toArray()];
    }
}
