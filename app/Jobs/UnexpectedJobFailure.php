<?php

namespace App\Jobs;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Replaces an unexpected exception thrown while processing a document, so
 * nothing the original carried (SQL bindings, file contents, provider text)
 * reaches the queue worker, its log, or failed_jobs.exception.
 *
 * The original is never chained as the previous exception, because
 * failed_jobs stores the whole chain. The job logs the safe details itself,
 * so this exception is not reported again.
 */
class UnexpectedJobFailure extends RuntimeException implements ShouldntReport
{
    /**
     * Create a new exception for the given document.
     */
    public function __construct(public readonly int $documentId)
    {
        parent::__construct(sprintf('Processing document %d failed unexpectedly; safe details were logged.', $documentId));
    }
}
