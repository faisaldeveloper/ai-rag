<?php

namespace App\Services\RAG;

use Laravel\Ai\Embeddings;

class EmbeddingService
{
    /**
     * Embed the given texts with the configured RAG provider, model, and dimensions.
     *
     * Texts are sent to the provider in batches of the configured batch size;
     * the returned vectors are in the same order as the texts.
     *
     * @param  list<string>  $texts
     * @return list<array<int, float>>
     *
     * @throws EmbeddingException if the provider returns the wrong number of vectors or dimensions.
     */
    public function embed(array $texts): array
    {
        $embeddings = [];

        foreach (array_chunk($texts, $this->batchSize()) as $batch) {
            array_push($embeddings, ...$this->embedBatch($batch, count($embeddings)));
        }

        return $embeddings;
    }

    /**
     * Embed one batch of texts and verify the response.
     *
     * @param  list<string>  $texts
     * @param  int  $offset  Position of the batch's first text among all texts, for error messages.
     * @return list<array<int, float>>
     *
     * @throws EmbeddingException if the provider returns the wrong number of vectors or dimensions.
     */
    private function embedBatch(array $texts, int $offset): array
    {
        $provider = $this->provider();
        $model = $this->model();
        $dimensions = $this->dimensions();

        $embeddings = Embeddings::for($texts)
            ->dimensions($dimensions)
            ->generate($provider, $model)
            ->embeddings;

        if (count($embeddings) !== count($texts)) {
            throw new EmbeddingException(sprintf(
                'Expected %d embeddings but received %d (provider %s, model %s).',
                count($texts), count($embeddings), $provider, $model,
            ));
        }

        foreach (array_values($embeddings) as $index => $embedding) {
            if (count($embedding) !== $dimensions) {
                throw new EmbeddingException(sprintf(
                    'Embedding %d has %d dimensions; expected %d (provider %s, model %s).',
                    $offset + $index, count($embedding), $dimensions, $provider, $model,
                ));
            }
        }

        return array_values($embeddings);
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

    /**
     * Get the maximum number of texts sent to the provider in one request.
     */
    public function batchSize(): int
    {
        return max(1, (int) config('rag.embedding_batch_size', 32));
    }
}
