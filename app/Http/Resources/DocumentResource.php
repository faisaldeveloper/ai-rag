<?php

namespace App\Http\Resources;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The storage path and the raw error message are never exposed: both can
 * reveal server paths or internals.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: int, title: string, original_filename: string, mime_type: string, checksum: string, status: string, error: ?string, created_at: mixed, updated_at: mixed}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'checksum' => $this->checksum,
            'status' => $this->status->value,
            'error' => $this->status === DocumentStatus::Failed ? 'Processing failed. The document can be retried.' : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
