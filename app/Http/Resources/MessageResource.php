<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The retrieval metadata (scores, provider, model, latency) stays internal:
 * only the citations of an assistant message, and whether it is the fallback
 * answer, are exposed.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: int, role: string, content: string, fallback: bool, citations: list<array<string, mixed>>, created_at: mixed}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'fallback' => (bool) ($this->metadata['fallback'] ?? false),
            'citations' => array_map($this->citation(...), $this->metadata['citations'] ?? []),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Put a stored citation's fields back in order; jsonb does not keep key order.
     *
     * @param  array<string, mixed>  $citation
     * @return array{label: mixed, document_id: mixed, document_title: mixed, heading: mixed, page: mixed, chunk_id: mixed, score: mixed}
     */
    private function citation(array $citation): array
    {
        return [
            'label' => $citation['label'] ?? null,
            'document_id' => $citation['document_id'] ?? null,
            'document_title' => $citation['document_title'] ?? null,
            'heading' => $citation['heading'] ?? null,
            'page' => $citation['page'] ?? null,
            'chunk_id' => $citation['chunk_id'] ?? null,
            'score' => $citation['score'] ?? null,
        ];
    }
}
