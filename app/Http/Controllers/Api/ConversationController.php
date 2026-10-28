<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ConversationController extends Controller
{
    /**
     * List the user's own conversations, most recently active first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ConversationResource::collection(
            $request->user()->conversations()->latest('updated_at')->latest('id')->paginate(15),
        );
    }

    /**
     * Show one of the user's conversations with its messages.
     */
    public function show(Conversation $conversation): ConversationResource
    {
        Gate::authorize('view', $conversation);

        return ConversationResource::make($conversation->load('messages'));
    }
}
