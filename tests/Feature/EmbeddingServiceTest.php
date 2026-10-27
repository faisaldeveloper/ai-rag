<?php

namespace Tests\Feature;

use App\Services\RAG\EmbeddingException;
use App\Services\RAG\EmbeddingService;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
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
            'rag.embedding_batch_size' => 32,
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

        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('Expected 2 embeddings but received 1');

        app(EmbeddingService::class)->embed(['first', 'second']);
    }

    public function test_it_rejects_vectors_with_the_wrong_dimension(): void
    {
        Embeddings::fake([[Embeddings::fakeEmbedding(4)]]);

        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('Embedding 0 has 4 dimensions; expected 8');

        app(EmbeddingService::class)->embed(['first']);
    }

    public function test_it_embeds_in_batches_of_the_configured_size_and_keeps_the_input_order(): void
    {
        config(['rag.embedding_batch_size' => 2]);
        $batches = [];
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$batches): array {
            $batches[] = $prompt->inputs;

            return array_map(fn (string $input): array => array_fill(0, 8, (float) substr($input, 5)), $prompt->inputs);
        });

        $embeddings = app(EmbeddingService::class)->embed(['text-0', 'text-1', 'text-2', 'text-3', 'text-4']);

        $this->assertSame([['text-0', 'text-1'], ['text-2', 'text-3'], ['text-4']], $batches);
        $this->assertSame([0.0, 1.0, 2.0, 3.0, 4.0], array_column($embeddings, 0));
    }

    public function test_it_rejects_wrong_dimensions_in_a_later_batch_with_the_overall_index(): void
    {
        config(['rag.embedding_batch_size' => 2]);
        Embeddings::fake([
            [Embeddings::fakeEmbedding(8), Embeddings::fakeEmbedding(8)],
            [Embeddings::fakeEmbedding(8), Embeddings::fakeEmbedding(4)],
        ]);

        $this->expectException(EmbeddingException::class);
        $this->expectExceptionMessage('Embedding 3 has 4 dimensions; expected 8');

        app(EmbeddingService::class)->embed(['a', 'b', 'c', 'd']);
    }

    public function test_embedding_nothing_does_not_call_the_provider(): void
    {
        Embeddings::fake();

        $this->assertSame([], app(EmbeddingService::class)->embed([]));

        Embeddings::assertNothingGenerated();
    }
}
