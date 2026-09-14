<?php

namespace App\Services;

use App\Contracts\EmbeddingProvider;
use App\Data\Input;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RetrievalService
{
    public function __construct(private EmbeddingProvider $embeddings, private MemoryService $memories, private ContextRankingService $ranking) {}

    public function search(User $user, array $input): array
    {
        $input = Input::search($user, $input);
        $scope = $this->memories->scope($user, (int) $input['project_id'], $input['conversation_id'] ?? null);
        $vector = EmbeddingService::vector($this->embeddings->embedQuery($input['query']));
        $candidates = [];
        $cap = (int) config('contextdock.candidate_limit');
        if (($input['kind'] ?? null) !== 'document') {
            $query = (clone $scope)->where('embedding_status','ready')->where('embedding_model',$this->embeddings->model())->whereNotNull('embedding');
            // Materialize the authorized subset BEFORE computing distances: exact search, no global ANN post-filtering.
            $rows = DB::select('WITH scoped AS MATERIALIZED ('.$query->toSql().') SELECT id, title, content, scope, type, importance, confidence, is_pinned, updated_at, usage_count, source_type, source_id, 1 - (embedding <=> ?::vector) AS similarity FROM scoped ORDER BY embedding <=> ?::vector, id LIMIT ?', [...$query->getBindings(),$vector,$vector,$cap]);
            foreach ($rows as $row) { $candidates['memory:'.$row->id] = $this->ranking->rank((array) $row + ['kind'=>'memory']); }
            foreach ((clone $scope)->where('is_pinned',true)->orderByDesc('importance')->orderBy('id')->limit($cap)->get() as $pin) {
                if (! isset($candidates['memory:'.$pin->id])) { $candidates['memory:'.$pin->id] = $this->ranking->rank($pin->toArray() + ['kind'=>'memory','similarity'=>null]); }
            }
        }
        if (($input['kind'] ?? null) !== 'memory') {
            $rows = DB::select('WITH scoped AS MATERIALIZED (
                SELECT c.*, d.name AS title FROM document_chunks c JOIN documents d ON d.id = c.document_id
                WHERE d.user_id = ? AND d.project_id = ? AND d.status = ? AND c.embedding_model = ?
                ) SELECT id, document_id, position, title, content, updated_at, 1 - (embedding <=> ?::vector) AS similarity
                FROM scoped ORDER BY embedding <=> ?::vector, id LIMIT ?', [$user->id,$input['project_id'],'ready',$this->embeddings->model(),$vector,$vector,$cap]);
            foreach ($rows as $row) { $candidates['document:'.$row->id] = $this->ranking->rank((array) $row + ['kind'=>'document','is_pinned'=>false]); }
        }
        $candidates = array_values($candidates);
        usort($candidates, fn ($a,$b) => ((int) $b['is_pinned'] <=> (int) $a['is_pinned']) ?: ($b['score'] <=> $a['score']) ?: strcmp($a['kind'].':'.$a['id'], $b['kind'].':'.$b['id']));
        return ['candidates'=>$candidates, 'embedding'=>['model'=>$this->embeddings->model(),'dimensions'=>1024], 'candidate_limit'=>$cap];
    }
}
