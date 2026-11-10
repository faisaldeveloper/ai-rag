<?php

namespace App\Jobs\Concerns;

use App\Jobs\UnexpectedJobFailure;
use App\Models\Document;
use App\Services\DatabaseFailureLog;
use App\Services\RAG\IngestionException;
use App\Services\RAG\ProviderFailureLog;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a document job's work so that only expected exceptions leave it.
 *
 * Ingestion exceptions (fixed, app-written messages) and provider failures
 * (reported with ProviderFailureLog's context only) are rethrown unchanged.
 * Anything else is logged with safe details only and replaced with an
 * UnexpectedJobFailure that carries nothing but the document ID.
 *
 * @property Document $document
 */
trait ReplacesUnexpectedFailures
{
    /**
     * Run the work, replacing any unexpected exception it throws.
     *
     * @param  Closure(): void  $work
     */
    protected function runReplacingUnexpectedFailures(Closure $work): void
    {
        $documentId = (int) $this->document->getKey();

        Context::addHidden([
            DatabaseFailureLog::JobKey => static::class,
            DatabaseFailureLog::DocumentIdKey => $documentId,
        ]);

        try {
            $work();
        } catch (Throwable $exception) {
            if ($exception instanceof IngestionException || ProviderFailureLog::isProviderFailure($exception)) {
                throw $exception;
            }

            Log::error('A document job failed unexpectedly.', [
                'job' => static::class,
                'document_id' => $documentId,
                'exception' => $exception::class,
                'code' => DatabaseFailureLog::sqlState($exception) ?? (is_int($exception->getCode()) ? $exception->getCode() : null),
            ]);

            throw new UnexpectedJobFailure($documentId);
        }
    }
}
