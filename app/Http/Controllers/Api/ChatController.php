<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsToProviderFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChatRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\RAG\Answer;
use App\Services\RAG\AnswerService;
use App\Services\RAG\EmbeddingException;
use App\Services\RAG\ProviderFailureLog;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;

class ChatController extends Controller
{
    use RespondsToProviderFailures;

    /**
     * The message returned when an AI provider fails; it never includes the provider's error.
     */
    public const UnavailableMessage = 'The assistant is temporarily unavailable. Please try again later.';

    /**
     * Answer the user's question and store it, with the answer, in a conversation.
     *
     * The answer is produced before anything is stored, so a provider failure
     * leaves no conversation, question, or answer behind. It is logged with
     * ProviderFailureLog's context only and answered with a generic 503.
     */
    public function __invoke(ChatRequest $request, AnswerService $answers): JsonResponse
    {
        $question = $request->validated('message');

        try {
            $answer = $answers->answer($request->user(), $question);
        } catch (AiException|EmbeddingException|HttpClientException $exception) {
            Log::warning('Chat failed because an AI provider is unavailable.', ProviderFailureLog::context($exception));

            return $this->providerUnavailable($exception, self::UnavailableMessage);
        }

        [$conversation, $userMessage, $assistantMessage] = DB::transaction(
            fn (): array => $this->store($request->user(), $request->validated('conversation_id'), $question, $answer),
        );

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->id,
                'fallback' => $answer->isFallback,
                'user_message' => MessageResource::make($userMessage),
                'assistant_message' => MessageResource::make($assistantMessage),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Store the question and the answer in the given conversation, or in a new one titled after the question.
     *
     * @return array{0: Conversation, 1: Message, 2: Message}
     */
    private function store(User $user, ?int $conversationId, string $question, Answer $answer): array
    {
        $conversation = $conversationId !== null
            ? $user->conversations()->findOrFail($conversationId)
            : $user->conversations()->create(['title' => Str::limit($question, 80)]);

        $userMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => $question,
        ]);

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $answer->text,
            'metadata' => $answer->metadata(),
        ]);

        return [$conversation, $userMessage, $assistantMessage];
    }
}
