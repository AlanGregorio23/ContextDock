<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('create_conversation')]
#[Description('Start a new conversation session associated with a project in ContextDock. Allows Qwen or other AI agents to track chat sessions.')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class CreateConversation extends ContextTool
{
    protected string $ability = 'context:write';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->min(1)->required()->description('Project ID the conversation belongs to.'),
            'title' => $schema->string()->max(200)->required()->description('Title or topic of the conversation.'),
            'source' => $schema->string()->max(100)->description('Originating client or model (e.g., "qwen", "chatgpt", "claude"). Defaults to "qwen".'),
        ];
    }

    protected function execute(User $user, Request $request): array
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:200'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $conversation = app(ConversationService::class)->create($user, [
            'project_id' => (int) $validated['project_id'],
            'title' => (string) $validated['title'],
            'source' => (string) ($validated['source'] ?? 'qwen'),
        ]);

        return [
            'conversation' => [
                'id' => $conversation->id,
                'project_id' => $conversation->project_id,
                'workspace_id' => $conversation->workspace_id,
                'title' => $conversation->title,
                'source' => $conversation->source,
            ],
        ];
    }
}
