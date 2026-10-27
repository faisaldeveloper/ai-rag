<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\RetrievedChunkResource;
use App\Services\RAG\Retriever;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SearchController extends Controller
{
    /**
     * Return the knowledge base chunks most similar to the query, best first.
     */
    public function __invoke(SearchRequest $request, Retriever $retriever): AnonymousResourceCollection
    {
        $limit = max(1, min(Retriever::MaxLimit, $request->integer('limit') ?: (int) config('rag.top_k')));
        $minScore = max(0.0, min(1.0, (float) config('rag.min_score')));

        $chunks = $retriever->search($request->user(), $request->validated('query'), $limit, $minScore);

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
