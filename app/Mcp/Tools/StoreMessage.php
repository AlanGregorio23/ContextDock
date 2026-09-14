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

#[Name('store_message')]
#[Description('Append a message to an active conversation in ContextDock. Allows Qwen or other AI agents to save chat messages.')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class StoreMessage extends ContextTool
{
    protected string $ability = 'context:write';

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->integer()->min(1)->required()->description('Conversation ID to append the message to.'),
            'role' => $schema->string()->enum(['user', 'assistant', 'system', 'tool'])->required()->description('Role of the message sender.'),
            'content' => $schema->string()->max(50000)->required()->description('Text content of the message.'),
        ];
    }

    protected function execute(User $user, Request $request): array
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer', 'min:1'],
            'role' => ['required', 'string', 'in:user,assistant,system,tool'],
            'content' => ['required', 'string', 'max:50000'],
        ]);

        $message = app(ConversationService::class)->append($user, (int) $validated['conversation_id'], [
            'role' => (string) $validated['role'],
            'content' => (string) $validated['content'],
        ]);

        return [
            'message' => [
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ];
    }
}
