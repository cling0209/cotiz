<?php

namespace App\Services\Embeddings;

use App\Services\GeminiClientService;

class GeminiProductEmbeddingProvider implements ProductEmbeddingProvider
{
    private const TASK_DOCUMENT = 'RETRIEVAL_DOCUMENT';

    private const TASK_QUERY = 'RETRIEVAL_QUERY';

    public function __construct(
        protected GeminiClientService $gemini,
    ) {}

    public function isAvailable(): bool
    {
        return $this->gemini->isConfigured();
    }

    public function modelId(): string
    {
        return trim((string) config('cotiz.busqueda_vectores.modelo', 'gemini-embedding-001'));
    }

    public function dimension(): int
    {
        return max(64, min(3072, (int) config('cotiz.busqueda_vectores.dimension', 768)));
    }

    public function embedDocument(string $text): array
    {
        $task = trim((string) config('cotiz.busqueda_vectores.task_document', self::TASK_DOCUMENT));

        return $this->gemini->embedir($text, $task !== '' ? $task : self::TASK_DOCUMENT);
    }

    public function embedQuery(string $text): array
    {
        $task = trim((string) config('cotiz.busqueda_vectores.task_query', self::TASK_QUERY));

        return $this->gemini->embedir($text, $task !== '' ? $task : self::TASK_QUERY);
    }
}
