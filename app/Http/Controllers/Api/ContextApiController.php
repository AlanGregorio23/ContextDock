<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\{ContextBuilder, ConversationService, DocumentService, MemoryService, RetrievalService};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContextApiController extends Controller
{
    public function searchMemory(Request $request, RetrievalService $retrieval): JsonResponse
    {
        $user = $request->user();
        $input = $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
            'query' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'between:1,30'],
            'budget_tokens' => ['sometimes', 'integer', 'between:512,12000'],
        ]);

        $results = $retrieval->search($user, array_merge($input, ['kind' => 'memory']));

        return response()->json($results);
    }

    public function storeMemory(Request $request, MemoryService $memoryService): JsonResponse
    {
        $user = $request->user();
        if (! $user->tokenCan('context:write') && ! $user->tokenCan('*')) {
            return response()->json(['message' => 'Permission denied: context:write ability required.'], 403);
        }

        $memory = $memoryService->store($user, $request->all());

        return response()->json([
            'message' => 'Memory stored successfully.',
            'memory' => $memory->makeHidden(['embedding']),
        ], 201);
    }

    public function buildContext(Request $request, ContextBuilder $builder): JsonResponse
    {
        $user = $request->user();
        $result = $builder->build($user, $request->all());

        return response()->json($result['pack']);
    }

    public function debugContext(Request $request, ContextBuilder $builder): JsonResponse
    {
        $user = $request->user();
        $result = $builder->build($user, $request->all());

        return response()->json([
            'pack' => $result['pack'],
            'debug' => $result['debug'],
            'run_id' => $result['run_id'],
        ]);
    }

    public function getProjectContext(Request $request, int $project, ContextBuilder $builder): JsonResponse
    {
        $user = $request->user();
        $query = (string) $request->query('query', 'Project context and active guidelines');
        $conversationId = $request->query('conversation_id');

        $result = $builder->build($user, [
            'project_id' => $project,
            'query' => $query,
            'conversation_id' => $conversationId ? (int) $conversationId : null,
            'limit' => (int) $request->query('limit', 12),
            'budget_tokens' => (int) $request->query('budget_tokens', 2400),
        ]);

        return response()->json($result['pack']);
    }

    public function storeDocument(Request $request, DocumentService $documentService): JsonResponse
    {
        $user = $request->user();
        if (! $user->tokenCan('context:write') && ! $user->tokenCan('*')) {
            return response()->json(['message' => 'Permission denied: context:write ability required.'], 403);
        }

        $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
            'document' => ['required', 'file'],
        ]);

        $document = $documentService->store($user, (int) $request->input('project_id'), $request->file('document'));

        return response()->json([
            'message' => 'Document uploaded and queued for indexing.',
            'document' => $document,
        ], 201);
    }

    public function storeConversation(Request $request, ConversationService $conversationService): JsonResponse
    {
        $user = $request->user();
        if (! $user->tokenCan('context:write') && ! $user->tokenCan('*')) {
            return response()->json(['message' => 'Permission denied: context:write ability required.'], 403);
        }

        $conversation = $conversationService->create($user, $request->all());

        return response()->json([
            'message' => 'Conversation created.',
            'conversation' => $conversation,
        ], 201);
    }

    public function appendMessage(Request $request, int $conversation, ConversationService $conversationService): JsonResponse
    {
        $user = $request->user();
        if (! $user->tokenCan('context:write') && ! $user->tokenCan('*')) {
            return response()->json(['message' => 'Permission denied: context:write ability required.'], 403);
        }

        $message = $conversationService->append($user, $conversation, $request->all());

        return response()->json([
            'message' => 'Message appended.',
            'message_item' => $message,
        ], 201);
    }

    public function getRecentMessages(Request $request, int $project, int $conversation, ConversationService $conversationService): JsonResponse
    {
        $user = $request->user();
        $limit = (int) $request->query('limit', 10);
        $messages = $conversationService->recent($user, $project, $conversation, $limit);

        return response()->json(['messages' => $messages]);
    }
}
