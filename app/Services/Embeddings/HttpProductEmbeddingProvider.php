<?php

namespace App\Services\Embeddings;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpProductEmbeddingProvider implements ProductEmbeddingProvider
{
    public function isAvailable(): bool
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return false;
        }

        try {
            $response = Http::timeout(5)->get($base.'/health');

            return $response->successful() && ($response->json('ok') === true);
        } catch (\Throwable) {
            return false;
        }
    }

    public function modelId(): string
    {
        return trim((string) config('cotiz.busqueda_vectores.modelo', 'intfloat/multilingual-e5-small'));
    }

    public function dimension(): int
    {
        return max(64, min(3072, (int) config('cotiz.busqueda_vectores.dimension', 384)));
    }

    public function embedDocument(string $text): array
    {
        return $this->requestEmbed($text, 'document');
    }

    public function embedQuery(string $text): array
    {
        return $this->requestEmbed($text, 'query');
    }

    /**
     * @return list<float>
     */
    private function requestEmbed(string $text, string $task): array
    {
        $base = $this->baseUrl();
        if ($base === '') {
            throw new RuntimeException('COTIZ_EMBEDDING_URL no configurada.');
        }

        $timeout = max(5, min(120, (int) config('cotiz.busqueda_vectores.timeout_seg', 30)));
        $response = Http::timeout($timeout)
            ->acceptJson()
            ->post($base.'/embed', [
                'text' => $text,
                'task' => $task,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Embedding HTTP '.$response->status().': '.$response->body());
        }

        $values = $response->json('values');
        if (! is_array($values) || $values === []) {
            throw new RuntimeException('Respuesta de embedding sin values.');
        }

        return array_map(static fn ($v) => (float) $v, $values);
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) config('cotiz.busqueda_vectores.url', '')), '/');
    }
}
