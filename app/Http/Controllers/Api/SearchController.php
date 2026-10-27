<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\RetrievedChunkResource;
use App\Services\RAG\EmbeddingException;
use App\Services\RAG\EmbeddingService;
use App\Services\RAG\ProviderFailureLog;
use App\Services\RAG\Retriever;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;
use Throwable;

class SearchController extends Controller
{
    /**
     * The message returned when the embedding provider fails; it never includes the provider's error.
     */
    public const UnavailableMessage = 'Search is temporarily unavailable. Please try again later.';

    /**
     * The Retry-After seconds sent when the provider is rate limiting and gives no usable delay.
     */
    public const DefaultRetryAfterSeconds = 30;

    /**
     * The longest Retry-After sent to clients, whatever the provider asks for.
     */
    public const MaxRetryAfterSeconds = 300;

    /**
     * Return the knowledge base chunks most similar to the query, best first.
     *
     * A provider failure is logged with ProviderFailureLog's context only,
     * never with the provider's message, and answered with a generic 503.
     */
    public function __invoke(SearchRequest $request, Retriever $retriever): AnonymousResourceCollection|JsonResponse
    {
        $limit = max(1, min(Retriever::MaxLimit, $request->integer('limit') ?: (int) config('rag.top_k')));
        $minScore = max(0.0, min(1.0, (float) config('rag.min_score')));

        try {
            $chunks = $retriever->search($request->user(), $request->validated('query'), $limit, $minScore);
        } catch (AiException|EmbeddingException|HttpClientException $exception) {
            Log::warning('Search failed because the embedding provider is unavailable.', ProviderFailureLog::context($exception));

            return $this->providerUnavailable($exception);
        }

        return RetrievedChunkResource::collection($chunks)->additional([
            'meta' => [
                'query' => $request->validated('query'),
                'limit' => $limit,
                'min_score' => $minScore,
                'count' => $chunks->count(),
            ],
        ]);
    }

    /**
     * Build the generic 503 response, with a Retry-After header when the provider is rate limiting.
     */
    private function providerUnavailable(Throwable $exception): JsonResponse
    {
        $headers = EmbeddingService::isRateLimit($exception)
            ? ['Retry-After' => (string) $this->retryAfterSeconds($exception)]
            : [];

        return response()->json(['message' => self::UnavailableMessage], 503, $headers);
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
