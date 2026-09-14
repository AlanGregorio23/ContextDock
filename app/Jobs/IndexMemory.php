<?php

namespace App\Jobs;

use App\Contracts\EmbeddingProvider;
use App\Models\Memory;
use App\Services\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IndexMemory implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 150;
    public function __construct(public int $memoryId, public string $hash) {}
    public function backoff(): array { return [10,30,60]; }

    public function handle(EmbeddingProvider $embeddings): void
    {
        $memory = Memory::find($this->memoryId);
        if (! $memory || $memory->content_hash !== $this->hash) { return; }
        $vector = $embeddings->embedDocument($memory->title."\n".$memory->content);
        Memory::whereKey($this->memoryId)->where('content_hash',$this->hash)->update([
            'embedding'=>EmbeddingService::vector($vector),'embedding_model'=>$embeddings->model(),'embedding_status'=>'ready',
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Memory::whereKey($this->memoryId)->where('content_hash',$this->hash)->update(['embedding_status'=>'failed']);
    }
}
