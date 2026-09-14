<?php

namespace App\Contracts;

interface EmbeddingProvider
{
    public function embedDocument(string $text): array;
    public function embedQuery(string $text): array;
    public function model(): string;
}
