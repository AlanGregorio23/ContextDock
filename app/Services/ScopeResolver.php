<?php

namespace App\Services;

use App\Models\{User, Project, Workspace, Conversation};

class ScopeResolver
{
    public function project(User $user, int $id): Project
    {
        return Project::where('user_id', $user->id)->findOrFail($id);
    }

    public function workspace(User $user, int $id): Workspace
    {
        return Workspace::where('user_id', $user->id)->findOrFail($id);
    }

    public function conversation(User $user, int $id, ?int $projectId = null): Conversation
    {
        return Conversation::where('user_id', $user->id)->when($projectId, fn ($q) => $q->where('project_id', $projectId))->findOrFail($id);
    }
}
