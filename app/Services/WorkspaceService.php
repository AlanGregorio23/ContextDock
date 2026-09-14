<?php

namespace App\Services;

use App\Models\{User, Workspace, Project};
use Illuminate\Support\Facades\Validator;

class WorkspaceService
{
    public function createWorkspace(User $user, array $data): Workspace
    {
        $data = Validator::make($data, ['name'=>'required|string|max:100'])->validate();
        $workspace = Workspace::create($data + ['user_id'=>$user->id]);
        app(AuditService::class)->record($user, 'workspace.created', 'workspace', $workspace->id);
        return $workspace;
    }

    public function createProject(User $user, array $data): Project
    {
        $data = Validator::make($data, ['workspace_id'=>'required|integer','name'=>'required|string|max:100','description'=>'nullable|string|max:4000'])->validate();
        app(ScopeResolver::class)->workspace($user, $data['workspace_id']);
        $project = Project::create($data + ['user_id'=>$user->id]);
        app(AuditService::class)->record($user, 'project.created', 'project', $project->id);
        return $project;
    }
}
