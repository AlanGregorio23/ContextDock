<?php

namespace App\Services;

use App\Models\{AuditLog, User};

class AuditService
{
    public function record(User $user, string $action, ?string $type = null, int|string|null $id = null, array $metadata = []): void
    {
        AuditLog::create(['user_id'=>$user->id,'action'=>$action,'subject_type'=>$type,'subject_id'=>$id,'metadata'=>$metadata]);
    }
}
