<?php

namespace App\Http\Resources;

use App\Models\DocumentChunk;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A cited chunk as shown in the sources panel: never the storage path or the embedding.
 *
 * The content is untrusted document text; clients must render it as text.
 *
 * @mixin DocumentChunk
 */
class MessageSourceResource extends JsonResource
{
    /**
     * The [S#] label the answer cites the chunk under.
     */
    private string $label = '';

    /**
     * Set the [S#] label the answer cites the chunk under.
     */
    public function withLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array{label: string, document_id: int, document_title: string, chunk_id: int, chunk_index: int, heading: ?string, page: ?int, content: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'label' => $this->label,
            'document_id' => $this->document_id,
            'document_title' => $this->document_title,
            'chunk_id' => $this->id,
            'chunk_index' => $this->chunk_index,
            'heading' => $this->metadata['heading'] ?? null,
            'page' => isset($this->metadata['page']) ? (int) $this->metadata['page'] : null,
            'content' => $this->content,
        ];
    }
}
