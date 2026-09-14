<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_recent_context')]
#[Description('Get recent saved conversation context for a project, optionally restricted to one conversation.')]
#[IsReadOnly]
class GetRecentContext extends ContextTool
{
    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + ['limit' => $schema->integer()->min(1)->max(20)->default(10)];
    }

    protected function execute(User $user, Request $request): array
    {
        $input = $this->projectInput($request);

        return ['context' => app(ConversationService::class)->recent($user, $input['project_id'], $input['conversation_id'] ?? null, $input['limit'] ?? 10)];
    }
}
