<?php

namespace App\Services;

use App\Data\Input;
use App\Jobs\IndexMemory;
use App\Models\{Memory, User};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MemoryService
{
    private function normalized(User $user, array $data): array
    {
        $data = Input::memory($user, $data);
        $scope = $data['scope'];
        $resolver = app(ScopeResolver::class);
        if (in_array($scope, ['project','session'])) {
            if (empty($data['project_id'])) { throw ValidationException::withMessages(['project_id'=>'Seleziona un progetto.']); }
            $project = $resolver->project($user, (int) $data['project_id']);
            if (! empty($data['workspace_id']) && (int) $data['workspace_id'] !== $project->workspace_id) {
                throw ValidationException::withMessages(['workspace_id'=>'Workspace non coerente con il progetto.']);
            }
            $data['workspace_id'] = $project->workspace_id;
        } elseif ($scope === 'workspace') {
            if (empty($data['workspace_id'])) { throw ValidationException::withMessages(['workspace_id'=>'Seleziona un workspace.']); }
            $resolver->workspace($user, (int) $data['workspace_id']);
            $data['project_id'] = null;
        } else { $data['workspace_id'] = $data['project_id'] = null; }
        if ($scope === 'session') {
            if (empty($data['conversation_id'])) { throw ValidationException::withMessages(['conversation_id'=>'Seleziona una conversazione.']); }
            $resolver->conversation($user, (int) $data['conversation_id'], (int) $data['project_id']);
        } else { $data['conversation_id'] = null; }
        return $data;
    }

    public function store(User $user, array $data): Memory
    {
        $data = $this->normalized($user, $data);
        return DB::transaction(function () use ($user, $data) {
            $memory = Memory::create($data + ['user_id'=>$user->id,'content_hash'=>hash('sha256', $data['title']."\n".$data['content'])]);
            IndexMemory::dispatch($memory->id, $memory->content_hash)->afterCommit();
            app(AuditService::class)->record($user, 'memory.created', 'memory', $memory->id);
            return $memory;
        });
    }

    public function update(User $user, int $id, array $data): Memory
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $memory = Memory::where('user_id',$user->id)->lockForUpdate()->findOrFail($id);
            $normalized = $this->normalized($user, array_merge($memory->toArray(), $data));
            $hash = hash('sha256', $normalized['title']."\n".$normalized['content']);
            if ($hash !== $memory->content_hash) {
                $normalized += ['embedding'=>null, 'embedding_model'=>null,'embedding_status'=>'pending','content_hash'=>$hash];
            }
            $memory->update($normalized);
            if ($memory->embedding_status === 'pending') { IndexMemory::dispatch($id, $hash)->afterCommit(); }
            app(AuditService::class)->record($user, 'memory.updated', 'memory', $id);
            return $memory->refresh();
        });
    }

    public function delete(User $user, int $id): void
    {
        Memory::where('user_id', $user->id)->findOrFail($id)->delete();
        app(AuditService::class)->record($user, 'memory.deleted', 'memory', $id);
    }

    public function reindex(User $user, int $id): void
    {
        $memory = Memory::where('user_id', $user->id)->findOrFail($id);
        $memory->update(['embedding'=>null,'embedding_status'=>'pending']);
        IndexMemory::dispatch($id, $memory->content_hash);
    }

    public function scope(User $user, int $projectId, ?int $conversationId = null): Builder
    {
        $project = app(ScopeResolver::class)->project($user, $projectId);
        if ($conversationId) { app(ScopeResolver::class)->conversation($user, $conversationId, $projectId); }
        return Memory::where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at','>',now()))
            ->where(function ($q) use ($project, $conversationId) {
                $q->where('scope','global')
                    ->orWhere(fn ($q) => $q->where('scope','workspace')->where('workspace_id',$project->workspace_id))
                    ->orWhere(fn ($q) => $q->where('scope','project')->where('project_id',$project->id));
                if ($conversationId) { $q->orWhere(fn ($q) => $q->where('scope','session')->where('conversation_id',$conversationId)->where('project_id',$project->id)); }
            });
    }

    public function listing(User $user, array $filters): Builder
    {
        return Memory::where('user_id', $user->id)
            ->when($filters['project_id'] ?? null, fn ($q,$id) => $q->where('project_id',$id))
            ->when($filters['type'] ?? null, fn ($q,$type) => $q->where('type',$type))
            ->when($filters['search'] ?? $filters['query'] ?? null, fn ($q,$s) => $q->where(fn ($q) => $q->where('title','ilike','%'.$s.'%')->orWhere('content','ilike','%'.$s.'%')))
            ->when($filters['pinned'] ?? false, fn ($q) => $q->where('is_pinned',true))
            ->orderByDesc('is_pinned')->orderByDesc('updated_at');
    }
}
