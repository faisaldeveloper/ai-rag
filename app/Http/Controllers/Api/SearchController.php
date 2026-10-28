<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsToProviderFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\RetrievedChunkResource;
use App\Services\RAG\EmbeddingException;
use App\Services\RAG\ProviderFailureLog;
use App\Services\RAG\Retriever;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\AiException;

class SearchController extends Controller
{
    use RespondsToProviderFailures;

    /**
     * The message returned when the embedding provider fails; it never includes the provider's error.
     */
    public const UnavailableMessage = 'Search is temporarily unavailable. Please try again later.';

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

            return $this->providerUnavailable($exception, self::UnavailableMessage);
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
}
