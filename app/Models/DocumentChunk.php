<?php

namespace App\Models;

use Database\Factories\DocumentChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_id', 'chunk_index', 'content', 'embedding', 'token_count', 'metadata'])]
class DocumentChunk extends Model
{
    /** @use HasFactory<DocumentChunkFactory> */
    use HasFactory;

    /**
     * Get the document the chunk belongs to.
     *
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => AsVector::class,
            'metadata' => 'array',
        ];
    }
}
