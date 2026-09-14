<?php

use App\Models\AuditLog;
use App\Models\ContextRun;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::call(function () {
    $contextDays = (int) config('contextdock.context_retention_days', 30);
    ContextRun::where('created_at', '<', now()->subDays($contextDays))->delete();

    $auditDays = (int) config('contextdock.audit_retention_days', 90);
    AuditLog::where('created_at', '<', now()->subDays($auditDays))->delete();
})->daily()->name('contextdock:prune-retention');
