<?php

namespace App\Services\RAG;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\AiException;

/**
 * Turns a stored document into embedded, persisted chunks.
 *
 * It does not change the document's status; the ProcessDocument job owns that.
 */
class DocumentIngestionService
{
    /**
     * Create a new ingestion service.
     */
    public function __construct(
        private DocumentTextExtractor $extractor,
        private TextChunker $chunker,
        private EmbeddingService $embeddings,
    ) {}

    /**
     * Extract, chunk, and embed the document, then replace its chunks.
     *
     * The provider is called before the transaction opens, so a failed or slow
     * provider never holds a database transaction and never touches the
     * existing chunks. Running it again for the same document is safe.
     *
     * @return int The number of chunks stored.
     *
     * @throws IngestionException if the document has no usable text.
     * @throws EmbeddingException if the provider returns unexpected vectors.
     * @throws AiException if the provider request fails.
     */
    public function ingest(Document $document): int
    {
        $chunks = $this->chunker->chunk($this->extractor->extract($document));

        if ($chunks === []) {
            throw new IngestionException('The document contains no text.');
        }

        $vectors = $this->embeddings->embed(array_map(fn (TextChunk $chunk): string => $chunk->content, $chunks));

        DB::transaction(function () use ($document, $chunks, $vectors): void {
            DocumentChunk::query()->whereBelongsTo($document)->delete();

            $document->chunks()->createMany(array_map(fn (TextChunk $chunk): array => [
                'chunk_index' => $chunk->index,
                'content' => $chunk->content,
                'embedding' => $vectors[$chunk->index],
                'token_count' => $chunk->tokenCount,
                'metadata' => $chunk->metadata(),
            ], $chunks));

            $document->update([
                'embedding_provider' => $this->embeddings->provider(),
                'embedding_model' => $this->embeddings->model(),
            ]);
        });

        return count($chunks);
    }
}
