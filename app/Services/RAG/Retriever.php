<?php

namespace App\Services\RAG;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use Illuminate\Support\Collection;
use Laravel\Ai\Exceptions\AiException;

/**
 * Finds the document chunks most similar to a question.
 *
 * Authorization is part of the SQL: chunks are joined to the documents the
 * user may search (Document::searchable()), so a chunk of any other document
 * is never loaded, scored, or returned.
 */
class Retriever
{
    /**
     * The most chunks a single search may return.
     */
    public const MaxLimit = 20;

    /**
     * Create a new retriever.
     */
    public function __construct(private EmbeddingService $embeddings) {}

    /**
     * Embed the query and return the chunks most similar to it, best first.
     *
     * @param  int|null  $limit  Defaults to rag.top_k; clamped to 1..MaxLimit.
     * @param  float|null  $minScore  Defaults to rag.min_score; clamped to 0..1.
     * @return Collection<int, RetrievedChunk>
     *
     * @throws EmbeddingException if the provider returns unexpected vectors.
     * @throws AiException if the provider request fails.
     */
    public function search(User $user, string $query, ?int $limit = null, ?float $minScore = null): Collection
    {
        if (trim($query) === '') {
            return new Collection;
        }

        return $this->searchByVector($user, $this->embeddings->embed([$query])[0], $limit, $minScore);
    }

    /**
     * Return the chunks most similar to an already embedded query, best first.
     *
     * The user is not used to filter yet because the knowledge base is shared;
     * it is required so per-user rules can be added to the query in one place.
     *
     * @param  array<int, float>  $vector
     * @param  int|null  $limit  Defaults to rag.top_k; clamped to 1..MaxLimit.
     * @param  float|null  $minScore  Defaults to rag.min_score; clamped to 0..1.
     * @return Collection<int, RetrievedChunk>
     */
    public function searchByVector(User $user, array $vector, ?int $limit = null, ?float $minScore = null): Collection
    {
        $limit = max(1, min(self::MaxLimit, $limit ?? (int) config('rag.top_k', 5)));
        $minScore = max(0.0, min(1.0, $minScore ?? (float) config('rag.min_score', 0.0)));

        return DocumentChunk::query()
            ->joinSub(
                Document::query()->searchable()->select(['id', 'title']),
                'searchable_documents',
                'searchable_documents.id',
                '=',
                'document_chunks.document_id',
            )
            ->select([
                'document_chunks.id',
                'document_chunks.document_id',
                'document_chunks.chunk_index',
                'document_chunks.content',
                'document_chunks.metadata',
                'searchable_documents.title as document_title',
            ])
            ->selectVectorDistance('document_chunks.embedding', $vector, 'distance')
            ->whereVectorSimilarTo('document_chunks.embedding', $vector, $minScore)
            ->orderBy('document_chunks.id')
            ->limit($limit)
            ->get()
            ->map(fn (DocumentChunk $chunk): RetrievedChunk => new RetrievedChunk(
                documentId: $chunk->document_id,
                documentTitle: $chunk->document_title,
                chunkId: $chunk->id,
                chunkIndex: $chunk->chunk_index,
                heading: $chunk->metadata['heading'] ?? null,
                content: $chunk->content,
                score: max(0.0, min(1.0, 1 - (float) $chunk->distance)),
            ))
            ->toBase();
    }
}
