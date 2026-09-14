<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

abstract class ContextTool extends Tool
{
    protected string $ability = 'context:read';

    final public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->tokenCan($this->ability)) {
            return Response::error('Permission denied.');
        }

        try {
            return Response::structured($this->execute($user, $request));
        } catch (ValidationException $exception) {
            return Response::error('Invalid arguments: '.implode(', ', array_keys($exception->errors())).'.');
        } catch (AuthorizationException|ModelNotFoundException|HttpExceptionInterface) {
            return Response::error('Resource unavailable or permission denied.');
        } catch (Throwable $exception) {
            // Do not log SQL, request content, tokens, or exception messages.
            Log::warning('MCP tool failed.', ['tool' => $this->name(), 'exception' => $exception::class]);

            return Response::error('ContextDock could not complete this operation. Check service status and retry.');
        }
    }

    abstract protected function execute(User $user, Request $request): array;

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->min(1)->required(),
            'conversation_id' => $schema->integer()->min(1)->description('Optional conversation in this project; limits session context.'),
        ];
    }

    protected function projectInput(Request $request): array
    {
        return $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
            'conversation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);
    }
}
