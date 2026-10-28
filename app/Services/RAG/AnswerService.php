<?php

namespace App\Services\RAG;

use App\Models\User;
use Illuminate\Support\Collection;
use Laravel\Ai\Exceptions\AiException;

/**
 * Answers a question from the knowledge base, with citations.
 *
 * Each question stands alone: no earlier messages are retrieved on or sent.
 * When retrieval finds no chunk above rag.min_score, the fixed fallback is
 * returned and the model is never called.
 */
class AnswerService
{
    /**
     * The answer given when the knowledge base has no sufficient evidence.
     */
    public const FallbackAnswer = 'The knowledge base does not contain enough information to answer this question.';

    /**
     * Matches a citation such as [S1] or [S1, S3], with any whitespace before it.
     */
    private const CitationPattern = '/(\s*)\[\s*(S\d+(?:\s*[,;]\s*S\d+)*)\s*\]/i';

    /**
     * Create a new answer service.
     */
    public function __construct(
        private Retriever $retriever,
        private PromptBuilder $prompts,
        private AnswerGenerator $generator,
    ) {}

    /**
     * Answer the user's question from the chunks they may search.
     *
     * @throws EmbeddingException if the embedding provider returns unexpected vectors.
     * @throws AiException if the embedding or answer provider request fails.
     */
    public function answer(User $user, string $question): Answer
    {
        $startedAt = hrtime(true);

        $chunks = $this->retriever->search($user, $question)->values();

        if ($chunks->isEmpty()) {
            return new Answer(self::FallbackAnswer, [], $chunks, true, null, null, $this->elapsedMs($startedAt));
        }

        $generated = $this->generator->generate(
            $this->prompts->systemPrompt(),
            $this->prompts->userPrompt($question, $chunks),
        );

        [$text, $citations] = $this->resolveCitations($generated['text'], $chunks);

        return new Answer(
            $text === '' ? self::FallbackAnswer : $text,
            $text === '' ? [] : $citations,
            $chunks,
            false,
            $generated['provider'],
            $generated['model'],
            $this->elapsedMs($startedAt),
        );
    }

    /**
     * Remove citation labels that match no supplied source and collect the sources that are cited.
     *
     * @param  Collection<int, RetrievedChunk>  $chunks
     * @return array{0: string, 1: list<Citation>}
     */
    private function resolveCitations(string $text, Collection $chunks): array
    {
        $sources = $chunks->mapWithKeys(fn (RetrievedChunk $chunk, int $index): array => [PromptBuilder::label($index) => $chunk]);
        $cited = [];

        $text = preg_replace_callback(self::CitationPattern, function (array $match) use ($sources, &$cited): string {
            $labels = collect(preg_split('/\s*[,;]\s*/', strtoupper($match[2])))
                ->filter(fn (string $label): bool => $sources->has($label))
                ->unique()
                ->each(function (string $label) use (&$cited): void {
                    $cited[$label] = true;
                });

            return $labels->isEmpty() ? '' : $match[1].$labels->map(fn (string $label): string => "[{$label}]")->implode('');
        }, $text) ?? $text;

        $citations = $sources
            ->filter(fn (RetrievedChunk $chunk, string $label): bool => isset($cited[$label]))
            ->map(fn (RetrievedChunk $chunk, string $label): Citation => new Citation($label, $chunk))
            ->values()
            ->all();

        return [trim($text), $citations];
    }

    /**
     * Get the milliseconds elapsed since the given hrtime.
     */
    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
