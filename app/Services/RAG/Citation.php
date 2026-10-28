<?php

namespace App\Services\RAG;

/**
 * A source the answer actually cites, identified by its [S#] label.
 */
final readonly class Citation
{
    /**
     * Create a new citation.
     */
    public function __construct(
        public string $label,
        public RetrievedChunk $chunk,
    ) {}

    /**
     * Get the citation as stored on the message and returned by the API; never the chunk text.
     *
     * @return array{label: string, document_id: int, document_title: string, heading: ?string, page: ?int, chunk_id: int, score: float}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'document_id' => $this->chunk->documentId,
            'document_title' => $this->chunk->documentTitle,
            'heading' => $this->chunk->heading,
            'page' => $this->chunk->page,
            'chunk_id' => $this->chunk->chunkId,
            'score' => round($this->chunk->score, 4),
        ];
    }
}
