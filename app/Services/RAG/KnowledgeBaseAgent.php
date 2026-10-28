<?php

namespace App\Services\RAG;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * The agent that answers a question from retrieved knowledge base chunks.
 *
 * It has no tools and no memory: every prompt carries its own context. The
 * provider, model, and timeout are passed per prompt by the AnswerGenerator.
 */
class KnowledgeBaseAgent implements Agent
{
    use Promptable;

    /**
     * Create a new knowledge base agent.
     */
    public function __construct(private string $instructions) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * Get the sampling temperature, or null to leave it to the provider.
     *
     * The SDK reads this method in place of the Temperature attribute, so the
     * value can come from configuration.
     */
    public function temperature(): ?float
    {
        $temperature = config('rag.llm_temperature');

        return $temperature === null ? null : (float) $temperature;
    }
}
