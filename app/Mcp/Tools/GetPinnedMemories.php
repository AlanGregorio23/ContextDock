<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\MemoryService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_pinned_memories')]
#[Description('Get up to 20 unexpired pinned memories in the authorized project and inherited memory scopes.')]
#[IsReadOnly]
class GetPinnedMemories extends ContextTool
{
    protected function execute(User $user, Request $request): array
    {
        $input = $this->projectInput($request);
        $memories = app(MemoryService::class)->scope($user, $input['project_id'], $input['conversation_id'] ?? null)
            ->where('is_pinned', true)->orderByDesc('importance')->orderByDesc('created_at')->limit(20)->get();

        return ['memories' => $memories->makeHidden('embedding')->toArray()];
    }
}
