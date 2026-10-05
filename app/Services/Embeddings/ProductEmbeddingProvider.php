<?php

namespace App\Services\Embeddings;

interface ProductEmbeddingProvider
{
    public function isAvailable(): bool;

    public function modelId(): string;

    public function dimension(): int;

    /**
     * @return list<float>
     */
    public function embedDocument(string $text): array;

    /**
     * @return list<float>
     */
    public function embedQuery(string $text): array;
}
