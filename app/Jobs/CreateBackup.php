<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AuditService;
use App\Services\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CreateBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(public int $userId) {}

    public function handle(BackupService $backupService): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $backupService->createForUser($user);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('Backup job failed.', ['user_id' => $this->userId, 'error' => $exception?->getMessage()]);
        $user = User::find($this->userId);
        if ($user) {
            app(AuditService::class)->record($user, 'backup.failed', 'backup', null, [
                'error' => 'Creazione del backup non riuscita.',
            ]);
        }
    }
}
