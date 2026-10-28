<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\RAG\AnswerService;
use App\Services\RAG\KnowledgeBaseAgent;
use App\Services\RAG\PromptBuilder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\TestCase;

class AnswerServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rag.top_k' => 5,
            'rag.min_score' => 0.5,
            'rag.llm_provider' => 'gemini',
            'rag.llm_model' => 'test-answer-model',
            'rag.llm_timeout' => 25,
            'rag.llm_temperature' => 0.1,
        ]);

        $this->user = User::factory()->create();

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(1.0), $prompt->inputs));
    }

    public function test_an_answer_keeps_valid_citations_and_returns_only_the_cited_chunks(): void
    {
        $document = Document::factory()->completed()->create(['title' => 'Refund Policy']);
        $refunds = $this->chunk($document, $this->vector(1.0), 'Refunds are issued within 30 days.', 'Policy > Refunds');
        $this->chunk($document, $this->vector(0.8, 0.6), 'Returns need a receipt.');
        $exchanges = $this->chunk($document, $this->vector(0.6, 0.8), 'Exchanges are free.', null, 7);
        KnowledgeBaseAgent::fake(['Refunds take up to 30 days [S1]. Exchanges cost nothing [S3].']);

        $answer = app(AnswerService::class)->answer($this->user, 'How do refunds work?');

        $this->assertSame('Refunds take up to 30 days [S1]. Exchanges cost nothing [S3].', $answer->text);
        $this->assertFalse($answer->isFallback);
        $this->assertSame([
            ['label' => 'S1', 'document_id' => $document->id, 'document_title' => 'Refund Policy', 'heading' => 'Policy > Refunds', 'page' => null, 'chunk_id' => $refunds->id, 'score' => 1.0],
            ['label' => 'S3', 'document_id' => $document->id, 'document_title' => 'Refund Policy', 'heading' => null, 'page' => 7, 'chunk_id' => $exchanges->id, 'score' => 0.6],
        ], array_map(fn ($citation): array => $citation->toArray(), $answer->citations));
        $this->assertCount(3, $answer->retrievedChunks);
        $this->assertSame('gemini', $answer->provider);
        $this->assertSame('test-answer-model', $answer->model);
    }

    public function test_the_model_is_called_with_the_configured_provider_model_timeout_and_temperature(): void
    {
        $this->chunk(Document::factory()->completed()->create(), $this->vector(1.0), 'Refunds are issued within 30 days.');
        KnowledgeBaseAgent::fake(['Answer [S1].']);

        app(AnswerService::class)->answer($this->user, 'How do refunds work?');

        KnowledgeBaseAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider()->name() === 'gemini'
            && $prompt->model === 'test-answer-model'
            && $prompt->timeout === 25
            && $prompt->agent->instructions() === PromptBuilder::SystemPrompt
            && $prompt->agent->temperature() === 0.1
            && str_contains($prompt->prompt, 'Refunds are issued within 30 days.')
            && str_ends_with($prompt->prompt, 'Question: How do refunds work?'));
    }

    public function test_an_invented_citation_marker_is_dropped(): void
    {
        $chunk = $this->chunk(Document::factory()->completed()->create(), $this->vector(1.0));
        KnowledgeBaseAgent::fake(['Refunds take 30 days [S1]. Prices are fixed [S9]. Both apply [S1, S9][s7].']);

        $answer = app(AnswerService::class)->answer($this->user, 'How do refunds work?');

        $this->assertSame('Refunds take 30 days [S1]. Prices are fixed. Both apply [S1].', $answer->text);
        $this->assertCount(1, $answer->citations);
        $this->assertSame($chunk->id, $answer->citations[0]->chunk->chunkId);
    }

    public function test_an_answer_without_markers_cites_nothing(): void
    {
        $this->chunk(Document::factory()->completed()->create(), $this->vector(1.0));
        KnowledgeBaseAgent::fake(['The knowledge base does not contain enough information to answer this.']);

        $answer = app(AnswerService::class)->answer($this->user, 'What is the CEO salary?');

        $this->assertSame([], $answer->citations);
        $this->assertFalse($answer->isFallback);
    }

    public function test_the_fallback_is_returned_without_calling_the_model_when_no_chunk_reaches_min_score(): void
    {
        config(['rag.min_score' => 0.9]);
        $this->chunk(Document::factory()->completed()->create(), $this->vector(0.6, 0.8));
        KnowledgeBaseAgent::fake();

        $answer = app(AnswerService::class)->answer($this->user, 'What is the CEO salary?');

        $this->assertSame(AnswerService::FallbackAnswer, $answer->text);
        $this->assertTrue($answer->isFallback);
        $this->assertSame([], $answer->citations);
        $this->assertNull($answer->model);
        KnowledgeBaseAgent::assertNeverPrompted();
    }

    public function test_an_empty_model_answer_becomes_the_fallback(): void
    {
        $this->chunk(Document::factory()->completed()->create(), $this->vector(1.0));
        KnowledgeBaseAgent::fake(['  [S9]  ']);

        $answer = app(AnswerService::class)->answer($this->user, 'How do refunds work?');

        $this->assertSame(AnswerService::FallbackAnswer, $answer->text);
        $this->assertSame([], $answer->citations);
    }

    /**
     * Build a vector of the configured dimension whose first components are the given values.
     *
     * @return list<float>
     */
    private function vector(float ...$leading): array
    {
        return array_pad(array_values($leading), config('rag.embedding_dimensions'), 0.0);
    }

    /**
     * Store one chunk of the document.
     *
     * @param  list<float>  $embedding
     */
    private function chunk(Document $document, array $embedding, string $content = 'Chunk text.', ?string $heading = null, ?int $page = null): DocumentChunk
    {
        return DocumentChunk::factory()->for($document)->create([
            'embedding' => $embedding,
            'content' => $content,
            'metadata' => array_filter(['heading' => $heading, 'page' => $page], fn ($value): bool => $value !== null),
        ]);
    }
}
