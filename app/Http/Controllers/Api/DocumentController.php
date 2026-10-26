<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\DocumentUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use LogicException;

class DocumentController extends Controller
{
    /**
     * List every document in the shared knowledge base, newest first.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Document::class);

        return DocumentResource::collection(Document::query()->latest('id')->paginate(15));
    }

    /**
     * Upload a document. It stays pending until ingestion picks it up.
     */
    public function store(StoreDocumentRequest $request, DocumentUploadService $uploads): JsonResponse
    {
        $document = $uploads->upload(
            $request->user(),
            $request->file('file'),
            $request->checksum(),
            $request->validated('title'),
        );

        return DocumentResource::make($document)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Show a document.
     */
    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return DocumentResource::make($document);
    }

    /**
     * Delete a document, its stored file, and (by cascade) its chunks.
     */
    public function destroy(Document $document, DocumentUploadService $uploads): Response
    {
        Gate::authorize('delete', $document);

        $uploads->delete($document);

        return response()->noContent();
    }

    /**
     * Move a failed document back to pending so it can be processed again.
     */
    public function retry(Document $document): DocumentResource
    {
        Gate::authorize('retry', $document);

        abort_unless(
            $document->status === DocumentStatus::Failed,
            Response::HTTP_CONFLICT,
            'Only failed documents can be retried.',
        );

        try {
            $document->transitionTo(DocumentStatus::Pending);
        } catch (LogicException) {
            abort(Response::HTTP_CONFLICT, 'The document was changed by another request. Please try again.');
        }

        return DocumentResource::make($document);
    }
}
