<?php

namespace Tests\Feature;

use App\Services\RAG\EmbeddingService;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use RuntimeException;
use Tests\TestCase;

class EmbeddingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rag.embedding_provider' => 'gemini',
            'rag.embedding_model' => 'test-embedding-model',
            'rag.embedding_dimensions' => 8,
        ]);
    }

    public function test_it_embeds_texts_with_the_configured_provider_model_and_dimensions(): void
    {
        Embeddings::fake();

        $embeddings = app(EmbeddingService::class)->embed(['first', 'second']);

        $this->assertCount(2, $embeddings);
        $this->assertCount(8, $embeddings[0]);

        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['first', 'second']
            && $prompt->dimensions === 8
            && $prompt->model === 'test-embedding-model'
            && $prompt->provider->name() === 'gemini');
    }

    public function test_it_exposes_the_configured_provider_and_model(): void
    {
        $service = app(EmbeddingService::class);

        $this->assertSame('gemini', $service->provider());
        $this->assertSame('test-embedding-model', $service->model());
        $this->assertSame(8, $service->dimensions());
    }

    public function test_it_rejects_a_response_with_the_wrong_number_of_vectors(): void
    {
        Embeddings::fake([[Embeddings::fakeEmbedding(8)]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected 2 embeddings but received 1');

        app(EmbeddingService::class)->embed(['first', 'second']);
    }

    public function test_it_rejects_vectors_with_the_wrong_dimension(): void
    {
        Embeddings::fake([[Embeddings::fakeEmbedding(4)]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embedding 0 has 4 dimensions; expected 8');

        app(EmbeddingService::class)->embed(['first']);
    }
}
