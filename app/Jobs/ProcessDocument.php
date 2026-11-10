<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Jobs\Concerns\ReplacesUnexpectedFailures;
use App\Models\Document;
use App\Services\RAG\DocumentIngestionService;
use App\Services\RAG\EmbeddingException;
use App\Services\RAG\EmbeddingService;
use App\Services\RAG\IngestionException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Laravel\Ai\Exceptions\AiException;
use LogicException;
use Throwable;

/**
 * Ingests a document: pending -> processing -> completed, or failed after the last attempt.
 *
 * Every attempt re-runs the whole ingestion, which replaces the document's
 * chunks, so retries are safe. While an attempt is retried (after an
 * exception, a rate limit, or a worker crash) the document stays processing;
 * a retried attempt resumes it without a status transition. A first attempt
 * that finds the document already processing leaves it to the job that owns
 * it. Only one job per document is queued at a time.
 *
 * A rate limit releases the job without counting as an exception, so it may
 * wait out a rate-limit storm for up to $tries attempts, while $maxExceptions
 * real errors (exceptions, timeouts, or worker crashes) fail the document.
 */
class ProcessDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use ReplacesUnexpectedFailures;

    /**
     * The delay before retrying the first rate-limited attempt, doubled for each later attempt.
     */
    public const RateLimitDelaySeconds = 60;

    /**
     * The longest delay before retrying a rate-limited attempt.
     */
    public const MaxRateLimitDelaySeconds = 900;

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
     *
     * Longer than the worst case of every attempt timing out plus every rate-limit delay.
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
     * Get the unique ID of the job, so only one job per document is queued.
     */
    public function uniqueId(): string
    {
        return (string) $this->document->getKey();
    }

    /**
     * Get the number of seconds to wait before retrying after an exception.
     *
     * The queue picks the delay by attempt number, which includes rate-limit
     * releases; attempts past the end of the list use its last value.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    /**
     * Execute the job.
     *
     * Unexpected exceptions are replaced so their content never reaches the
     * worker or failed_jobs; retries and backoff count them as before.
     */
    public function handle(DocumentIngestionService $ingestion): void
    {
        $this->runReplacingUnexpectedFailures(function () use ($ingestion): void {
            if (! $this->claim()) {
                return;
            }

            try {
                $ingestion->ingest($this->document);
            } catch (Throwable $exception) {
                if (! EmbeddingService::isRateLimit($exception) || $this->attempts() >= $this->tries) {
                    throw $exception;
                }

                $this->release(self::rateLimitDelay($this->attempts()));

                return;
            }

            $this->document->transitionTo(DocumentStatus::Completed);
        });
    }

    /**
     * Get the number of seconds to wait before retrying the given rate-limited attempt.
     */
    public static function rateLimitDelay(int $attempt): int
    {
        return min(self::MaxRateLimitDelaySeconds, self::RateLimitDelaySeconds * 2 ** max(0, $attempt - 1));
    }

    /**
     * Mark the document failed with a message that is safe to show to administrators.
     */
    public function failed(?Throwable $exception): void
    {
        $document = $this->document->fresh();

        if ($document === null) {
            return;
        }

        try {
            if ($document->status === DocumentStatus::Pending) {
                $document->transitionTo(DocumentStatus::Processing);
            }

            if ($document->status === DocumentStatus::Processing) {
                $document->transitionTo(DocumentStatus::Failed, self::safeErrorMessage($exception));
            }
        } catch (LogicException) {
            // Another process changed the document's status first; leave it as it is.
        }
    }

    /**
     * Get a short error message for the exception that never includes secrets, file contents, or internals.
     */
    public static function safeErrorMessage(?Throwable $exception): string
    {
        return match (true) {
            $exception instanceof IngestionException => $exception->getMessage(),
            EmbeddingService::isRateLimit($exception) => 'The embedding provider rate limit was reached. Retry the document later.',
            $exception instanceof EmbeddingException => 'The embedding provider returned an unexpected response.',
            $exception instanceof AiException => 'The embedding provider request failed. Retry the document later.',
            $exception instanceof MaxAttemptsExceededException => 'Processing took too long or was interrupted too many times.',
            default => 'Processing failed because of an unexpected error.',
        };
    }

    /**
     * Determine whether this attempt may process the document, moving it to processing if it is pending.
     */
    private function claim(): bool
    {
        $this->document->refresh();

        if ($this->document->status === DocumentStatus::Processing) {
            return $this->attempts() > 1;
        }

        if ($this->document->status !== DocumentStatus::Pending) {
            return false;
        }

        try {
            $this->document->transitionTo(DocumentStatus::Processing);
        } catch (LogicException) {
            return false;
        }

        return true;
    }
}
