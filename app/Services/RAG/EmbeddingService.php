<?php

namespace App\Services\RAG;

use Laravel\Ai\Embeddings;
use RuntimeException;

class EmbeddingService
{
    /**
     * Embed the given texts with the configured RAG provider, model, and dimensions.
     *
     * @param  list<string>  $texts
     * @return list<array<int, float>>
     *
     * @throws RuntimeException if the provider returns the wrong number of vectors or dimensions.
     */
    public function embed(array $texts): array
    {
        $provider = $this->provider();
        $model = $this->model();
        $dimensions = $this->dimensions();

        $embeddings = Embeddings::for($texts)
            ->dimensions($dimensions)
            ->generate($provider, $model)
            ->embeddings;

        if (count($embeddings) !== count($texts)) {
            throw new RuntimeException(sprintf(
                'Expected %d embeddings but received %d (provider %s, model %s).',
                count($texts), count($embeddings), $provider, $model,
            ));
        }

        foreach ($embeddings as $index => $embedding) {
            if (count($embedding) !== $dimensions) {
                throw new RuntimeException(sprintf(
                    'Embedding %d has %d dimensions; expected %d (provider %s, model %s).',
                    $index, count($embedding), $dimensions, $provider, $model,
                ));
            }
        }

        return $embeddings;
    }

    /**
     * Get the configured embedding provider name.
     */
    public function provider(): string
    {
        return config('rag.embedding_provider');
    }

    /**
     * Get the configured embedding model name.
     */
    public function model(): string
    {
        return config('rag.embedding_model');
    }

    /**
     * Get the configured embedding vector dimension.
     */
    public function dimensions(): int
    {
        return config('rag.embedding_dimensions');
    }
}
