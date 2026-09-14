<?php

namespace App\Services;

use App\Models\{Conversation, Message, User};
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ConversationService
{
    public function create(User $user, array $data): Conversation
    {
        $data = Validator::make($data,['project_id'=>'required|integer','title'=>'required|string|max:200','source'=>'sometimes|string|max:100'])->validate();
        $project = app(ScopeResolver::class)->project($user,$data['project_id']);
        return Conversation::create($data + ['user_id'=>$user->id,'workspace_id'=>$project->workspace_id]);
    }

    public function append(User $user, int $id, array $data): Message
    {
        $conversation = app(ScopeResolver::class)->conversation($user,$id);
        $data = Validator::make($data,['role'=>['required',Rule::in(['user','assistant','system','tool'])],
            'content'=>'required|string|max:50000'])->validate();
        $message = $conversation->messages()->create($data);
        $conversation->touch();
        app(AuditService::class)->record($user,'message.appended','conversation',$id);
        return $message;
    }

    public function recent(User $user, int $projectId, ?int $conversationId = null, int $limit = 10): array
    {
        app(ScopeResolver::class)->project($user,$projectId);
        if (! $conversationId) { return []; }
        $conversation = app(ScopeResolver::class)->conversation($user,$conversationId,$projectId);
        return $conversation->messages()->latest('id')->limit(min(20,max(1,$limit)))->get(['id','role','content','created_at'])->reverse()->values()->toArray();
    }
}
