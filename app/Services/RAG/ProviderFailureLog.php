<?php

namespace App\Services\RAG;

use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Exceptions\AiException;
use Throwable;

/**
 * Builds the only details of an embedding provider failure that may be logged.
 *
 * A provider's error message or response body can echo request text (chunks
 * of documents or questions) or credentials, so neither is ever logged: only
 * the exception class, the provider's HTTP status, and the request ID.
 */
class ProviderFailureLog
{
    /**
     * The Context key holding the ID of the request (or command) that started the work.
     */
    public const RequestIdKey = 'request_id';

    /**
     * Determine whether the exception came from the embedding provider or its HTTP client.
     */
    public static function isProviderFailure(Throwable $exception): bool
    {
        return $exception instanceof AiException
            || $exception instanceof EmbeddingException
            || $exception instanceof HttpClientException;
    }

    /**
     * Get the log context for the exception, without its message or response body.
     *
     * @return array{exception: class-string<Throwable>, status: int|null, request_id: string|null}
     */
    public static function context(Throwable $exception): array
    {
        return [
            'exception' => $exception::class,
            'status' => self::status($exception),
            'request_id' => Context::get(self::RequestIdKey),
        ];
    }

    /**
     * Get the provider's HTTP status from the first HTTP response in the exception chain, if any.
     */
    private static function status(Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RequestException && $current->response !== null) {
                return $current->response->status();
            }
        }

        return null;
    }
}
