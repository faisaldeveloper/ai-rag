<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\RAG\RetrievedChunk;
use App\Services\RAG\Retriever;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RetrieverTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rag.embedding_provider' => 'gemini',
            'rag.embedding_model' => 'test-embedding-model',
            'rag.top_k' => 5,
            'rag.min_score' => 0.0,
        ]);

        $this->user = User::factory()->create();

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(1.0), $prompt->inputs));
    }

    public function test_the_most_similar_chunk_is_ranked_first_with_its_document_and_heading(): void
    {
        $document = Document::factory()->completed()->create(['title' => 'Refund Policy']);
        $this->chunk($document, 0, $this->vector(0.0, 1.0), 'Unrelated text.');
        $this->chunk($document, 1, $this->vector(1.0), 'Refunds are issued within 30 days.', 'Policy > Refunds');
        $this->chunk($document, 2, $this->vector(0.6, 0.8), 'Partly related text.');

        $results = app(Retriever::class)->search($this->user, 'How do refunds work?');

        $this->assertSame([1, 2, 0], $results->pluck('chunkIndex')->all());

        $best = $results->first();
        $this->assertInstanceOf(RetrievedChunk::class, $best);
        $this->assertSame($document->id, $best->documentId);
        $this->assertSame('Refund Policy', $best->documentTitle);
        $this->assertSame('Policy > Refunds', $best->heading);
        $this->assertSame('Refunds are issued within 30 days.', $best->content);
        $this->assertSame(DocumentChunk::query()->where('chunk_index', 1)->value('id'), $best->chunkId);
        $this->assertNull($results->last()->heading);
    }

    public function test_scores_are_cosine_similarities_between_zero_and_one(): void
    {
        $document = Document::factory()->completed()->create();
        $this->chunk($document, 0, $this->vector(1.0));
        $this->chunk($document, 1, $this->vector(3.0, 4.0));
        $this->chunk($document, 2, $this->vector(0.0, 1.0));
        $this->chunk($document, 3, $this->vector(-1.0));

        $scores = app(Retriever::class)->search($this->user, 'query')->pluck('score')->all();

        $this->assertCount(3, $scores, 'A chunk pointing away from the query has a negative similarity and is never returned.');
        $this->assertEqualsWithDelta([1.0, 0.6, 0.0], $scores, 1e-6);
        foreach ($scores as $score) {
            $this->assertGreaterThanOrEqual(0.0, $score);
            $this->assertLessThanOrEqual(1.0, $score);
        }
    }

    public function test_the_query_is_embedded_with_the_configured_provider_and_model(): void
    {
        app(Retriever::class)->search($this->user, 'How do refunds work?');

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['How do refunds work?']
            && $prompt->model === 'test-embedding-model'
            && $prompt->provider->name() === 'gemini'
            && $prompt->dimensions === config('rag.embedding_dimensions'));
    }

    public function test_the_limit_defaults_to_top_k_and_is_respected(): void
    {
        config(['rag.top_k' => 3]);
        $this->chunks(Document::factory()->completed()->create(), 6);

        $this->assertCount(3, app(Retriever::class)->search($this->user, 'query'));
        $this->assertCount(2, app(Retriever::class)->search($this->user, 'query', limit: 2));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function outOfRangeLimits(): array
    {
        return [
            'above the cap' => [100, Retriever::MaxLimit],
            'zero' => [0, 1],
            'negative' => [-5, 1],
        ];
    }

    #[DataProvider('outOfRangeLimits')]
    public function test_the_limit_is_clamped_between_one_and_the_cap(int $limit, int $expectedCount): void
    {
        $this->chunks(Document::factory()->completed()->create(), Retriever::MaxLimit + 5);

        $this->assertCount($expectedCount, app(Retriever::class)->search($this->user, 'query', $limit));
    }

    public function test_the_minimum_score_defaults_to_config_and_is_respected(): void
    {
        $document = Document::factory()->completed()->create();
        $this->chunk($document, 0, $this->vector(1.0));
        $this->chunk($document, 1, $this->vector(0.6, 0.8));
        $this->chunk($document, 2, $this->vector(0.0, 1.0));

        $this->assertSame([0, 1], app(Retriever::class)->search($this->user, 'query', minScore: 0.5)->pluck('chunkIndex')->all());

        config(['rag.min_score' => 0.9]);
        $this->assertSame([0], app(Retriever::class)->search($this->user, 'query')->pluck('chunkIndex')->all());
    }

    public function test_chunks_of_documents_that_are_not_completed_are_never_returned(): void
    {
        $completed = Document::factory()->completed()->create();
        $this->chunk($completed, 0, $this->vector(0.6, 0.8));

        foreach ([Document::factory()->create(), Document::factory()->processing()->create(), Document::factory()->failed()->create()] as $hidden) {
            $this->chunk($hidden, 0, $this->vector(1.0));
        }

        $results = app(Retriever::class)->search($this->user, 'query', limit: Retriever::MaxLimit);

        $this->assertSame([$completed->id], $results->pluck('documentId')->all());
    }

    public function test_an_empty_knowledge_base_returns_no_chunks(): void
    {
        Document::factory()->create();

        $this->assertTrue(app(Retriever::class)->search($this->user, 'query')->isEmpty());
    }

    public function test_a_blank_query_returns_no_chunks_without_calling_the_provider(): void
    {
        $this->chunks(Document::factory()->completed()->create(), 1);

        $this->assertTrue(app(Retriever::class)->search($this->user, "  \n ")->isEmpty());

        Embeddings::assertNothingGenerated();
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
    private function chunk(Document $document, int $index, array $embedding, ?string $content = null, ?string $heading = null): DocumentChunk
    {
        return DocumentChunk::factory()->for($document)->create(array_filter([
            'chunk_index' => $index,
            'embedding' => $embedding,
            'content' => $content,
            'metadata' => ['heading' => $heading],
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Store the given number of chunks of the document, all equally similar to the query.
     */
    private function chunks(Document $document, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->chunk($document, $index, $this->vector(1.0));
        }
    }
}
