<?php

namespace App\Services\RAG;

/**
 * One chunk of a document's text, as produced by the TextChunker.
 */
final readonly class TextChunk
{
    /**
     * Create a new chunk.
     *
     * @param  int  $index  Position of the chunk in the document, consecutive from 0.
     * @param  int  $tokenCount  Estimated token count (characters / 4, rounded up).
     * @param  string|null  $heading  Trail of the Markdown headings the chunk sits under, e.g. "Guide > Install".
     */
    public function __construct(
        public int $index,
        public string $content,
        public int $tokenCount,
        public ?string $heading = null,
    ) {}

    /**
     * Get the metadata stored alongside the chunk.
     *
     * @return array{heading: ?string}
     */
    public function metadata(): array
    {
        return ['heading' => $this->heading];
    }
}
