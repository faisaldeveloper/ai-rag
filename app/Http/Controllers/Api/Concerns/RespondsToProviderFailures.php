<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Services\RAG\EmbeddingService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Answers an AI provider failure with a generic 503 that never includes the provider's error.
 */
trait RespondsToProviderFailures
{
    /**
     * The Retry-After seconds sent when the provider is rate limiting and gives no usable delay.
     */
    public const DefaultRetryAfterSeconds = 30;

    /**
     * The longest Retry-After sent to clients, whatever the provider asks for.
     */
    public const MaxRetryAfterSeconds = 300;

    /**
     * Build the generic 503 response, with a Retry-After header when the provider is rate limiting.
     */
    protected function providerUnavailable(Throwable $exception, string $message): JsonResponse
    {
        $headers = EmbeddingService::isRateLimit($exception)
            ? ['Retry-After' => (string) $this->retryAfterSeconds($exception)]
            : [];

        return response()->json(['message' => $message], 503, $headers);
    }

    /**
     * Get the provider's Retry-After delay in seconds, if it sent a usable one, clamped to a sane range.
     */
    private function retryAfterSeconds(Throwable $exception): int
    {
        $requestException = $exception instanceof RequestException ? $exception : $exception->getPrevious();
        $retryAfter = $requestException instanceof RequestException
            ? (string) $requestException->response?->header('Retry-After')
            : '';

        return ctype_digit($retryAfter)
            ? max(1, min(self::MaxRetryAfterSeconds, (int) $retryAfter))
            : self::DefaultRetryAfterSeconds;
    }
}
