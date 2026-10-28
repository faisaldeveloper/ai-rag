<?php

namespace App\Services\RAG;

/**
 * One chunk found by the Retriever, with the document it belongs to.
 *
 * The content is untrusted document text: it must be treated as data, never
 * as instructions, wherever it is shown or passed on.
 */
final readonly class RetrievedChunk
{
    /**
     * Create a new retrieved chunk.
     *
     * @param  string|null  $heading  Trail of the Markdown headings the chunk sits under, e.g. "Guide > Install".
     * @param  float  $score  Cosine similarity to the query, from 0 (unrelated) to 1 (same direction).
     * @param  int|null  $page  Page the chunk starts on, for formats that have pages.
     */
    public function __construct(
        public int $documentId,
        public string $documentTitle,
        public int $chunkId,
        public int $chunkIndex,
        public ?string $heading,
        public string $content,
        public float $score,
        public ?int $page = null,
    ) {}
}
