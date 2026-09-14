<?php

namespace App\Jobs;

use App\Contracts\EmbeddingProvider;
use App\Models\Document;
use App\Services\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB, Storage};

class IndexDocument implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 290;
    public function __construct(public int $documentId) {}
    public function backoff(): array { return [15,60,120]; }

    public function handle(EmbeddingProvider $embeddings): void
    {
        $document = Document::find($this->documentId);
        if (! $document) { return; }
        $document->update(['status'=>'indexing','error'=>null]);
        $text = Storage::disk('documents')->get($document->path);
        // Resume idempotently after a retry; partially indexed documents remain invisible to retrieval.
        for ($start=0,$position=0; $start < mb_strlen($text); $start+=1600,$position++) {
            $content = mb_substr($text,$start,1800);
            if ($document->chunks()->where('position',$position)->where('embedding_model',$embeddings->model())->exists()) { continue; }
            $vector = EmbeddingService::vector($embeddings->embedDocument($content));
            DB::transaction(function () use ($document,$position,$content,$vector,$embeddings) {
                $existing = Document::lockForUpdate()->find($document->id);
                if (! $existing) { return; }
                $existing->chunks()->updateOrCreate(['position'=>$position],['content'=>$content,'embedding'=>$vector,'embedding_model'=>$embeddings->model()]);
            });
        }
        Document::whereKey($document->id)->update(['status'=>'ready','error'=>null]);
    }

    public function failed(?\Throwable $exception): void
    {
        Document::whereKey($this->documentId)->update(['status'=>'failed','error'=>'Indicizzazione non riuscita. Verifica Ollama e riprova.']);
    }
}
