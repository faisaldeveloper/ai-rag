<?php

namespace App\Services\RAG;

use Illuminate\Support\Collection;

/**
 * The answer to a question, the sources it cites, and how it was produced.
 */
final readonly class Answer
{
    /**
     * Create a new answer.
     *
     * @param  list<Citation>  $citations  Only the sources the answer cites, in label order.
     * @param  Collection<int, RetrievedChunk>  $retrievedChunks  Every chunk that was supplied to the model.
     * @param  bool  $isFallback  True when no sufficient evidence was found and the model was not called.
     */
    public function __construct(
        public string $text,
        public array $citations,
        public Collection $retrievedChunks,
        public bool $isFallback,
        public ?string $provider,
        public ?string $model,
        public int $latencyMs,
    ) {}

    /**
     * Get the retrieval metadata stored on the assistant message.
     *
     * @return array{fallback: bool, provider: ?string, model: ?string, latency_ms: int, retrieved: list<array{chunk_id: int, score: float}>, cited_chunk_ids: list<int>, citations: list<array<string, mixed>>}
     */
    public function metadata(): array
    {
        return [
            'fallback' => $this->isFallback,
            'provider' => $this->provider,
            'model' => $this->model,
            'latency_ms' => $this->latencyMs,
            'retrieved' => $this->retrievedChunks->map(fn (RetrievedChunk $chunk): array => [
                'chunk_id' => $chunk->chunkId,
                'score' => round($chunk->score, 4),
            ])->values()->all(),
            'cited_chunk_ids' => array_map(fn (Citation $citation): int => $citation->chunk->chunkId, $this->citations),
            'citations' => array_map(fn (Citation $citation): array => $citation->toArray(), $this->citations),
        ];
    }
}
