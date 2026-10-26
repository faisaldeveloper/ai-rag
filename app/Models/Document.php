<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * The status is deliberately not fillable: it only changes through transitionTo().
 */
#[Fillable([
    'user_id',
    'title',
    'original_filename',
    'storage_path',
    'mime_type',
    'checksum',
    'embedding_provider',
    'embedding_model',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * The validation message returned when a file that is already in the knowledge base is uploaded.
     */
    public const DuplicateMessage = 'This file has already been uploaded.';

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => DocumentStatus::Pending->value,
    ];

    /**
     * Get the user who uploaded the document.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the document's chunks in reading order.
     *
     * @return HasMany<DocumentChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class)->orderBy('chunk_index');
    }

    /**
     * Limit the query to documents every authenticated user may search.
     *
     * The knowledge base is shared, so this is every completed document. It is
     * the single place retrieval applies authorization, inside the query.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function searchable(Builder $query): void
    {
        $query->where('status', DocumentStatus::Completed);
    }

    /**
     * Move the document to the given status, persisting it immediately.
     *
     * The update only applies while the stored status still matches this
     * instance's status, so two workers racing on the same document cannot
     * both perform the same transition. The error message is kept only for
     * the failed status and cleared otherwise.
     *
     * @throws LogicException if the transition is not allowed or the stored status changed.
     */
    public function transitionTo(DocumentStatus $status, ?string $errorMessage = null): void
    {
        $current = $this->status;

        if (! $current->canTransitionTo($status)) {
            throw new LogicException(sprintf(
                'Document %d cannot transition from [%s] to [%s].',
                $this->getKey(), $current->value, $status->value,
            ));
        }

        $updated = static::query()
            ->whereKey($this->getKey())
            ->where('status', $current)
            ->update([
                'status' => $status,
                'error_message' => $status === DocumentStatus::Failed ? $errorMessage : null,
            ]);

        if ($updated !== 1) {
            throw new LogicException(sprintf(
                'Document %d is no longer [%s]; it was changed by another process.',
                $this->getKey(), $current->value,
            ));
        }

        $this->refresh();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
        ];
    }
}
