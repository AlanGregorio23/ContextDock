<?php

namespace App\Services;

use App\Contracts\EmbeddingProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EmbeddingService implements EmbeddingProvider
{
    public function model(): string { return config('contextdock.embedding_model'); }

    public function embedQuery(string $text): array
    {
        return $this->embed(str_replace('\\n', "\n", config('contextdock.query_instruction')).$text);
    }

    public function embedDocument(string $text): array { return $this->embed($text); }

    private function embed(string $text): array
    {
        try {
            $response = Http::baseUrl(config('contextdock.ollama_url'))->connectTimeout(5)->timeout(120)
                ->post('/api/embed', ['model' => $this->model(), 'input' => $text, 'truncate' => false, 'keep_alive' => '5m']);
            if (! $response->successful()) { throw new RuntimeException; }
            $vector = $response->json('embeddings.0');
            self::validate($vector);
            return $vector;
        } catch (\Throwable $e) {
            // Do not expose upstream response bodies, document content or request payloads.
            throw new RuntimeException('Embedding locale non disponibile. Verifica Ollama e il modello qwen3-embedding:0.6b.');
        }
    }

    public static function validate(mixed $vector): void
    {
        if (! is_array($vector) || count($vector) !== 1024 || ! array_is_list($vector)) {
            throw new RuntimeException('Embedding non valido: attese 1024 dimensioni.');
        }
        $norm = 0;
        foreach ($vector as $v) {
            if (! is_numeric($v) || ! is_finite((float) $v)) { throw new RuntimeException('Embedding non finito.'); }
            $norm += (float) $v ** 2;
        }
        if ($norm <= 0) { throw new RuntimeException('Embedding nullo.'); }
    }

    public static function vector(array $values): string
    {
        self::validate($values);
        return json_encode(array_map(fn ($v) => (float) $v, $values), JSON_THROW_ON_ERROR);
    }
}
