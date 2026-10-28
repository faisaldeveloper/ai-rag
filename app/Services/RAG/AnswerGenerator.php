<?php

namespace App\Services\RAG;

use Laravel\Ai\Exceptions\AiException;

class AnswerGenerator
{
    /**
     * Ask the configured model to answer the prompt under the given system prompt.
     *
     * The provider, model, timeout, and temperature come from config/rag.php.
     *
     * @return array{text: string, provider: string, model: string}
     *
     * @throws AiException if the provider request fails.
     */
    public function generate(string $systemPrompt, string $userPrompt): array
    {
        $response = (new KnowledgeBaseAgent($systemPrompt))->prompt(
            $userPrompt,
            provider: $this->provider(),
            model: $this->model(),
            timeout: $this->timeout(),
        );

        return [
            'text' => $response->text,
            'provider' => $response->meta->provider ?? $this->provider(),
            'model' => $response->meta->model ?? $this->model(),
        ];
    }

    /**
     * Get the configured answer provider name.
     */
    public function provider(): string
    {
        return config('rag.llm_provider');
    }

    /**
     * Get the configured answer model name.
     */
    public function model(): string
    {
        return config('rag.llm_model');
    }

    /**
     * Get the request timeout in seconds.
     */
    public function timeout(): int
    {
        return max(1, (int) config('rag.llm_timeout', 30));
    }
}
