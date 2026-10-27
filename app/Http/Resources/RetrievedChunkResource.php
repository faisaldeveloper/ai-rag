<?php

namespace App\Http\Resources;

use App\Services\RAG\RetrievedChunk;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Only what a citation needs: never the storage path or the embedding.
 *
 * @mixin RetrievedChunk
 */
class RetrievedChunkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{document_id: int, document_title: string, chunk_id: int, chunk_index: int, heading: ?string, content: string, score: float}
     */
    public function toArray(Request $request): array
    {
        return [
            'document_id' => $this->documentId,
            'document_title' => $this->documentTitle,
            'chunk_id' => $this->chunkId,
            'chunk_index' => $this->chunkIndex,
            'heading' => $this->heading,
            'content' => $this->content,
            'score' => round($this->score, 4),
        ];
    }
}
