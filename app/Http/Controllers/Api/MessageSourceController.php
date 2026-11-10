<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageSourceResource;
use App\Models\Conversation;
use App\Models\DocumentChunk;
use App\Models\Message;
use App\Services\RAG\Retriever;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class MessageSourceController extends Controller
{
    /**
     * Show the chunk an answer cites under the given [S#] label.
     *
     * Only chunks the message actually cites can be fetched, so this cannot be
     * used to read arbitrary chunks. The chunk is loaded through the
     * Retriever's authorized query, so a source whose document was deleted or
     * is no longer searchable is reported as not found.
     */
    public function __invoke(Request $request, Conversation $conversation, Message $message, string $label, Retriever $retriever): MessageSourceResource
    {
        Gate::authorize('view', $conversation);

        $citation = collect($message->metadata['citations'] ?? [])->firstWhere('label', $label);

        abort_if($citation === null, Response::HTTP_NOT_FOUND, 'The answer does not cite this source.');

        /** @var DocumentChunk|null $chunk */
        $chunk = $retriever->chunksVisibleTo($request->user())
            ->where('document_chunks.id', $citation['chunk_id'] ?? null)
            ->first();

        abort_if($chunk === null, Response::HTTP_NOT_FOUND, 'This source is no longer in the knowledge base.');

        return MessageSourceResource::make($chunk)->withLabel($label);
    }
}
