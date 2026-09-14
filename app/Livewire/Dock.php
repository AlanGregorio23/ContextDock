<?php

namespace App\Livewire;

use App\Models\{AuditLog, ContextRun, Conversation, Document, Memory, Message, Project, Workspace};
use App\Services\{BackupService, ContextBuilder, ConversationService, DocumentService, MemoryService, WorkspaceService};
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Dock extends Component
{
    use WithFileUploads, WithPagination;

    public const SECTIONS = ['dashboard', 'workspaces', 'projects', 'memories', 'conversations', 'documents', 'inspector', 'integrations', 'jobs', 'logs', 'backups', 'settings'];
    public const TYPES = ['preference', 'fact', 'decision', 'instruction', 'task', 'summary', 'note', 'architecture', 'entity', 'temporary'];

    #[Locked]
    public string $section = 'dashboard';
    public string $search = '';
    public string $projectId = '';
    public string $workspaceId = '';
    public string $conversationId = '';
    public string $workspaceName = '';
    public string $projectName = '';
    public string $projectDescription = '';
    public string $conversationTitle = '';
    public string $messageRole = 'user';
    public string $messageContent = '';
    public array $memory = ['scope' => 'project', 'type' => 'note', 'title' => '', 'content' => '', 'summary' => '', 'importance' => 0.5, 'confidence' => 1, 'is_pinned' => false, 'expires_at' => ''];
    #[Locked]
    public ?int $editingMemoryId = null;
    public $upload;
    public string $query = '';
    public int $resultLimit = 12;
    public int $budgetTokens = 2400;
    #[Locked]
    public array $contextResult = [];
    public string $tokenName = '';
    public bool $tokenWrite = false;
    #[Locked]
    public string $newToken = '';
    public string $notice = '';

    public function mount(string $section = 'dashboard'): void
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        $this->section = $section;
        $this->projectId = (string) (Project::where('user_id', auth()->id())->orderBy('name')->value('id') ?? '');
        $this->workspaceId = (string) (Workspace::where('user_id', auth()->id())->orderBy('name')->value('id') ?? '');
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedProjectId(): void
    {
        $this->conversationId = (string) (Conversation::where('user_id', auth()->id())
            ->when($this->projectId !== '', fn ($q) => $q->where('project_id', $this->projectId))
            ->latest('id')
            ->value('id') ?? '');
        $this->contextResult = [];
        $this->resetPage();
    }

    private function perform(callable $action, string $message): void
    {
        $this->resetErrorBag();
        $this->notice = '';
        try {
            $action();
            $this->notice = $message;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('operation', 'Operazione non completata. Controlla i dati e riprova; se il problema continua consulta i log del server.');
        }
    }

    public function createWorkspace(): void
    {
        $this->perform(function () {
            $workspace = app(WorkspaceService::class)->createWorkspace(auth()->user(), ['name' => $this->workspaceName]);
            $this->workspaceId = (string) $workspace->id;
            $this->workspaceName = '';
        }, 'Workspace creato.');
    }

    public function createProject(): void
    {
        $this->perform(function () {
            $project = app(WorkspaceService::class)->createProject(auth()->user(), ['workspace_id' => $this->workspaceId, 'name' => $this->projectName, 'description' => $this->projectDescription]);
            $this->projectId = (string) $project->id;
            $this->projectName = $this->projectDescription = '';
        }, 'Progetto creato. Puoi aggiungere memorie e documenti.');
    }

    public function saveMemory(): void
    {
        $this->perform(function () {
            $data = $this->memory + ['source_type' => 'manual'];
            $data['workspace_id'] = $this->memory['scope'] === 'workspace' ? ($this->workspaceId ?: null) : null;
            $data['project_id'] = in_array($this->memory['scope'], ['project', 'session'], true) ? ($this->projectId ?: null) : null;
            $data['conversation_id'] = $this->memory['scope'] === 'session' ? ($this->conversationId ?: null) : null;
            $data['expires_at'] = $data['expires_at'] ?: null;
            $service = app(MemoryService::class);
            if ($this->editingMemoryId) {
                $service->update(auth()->user(), $this->editingMemoryId, $data);
            } else {
                $service->store(auth()->user(), $data);
            }
            $this->cancelEdit();
        }, 'Memoria salvata. Indicizzazione locale in coda.');
    }

    public function editMemory(int $id): void
    {
        $row = Memory::where('user_id', auth()->id())->findOrFail($id);
        $this->editingMemoryId = $row->id;
        foreach (array_keys($this->memory) as $key) {
            $this->memory[$key] = $key === 'expires_at' ? ($row->expires_at?->format('Y-m-d\TH:i') ?? '') : ($row->{$key} ?? '');
        }
        $this->projectId = (string) ($row->project_id ?? $this->projectId);
        $this->workspaceId = (string) ($row->workspace_id ?? $this->workspaceId);
        $this->conversationId = (string) ($row->conversation_id ?? '');
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->editingMemoryId = null;
        $this->reset('memory');
    }

    public function pinMemory(int $id): void
    {
        $this->perform(function () use ($id) {
            $row = Memory::where('user_id', auth()->id())->findOrFail($id);
            app(MemoryService::class)->update(auth()->user(), $id, ['is_pinned' => ! $row->is_pinned]);
        }, 'Pin aggiornato.');
    }

    public function deleteMemory(int $id): void
    {
        $this->perform(fn () => app(MemoryService::class)->delete(auth()->user(), $id), 'Memoria eliminata.');
        if ($this->editingMemoryId === $id) { $this->cancelEdit(); }
    }

    public function reindexMemory(int $id): void
    {
        $this->perform(fn () => app(MemoryService::class)->reindex(auth()->user(), $id), 'Reindicizzazione richiesta.');
    }

    public function createConversation(): void
    {
        $this->perform(function () {
            $row = app(ConversationService::class)->create(auth()->user(), ['project_id' => $this->projectId, 'title' => $this->conversationTitle, 'source' => 'manual']);
            $this->conversationId = (string) $row->id;
            $this->conversationTitle = '';
        }, 'Conversazione creata.');
    }

    public function appendMessage(): void
    {
        $this->perform(function () {
            app(ConversationService::class)->append(auth()->user(), (int) $this->conversationId, ['role' => $this->messageRole, 'content' => $this->messageContent]);
            $this->messageContent = '';
        }, 'Messaggio RAW archiviato.');
    }

    public function storeDocument(): void
    {
        $this->validate(['upload' => 'required|file|max:10240']);
        $this->perform(function () {
            app(DocumentService::class)->store(auth()->user(), (int) $this->projectId, $this->upload);
            $this->reset('upload');
        }, 'Documento caricato. Indicizzazione in coda.');
    }

    public function deleteDocument(int $id): void
    {
        $this->perform(fn () => app(DocumentService::class)->delete(auth()->user(), $id), 'Documento eliminato.');
    }

    public function buildContext(): void
    {
        $this->contextResult = [];
        $this->perform(function () {
            $this->contextResult = app(ContextBuilder::class)->build(auth()->user(), ['project_id' => $this->projectId, 'conversation_id' => $this->conversationId ?: null, 'query' => $this->query, 'limit' => $this->resultLimit, 'budget_tokens' => $this->budgetTokens]);
        }, 'Context Pack pronto: puoi esaminarlo e copiarlo.');
    }

    public function createToken(): void
    {
        $this->validate(['tokenName' => 'required|string|max:80']);
        $this->perform(function () {
            $abilities = $this->tokenWrite ? ['context:read', 'context:write'] : ['context:read'];
            $this->newToken = auth()->user()->createToken($this->tokenName, $abilities, now()->addDays(30))->plainTextToken;
            $this->tokenName = '';
        }, 'Token creato. Copialo ora: non verrà mostrato dopo aver lasciato questa pagina.');
    }

    public function dismissToken(): void { $this->newToken = ''; }

    public function revokeToken(int $id): void
    {
        $this->perform(fn () => auth()->user()->tokens()->findOrFail($id)->delete(), 'Token revocato.');
        $this->newToken = '';
    }

    public function dispatchBackup(): void
    {
        $this->perform(fn () => app(BackupService::class)->dispatch(auth()->user()), 'Backup richiesto. Controlla l’esito nel registro attività.');
    }

    public function render()
    {
        $owner = auth()->id();
        $projects = Project::where('user_id', $owner)->orderBy('name')->limit(50)->get();
        $workspaces = Workspace::where('user_id', $owner)->orderBy('name')->limit(50)->get();
        $conversations = Conversation::where('user_id', $owner)->when($this->projectId !== '', fn ($q) => $q->where('project_id', $this->projectId))->withCount('messages')->latest()->limit(50)->get();
        if ($this->conversationId === '' && $conversations->isNotEmpty()) {
            $this->conversationId = (string) $conversations->first()->id;
        }
        $rows = null;
        $messages = collect();
        $stats = [];
        if ($this->section === 'dashboard') {
            $stats = ['Progetti' => Project::where('user_id', $owner)->count(), 'Memorie' => Memory::where('user_id', $owner)->count(), 'Documenti' => Document::where('user_id', $owner)->count(), 'Context Pack' => ContextRun::where('user_id', $owner)->count()];
            $rows = AuditLog::where('user_id', $owner)->latest('created_at')->limit(8)->get();
        } elseif ($this->section === 'memories') {
            $rows = Memory::where('user_id', $owner)->when($this->projectId !== '', fn ($q) => $q->where(fn ($nested) => $nested->where('project_id', $this->projectId)->orWhereNull('project_id')))->when($this->search !== '', fn ($q) => $q->where(fn ($nested) => $nested->where('title', 'ilike', '%'.$this->search.'%')->orWhere('content', 'ilike', '%'.$this->search.'%')))->orderByDesc('is_pinned')->latest()->paginate(15);
        } elseif ($this->section === 'documents') {
            $rows = Document::where('user_id', $owner)->when($this->projectId !== '', fn ($q) => $q->where('project_id', $this->projectId))->when($this->search !== '', fn ($q) => $q->where('name', 'ilike', '%'.$this->search.'%'))->latest()->paginate(15);
        } elseif ($this->section === 'conversations' && $this->conversationId !== '') {
            $conversation = Conversation::where('user_id', $owner)->find($this->conversationId);
            if ($conversation) { $messages = Message::where('conversation_id', $conversation->id)->latest('id')->limit(50)->get()->reverse(); }
        } elseif (in_array($this->section, ['logs', 'backups'], true)) {
            $rows = AuditLog::where('user_id', $owner)->when($this->section === 'backups', fn ($q) => $q->where('action', 'like', 'backup%'))->when($this->search !== '', fn ($q) => $q->where('action', 'ilike', '%'.$this->search.'%'))->latest('created_at')->paginate(20);
        } elseif ($this->section === 'integrations') {
            $rows = auth()->user()->tokens()->latest()->paginate(15);
        } elseif ($this->section === 'jobs') {
            $rows = Memory::where('user_id', $owner)->where('embedding_status', '!=', 'ready')->latest()->paginate(20);
            $stats = Document::where('user_id', $owner)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        }

        return view('livewire.dock', compact('projects', 'workspaces', 'conversations', 'rows', 'messages', 'stats'))->layout('components.layouts.app');
    }
}
