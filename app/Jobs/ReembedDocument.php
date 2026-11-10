<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Jobs\Concerns\ReplacesUnexpectedFailures;
use App\Models\Document;
use App\Services\RAG\DocumentIngestionService;
use App\Services\RAG\EmbeddingService;
use App\Services\RAG\ProviderFailureLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-embeds a completed document with the current embedding settings.
 *
 * The document stays completed throughout, so the status machine needs no
 * completed -> pending transition and the document never drops out of
 * search: its old chunks stay searchable until the ingestion service
 * replaces them and the embedding metadata in one transaction. If every
 * attempt fails, the old chunks and their matching metadata are kept.
 *
 * Every attempt re-runs the whole ingestion, so retries are safe. Rate
 * limits are released and retried like in ProcessDocument.
 */
class ReembedDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use ReplacesUnexpectedFailures;

    /**
     * The number of times the job may be attempted, including rate-limit releases.
     */
    public int $tries = 10;

    /**
     * The number of exceptions, timeouts, and crashes after which the job fails.
     */
    public int $maxExceptions = 3;

    /**
     * Count a worker that died mid-attempt as an exception, so a crash loop cannot use up every try.
     */
    public bool $countCrashesAsExceptions = true;

    /**
     * The number of seconds the job may run. Must stay below the queue's retry_after.
     */
    public int $timeout = 300;

    /**
     * The number of seconds after which the unique lock is released even if the job never finishes.
     */
    public int $uniqueFor = 4 * 3600;

    /**
     * Delete the job if its document was deleted before it ran.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(public Document $document) {}

    /**
     * Get the unique ID of the job, so only one re-embed per document is queued.
     */
    public function uniqueId(): string
    {
        return (string) $this->document->getKey();
    }

    /**
     * Get the number of seconds to wait before retrying after an exception.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    /**
     * Execute the job.
     */
    public function handle(DocumentIngestionService $ingestion): void
    {
        $this->runReplacingUnexpectedFailures(function () use ($ingestion): void {
            $this->document->refresh();

            if ($this->document->status !== DocumentStatus::Completed) {
                return;
            }

            try {
                $ingestion->ingest($this->document);
            } catch (Throwable $exception) {
                if (! EmbeddingService::isRateLimit($exception) || $this->attempts() >= $this->tries) {
                    throw $exception;
                }

                $this->release(ProcessDocument::rateLimitDelay($this->attempts()));
            }
        });
    }

    /**
     * Record the failure without touching the document, which keeps its previous chunks.
     *
     * Only the document ID, exception class, HTTP status, and request ID are
     * logged, never the exception message, which may echo provider output.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Re-embedding a document failed; its previous chunks were kept.', [
            'document_id' => $this->document->getKey(),
            ...($exception !== null ? ProviderFailureLog::context($exception) : []),
        ]);
    }
}
