<?php

namespace App\Services;

use App\Jobs\CreateBackup;
use App\Models\{Conversation, Document, Memory, Message, Project, User, Workspace};
use Illuminate\Support\Facades\Storage;

class BackupService
{
    public function dispatch(User $user): void
    {
        CreateBackup::dispatch($user->id);
        app(AuditService::class)->record($user, 'backup.requested', 'backup');
    }

    public function createForUser(User $user): string
    {
        $timestamp = now()->format('Y-m-d_His');
        $backupDir = (string) $user->id;
        $filename = "backup_{$user->id}_{$timestamp}.json";
        $path = "{$backupDir}/{$filename}";

        $data = [
            'version' => '1.0',
            'exported_at' => now()->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'workspaces' => Workspace::where('user_id', $user->id)->get()->toArray(),
            'projects' => Project::where('user_id', $user->id)->get()->toArray(),
            'memories' => Memory::where('user_id', $user->id)->get()->makeHidden(['embedding'])->toArray(),
            'conversations' => Conversation::where('user_id', $user->id)->get()->toArray(),
            'messages' => Message::whereIn('conversation_id', function ($query) use ($user) {
                $query->select('id')->from('conversations')->where('user_id', $user->id);
            })->get()->toArray(),
            'documents' => Document::where('user_id', $user->id)->get()->toArray(),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Storage::disk('backups')->put($path, $json);

        $size = strlen($json);

        $this->pruneOldBackups($user);

        app(AuditService::class)->record($user, 'backup.created', 'backup', $filename, [
            'path' => $path,
            'size_bytes' => $size,
            'records' => [
                'workspaces' => count($data['workspaces']),
                'projects' => count($data['projects']),
                'memories' => count($data['memories']),
                'conversations' => count($data['conversations']),
                'messages' => count($data['messages']),
                'documents' => count($data['documents']),
            ],
        ]);

        return $path;
    }

    public function pruneOldBackups(User $user): void
    {
        $retention = (int) config('contextdock.backup_retention_count', 7);
        $files = Storage::disk('backups')->files((string) $user->id);

        if (count($files) > $retention) {
            rsort($files);
            $toDelete = array_slice($files, $retention);
            foreach ($toDelete as $oldFile) {
                Storage::disk('backups')->delete($oldFile);
            }
        }
    }
}
